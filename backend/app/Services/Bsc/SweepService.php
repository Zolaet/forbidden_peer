<?php

namespace App\Services\Bsc;

use App\Models\CryptoAddress;
use App\Models\Withdrawal;
use App\Services\Bsc\Crypto\EthTxSigner;
use App\Services\Bsc\Crypto\EthereumCrypto;
use App\Services\Bsc\Crypto\Keyring;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/**
 * Optional housekeeping: forward USDT sitting on per-user deposit addresses
 * into the treasury account so one funded account can pay withdrawals.
 *
 * Deposits land on derived user addresses (index >= 1) while withdrawals are
 * signed by the treasury (index 0). Until funds are moved, the treasury needs
 * its own USDT to honour withdrawals — this closes that gap. Each sweep sends
 * the full token balance from one deposit address to the treasury.
 *
 * NOTE: a sweep tx costs BNB gas, paid FROM the deposit address being swept —
 * that address needs a few cents of BNB or the sweep will be rejected
 * ("insufficient funds for gas").
 */
class SweepService
{
    public function run(int $limit = 25): array
    {
        $network = NetworkConfig::network();
        $contract = NetworkConfig::usdtContract();

        if ($contract === '') {
            Log::warning('bsc:sweep skipped — no USDT contract configured for ' . $network);

            return ['swept' => 0, 'empty' => 0, 'skipped' => 0, 'errors' => 0];
        }

        $rpc = new RpcClient();
        $treasury = Keyring::treasuryAddress();
        $addresses = CryptoAddress::where('network', $network)
            ->orderBy('derivation_index')
            ->limit($limit)
            ->get();

        $stats = ['swept' => 0, 'empty' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($addresses as $address) {
            if (EthereumCrypto::normalizeAddress($address->address) === $treasury) {
                continue; // never sweep treasury into itself
            }

            try {
                $balanceData = '0x' . EthTxSigner::balanceOfCalldata($address->address);
                $encoded = $rpc->ethCall($contract, $balanceData);
                $balanceWei = WeiMath::hexToDec($encoded);

                // Anything that floors to zero at our 8-dp ledger precision is
                // dust: there is nothing sendable, so skip the address.
                if (!Money::isPositive(Money::of(WeiMath::fromWeiFloor($balanceWei)))) {
                    $stats['empty']++;

                    continue;
                }

                $ok = $this->sweepOne($rpc, $address, $treasury, $contract, $balanceWei);
                $stats[$ok ? 'swept' : 'errors']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                Log::error('bsc:sweep failed for ' . $address->address . ': ' . $e->getMessage());
            }
        }

        return $stats;
    }

    protected function sweepOne(RpcClient $rpc, CryptoAddress $address, string $treasury, string $contract, string $balanceWei): bool
    {
        $privateKey = Keyring::derivePrivateKey(NetworkConfig::network(), $address->derivation_index);
        $source = EthereumCrypto::addressFromPrivateKey($privateKey);

        // Enforce the source really is the row's address (paranoia check).
        if ($source !== EthereumCrypto::normalizeAddress($address->address)) {
            throw new \RuntimeException('Derivation mismatch for index ' . $address->derivation_index);
        }

        $data = EthTxSigner::transferCalldata($treasury, $balanceWei);
        $raw = EthTxSigner::signTransfer(
            $privateKey,
            $rpc->transactionCount($source, 'pending'),
            $rpc->gasPriceWei(),
            NetworkConfig::transferGasLimit(),
            $contract,
            '0',
            $data
        );

        $rpc->sendRaw($raw);

        return true;
    }
}
