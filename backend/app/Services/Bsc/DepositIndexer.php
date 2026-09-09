<?php

namespace App\Services\Bsc;

use App\Models\ChainScanState;
use App\Models\CryptoAddress;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One scan pass over the chain for inbound USDT (BEP-20) transfers landing on
 * any of our per-user deposit addresses.
 *
 * Run every minute (see routes/console.php). Idempotent: new transfers are
 * keyed by tx_hash (unique), confirmations advance on each pass, and a wallet
 * is credited exactly once when a deposit crosses the confirmation threshold.
 */
class DepositIndexer
{
    public function run(): array
    {
        $network = NetworkConfig::network();
        $contract = NetworkConfig::usdtContract();

        if ($contract === '') {
            Log::warning('bsc:scan-deposits skipped — no USDT contract configured for ' . $network
                . ' (set BSC_' . strtoupper($network) . '_USDT_CONTRACT in .env).');

            return ['scanned' => 0, 'new' => 0, 'confirmed' => 0];
        }

        $rpc = new RpcClient();
        $state = ChainScanState::firstOrCreate(['network' => $network], ['last_block' => 0]);

        $tip = $rpc->blockNumber();
        $from = $state->last_block + 1;
        if ($state->last_block === 0) {
            // Cold start: pick up recent history so an address minted earlier
            // isn't permanently missed.
            $from = max(1, $tip - NetworkConfig::scanLookback());
        }
        if ($from > $tip) {
            return ['scanned' => 0, 'new' => 0, 'confirmed' => 0];
        }

        $stats = ['scanned' => 0, 'new' => 0, 'confirmed' => 0];

        // address (lower-cased) => CryptoAddress
        $addressMap = [];
        CryptoAddress::where('network', $network)->get(['address', 'user_id'])->each(function ($ca) use (&$addressMap) {
            $addressMap[$ca->address] = $ca;
        });

        // Nothing to watch yet — fast-forward the resume point.
        if ($addressMap === []) {
            $state->update(['last_block' => $tip]);

            return $stats;
        }

        $addressTopics = array_values(array_map(
            fn ($address) => '0x' . WeiMath::padHex(substr($address, 2), 32),
            array_keys($addressMap)
        ));

        // Walk the range in bounded chunks (public RPCs cap eth_getLogs span).
        $chunk = 2000;
        $advancedTo = $from - 1;
        $abort = false;

        for ($start = $from; $start <= $tip && !$abort; $start += $chunk) {
            $end = min($tip, $start + $chunk - 1);

            try {
                $logs = $rpc->getLogs([
                    'fromBlock' => '0x' . WeiMath::decToHex((string) $start),
                    'toBlock' => '0x' . WeiMath::decToHex((string) $end),
                    'address' => $contract,
                    'topics' => [TokenLogDecoder::TRANSFER_TOPIC, null, $addressTopics],
                ]);
            } catch (\Throwable $e) {
                Log::error('bsc:scan-deposits getLogs failed for blocks ' . $start . '-' . $end
                    . ': ' . $e->getMessage());

                break;
            }

            // Blocks ascending so a failure never strands later blocks behind it.
            usort($logs, fn ($a, $b) => (int) WeiMath::hexToDec((string) ($a['blockNumber'] ?? '0x0'))
                <=> (int) WeiMath::hexToDec((string) ($b['blockNumber'] ?? '0x0')));

            foreach ($logs as $log) {
                $block = (int) WeiMath::hexToDec((string) ($log['blockNumber'] ?? '0x0'));

                try {
                    if ($this->ingestLog($log, $network, $addressMap)) {
                        $stats['new']++;
                    }
                    $advancedTo = max($advancedTo, $block);
                } catch (\Throwable $e) {
                    Log::error('bsc:scan-deposits ingest failed at block ' . $block
                        . ' (' . ($log['transactionHash'] ?? '?') . '): ' . $e->getMessage());

                    // Don't fast-forward past the failing block; a future run
                    // re-visits it (idempotent) and retries the record.
                    $abort = true;

                    break;
                }
            }
        }

        if ($advancedTo >= $from) {
            $state->update(['last_block' => $advancedTo]);
            $stats['scanned'] = $advancedTo - $from + 1;
        }

        $stats['confirmed'] = $this->advanceConfirmations($rpc, $network, $tip);

        return $stats;
    }

    /**
     * Record one Transfer log as a deposit. Returns true when it was newly
     * inserted, false when it was already known (idempotent re-scan).
     */
    protected function ingestLog(array $log, string $network, array $addressMap): bool
    {
        $decoded = TokenLogDecoder::decode($log);

        if ($decoded['amount'] <= 0 || $decoded['tx_hash'] === '') {
            return false;
        }

        $cryptoAddress = $addressMap[$decoded['to']] ?? null;
        if (!$cryptoAddress) {
            return false; // to some address we don't track (topic filter is best-effort)
        }

        $created = Deposit::firstOrCreate(
            ['tx_hash' => $decoded['tx_hash']],
            [
                'user_id' => $cryptoAddress->user_id,
                'network' => $network,
                'deposit_address' => $decoded['to'],
                'from_address' => $decoded['from'],
                'block_number' => $decoded['block_number'],
                'amount' => $decoded['amount'],
                'value_raw' => $decoded['value_raw'],
                'confirmations' => 0,
                'status' => Deposit::PENDING,
                'detected_at' => now(),
            ]
        );

        return $created->wasRecentlyCreated;
    }

    /**
     * Refresh confirmations for every pending deposit; credit the wallet the
     * moment one crosses the configured threshold. Safe to run repeatedly.
     */
    protected function advanceConfirmations(RpcClient $rpc, string $network, int $tip): int
    {
        $minConfirmations = NetworkConfig::minConfirmations();
        $confirmed = 0;

        Deposit::where('network', $network)
            ->where('status', Deposit::PENDING)
            ->whereNotNull('block_number')
            ->orderBy('id')
            ->chunk(200, function ($deposits) use ($tip, $minConfirmations, &$confirmed) {
                foreach ($deposits as $deposit) {
                    $confs = $tip - $deposit->block_number + 1;
                    if ($confs < 1) {
                        continue; // reorged behind tip; wait
                    }

                    if ($confs >= $minConfirmations) {
                        if ($this->confirm($deposit)) {
                            $confirmed++;
                        }
                        continue;
                    }

                    if ((int) $deposit->confirmations !== $confs) {
                        $deposit->update(['confirmations' => $confs]);
                    }
                }
            });

        return $confirmed;
    }

    /**
     * Credit the user's wallet exactly once, guarded by a row lock so two
     * overlapping scans can't double-credit.
     */
    protected function confirm(Deposit $deposit): bool
    {
        return DB::transaction(function () use ($deposit) {
            /** @var Deposit|null $locked */
            $locked = Deposit::whereKey($deposit->id)->lockForUpdate()->first();
            if (!$locked || $locked->status === Deposit::CONFIRMED) {
                return false;
            }

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $locked->user_id, 'currency' => 'USDT'],
                ['available_balance' => 0, 'escrow_balance' => 0]
            );

            // Wallet::credit opens a nested transaction (savepoint) that locks
            // the wallet row and writes the audit ledger entry.
            $wallet->credit((float) $locked->amount, WalletTransaction::TYPE_DEPOSIT, $locked);

            $locked->update([
                'status' => Deposit::CONFIRMED,
                'confirmations' => NetworkConfig::minConfirmations(),
                'credited_at' => now(),
            ]);

            return true;
        });
    }
}
