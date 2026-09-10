<?php

namespace App\Services\Bsc;

use App\Services\Bsc\Exceptions\RpcRejectedException;
use App\Services\Bsc\Exceptions\RpcTransportException;
use GuzzleHttp\Client;
use RuntimeException;

/**
 * Minimal BSC JSON-RPC client over Guzzle (already installed) with automatic
 * failover across the configured RPC endpoints.
 *
 * Reads tolerate node failures (rotate to the next node). `sendRaw` is
 * special: a node that ANSWERS with a JSON-RPC error is a definitive
 * rejection (RpcRejectedException); only silent transport failures rotate and
 * ultimately raise RpcTransportException (ambiguous outcome).
 */
class RpcClient
{
    protected array $urls;
    protected Client $http;
    protected int $id = 0;

    public function __construct(?array $urls = null)
    {
        $this->urls = $urls ?? NetworkConfig::rpcUrls();
        $this->http = new Client(['timeout' => 20, 'http_errors' => false]);
    }

    /** Read call: rotate nodes until one answers; all-fail → transport error. */
    public function call(string $method, array $params = []): mixed
    {
        $lastTransport = null;
        foreach ($this->urls as $url) {
            try {
                [$status, $body] = $this->post($url, $method, $params);

                // Non-2xx from the gateway/node — rotate for reads.
                if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
                    throw new RuntimeException('HTTP ' . $status);
                }
                if (isset($body['error'])) {
                    // A node answered but refused — for reads try the next node.
                    throw new RuntimeException('RPC error: ' . ($body['error']['message'] ?? 'unknown'));
                }

                return $body['result'] ?? null;
            } catch (\Throwable $e) {
                $lastTransport = $e;
            }
        }

        throw new RpcTransportException(
            'All BSC RPC nodes failed' . ($lastTransport ? ': ' . $lastTransport->getMessage() : '.')
        );
    }

    /**
     * Broadcast a signed transaction. Node answers (HTTP 4xx or JSON-RPC error)
     * mean "rejected" and stop immediately; only transport problems rotate.
     */
    public function sendRaw(string $rawHex): string
    {
        $lastTransport = null;
        foreach ($this->urls as $url) {
            try {
                [$status, $body] = $this->post($url, 'eth_sendRawTransaction', [$rawHex]);

                // Rate-limited / overloaded → transient, try the next node.
                if ($status === 429 || $status >= 500) {
                    throw new RuntimeException('HTTP ' . $status);
                }
                if ($status >= 400) {
                    throw new RpcRejectedException('eth_sendRawTransaction HTTP ' . $status);
                }
                if (isset($body['error'])) {
                    throw new RpcRejectedException(
                        'eth_sendRawTransaction rejected: ' . ($body['error']['message'] ?? 'unknown')
                    );
                }

                return (string) ($body['result'] ?? '');
            } catch (RpcRejectedException $rejected) {
                // Definitive: the tx was not accepted. Do not try other nodes.
                throw $rejected;
            } catch (\Throwable $transport) {
                $lastTransport = $transport;
            }
        }

        throw new RpcTransportException(
            'eth_sendRawTransaction outcome unknown — no node reachable' .
            ($lastTransport ? ' (last: ' . $lastTransport->getMessage() . ')' : '') .
            '. Verify manually before re-crediting.'
        );
    }

    /**
     * True only when EVERY configured node reports the tx as unknown, in both
     * the block tree and the mempool.
     *
     * call() returns the first node that answers, which is the right trade for
     * a read whose "yes" is trustworthy. This asks the opposite question —
     * whether a transaction is *absent* — and a single lagging node, or one
     * behind a load balancer, answers null for a tx another node has already
     * mined. Acting on that answer means re-signing and broadcasting a second
     * transfer, so the negative needs unanimity. A node we cannot reach is not
     * a vote: unreachable means "unknown", which is not good enough to resend.
     */
    public function allNodesAgreeUnknown(string $txHash): bool
    {
        if ($txHash === '' || $this->urls === []) {
            return false;
        }

        foreach ($this->urls as $url) {
            foreach (['eth_getTransactionReceipt', 'eth_getTransactionByHash'] as $method) {
                try {
                    [$status, $body] = $this->post($url, $method, [$txHash]);
                } catch (\Throwable $e) {
                    return false;
                }

                if ($status >= 400 || isset($body['error'])) {
                    return false;
                }

                // A missing `result` key is a malformed answer, and a non-null
                // one means this node has the transaction. Either way: not
                // unknown, so do not resend.
                if (!array_key_exists('result', $body) || $body['result'] !== null) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function post(string $url, string $method, array $params): array
    {
        $response = $this->http->post($url, [
            'json' => [
                'jsonrpc' => '2.0',
                'id' => ++$this->id,
                'method' => $method,
                'params' => $params,
            ],
        ]);

        return [
            $response->getStatusCode(),
            json_decode((string) $response->getBody(), true) ?? [],
        ];
    }

    public function blockNumber(): int
    {
        return (int) WeiMath::hexToDec((string) $this->call('eth_blockNumber'));
    }

    /** Null when the tx has no receipt yet (unmined or unknown). */
    public function receipt(string $txHash): ?array
    {
        $res = $this->call('eth_getTransactionReceipt', [$txHash]);
        return is_array($res) ? $res : null;
    }

    /** Null when the tx is neither mined nor in any node's mempool. */
    public function transaction(string $txHash): ?array
    {
        $res = $this->call('eth_getTransactionByHash', [$txHash]);
        return is_array($res) ? $res : null;
    }

    public function getLogs(array $filter): array
    {
        $res = $this->call('eth_getLogs', [$filter]);
        return is_array($res) ? $res : [];
    }

    public function transactionCount(string $address, string $block = 'pending'): int
    {
        return (int) WeiMath::hexToDec((string) $this->call('eth_getTransactionCount', [$address, $block]));
    }

    public function gasPriceWei(): string
    {
        // Returned as hex; hand back a decimal string for our signer.
        return WeiMath::hexToDec((string) $this->call('eth_gasPrice'));
    }

    /** eth_call — e.g. balanceOf(address) on the token contract. */
    public function ethCall(string $to, string $data, string $block = 'latest'): string
    {
        return (string) $this->call('eth_call', [['to' => $to, 'data' => $data], $block]);
    }

    public function balanceWei(string $address): string
    {
        return WeiMath::hexToDec((string) $this->call('eth_getBalance', [$address, 'latest']));
    }

    /** Decimal string of the current chain tip, minus `depth`. */
    public function safeBlock(int $depth = 0): int
    {
        $block = $this->blockNumber();
        return max(0, $block - max(0, $depth));
    }
}
