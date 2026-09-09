<?php

namespace App\Services\Bsc;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\Withdrawal;
use App\Services\Bsc\Crypto\EthTxSigner;
use App\Services\Bsc\Crypto\EthereumCrypto;
use App\Services\Bsc\Crypto\Keyring;
use App\Services\Bsc\Exceptions\RpcRejectedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Exchange-style USDT (BEP-20) withdrawals.
 *
 * 1. Validates & debits the user's internal available balance, creating a
 *    `withdrawals` row — all in one DB transaction.
 * 2. Auto-broadcasts a signed USDT transfer from the treasury account
 *    (platform holds the keys) to the user-supplied external address.
 *
 * Accounting safety rules baked in:
 * - The ledger debit happens BEFORE any broadcast; nothing ever leaves without
 *   first being deducted from the user's available balance.
 * - A *definitive* node rejection (e.g. "insufficient funds for gas") means the
 *   tx was NOT accepted → the user is re-credited atomically.
 * - An *ambiguous* transport failure means the tx MAY have been accepted → the
 *   user is NOT re-credited; the computed tx hash is kept so it can be checked
 *   on the explorer and reconciled manually.
 */
class WithdrawalService
{
    /**
     * Validate, debit, create and immediately broadcast a withdrawal.
     * Returns the Withdrawal row; inspect ->status (sent | failed).
     *
     * @throws \DomainException            validation / below minimum / failed with re-credit
     * @throws \Illuminate\Database\QueryException on duplicate client_ref (retry)
     */
    public function createAndBroadcast(User $user, string $toAddress, float $amount): Withdrawal
    {
        $toAddress = EthereumCrypto::normalizeAddress(trim($toAddress));
        if (!EthereumCrypto::isAddress($toAddress)) {
            throw new \DomainException('Please enter a valid BSC (BEP-20) address.');
        }

        $network = NetworkConfig::network();
        if (NetworkConfig::usdtContract() === '') {
            throw new \DomainException('Withdrawals are not configured yet — no USDT contract is set for ' . $network
                . '. Contact the operator.');
        }

        $fee = NetworkConfig::withdrawalFee();
        $net = round($amount - $fee, 8);

        if ($amount < NetworkConfig::withdrawalMin()) {
            throw new \DomainException('Amount is below the minimum of ' . NetworkConfig::withdrawalMin() . ' USDT.');
        }
        if ($net <= 0) {
            throw new \DomainException('Amount must be greater than the withdrawal fee.');
        }

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => 'USDT'],
            ['available_balance' => 0, 'escrow_balance' => 0]
        );

        // Atomic: create the row AND debit the wallet. Wallet::debit() row-locks
        // the wallet and throws when the available balance is insufficient.
        $withdrawal = DB::transaction(function () use ($user, $network, $toAddress, $amount, $fee, $net, $wallet) {
            $row = Withdrawal::create([
                'user_id' => $user->id,
                'network' => $network,
                'to_address' => $toAddress,
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $net,
                'client_ref' => 'WDR-' . (string) Str::uuid(),
                'status' => Withdrawal::REQUESTED,
            ]);

            $wallet->debit($amount, WalletTransaction::TYPE_WITHDRAW, $row);

            return $row;
        });

        // Broadcast AFTER commit so a crash never debits without a row to resume.
        $this->broadcast($withdrawal);

        return $withdrawal->refresh();
    }

    /**
     * Scheduler entry point: finish withdrawals a previous run left mid-flight.
     *   - REQUESTED   (crash between debit & broadcast) → broadcast now.
     *   - BROADCASTING→ if mined, mark sent; if gone from the mempool and the
     *                   attempt is stale, re-broadcast with a fresh nonce.
     */
    public function processPending(int $limit = 25): array
    {
        $counts = ['requested' => 0, 'sent' => 0, 'failed' => 0, 'still_pending' => 0];

        $pending = Withdrawal::whereIn('status', [Withdrawal::REQUESTED, Withdrawal::BROADCASTING])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($pending as $withdrawal) {
            if ($withdrawal->status === Withdrawal::REQUESTED) {
                $counts['requested']++;
                $this->broadcast($withdrawal);
            } else {
                $this->resumeBroadcasting($withdrawal);
            }

            $counts[$withdrawal->fresh()->status === Withdrawal::SENT ? 'sent'
                : ($withdrawal->fresh()->status === Withdrawal::FAILED ? 'failed' : 'still_pending')]++;
        }

        return $counts;
    }

    /** Broadcast a newly created REQUESTED withdrawal. */
    public function broadcast(Withdrawal $withdrawal): void
    {
        if ((string) $withdrawal->status !== Withdrawal::REQUESTED) {
            return;
        }

        try {
            $this->send($withdrawal);
        } catch (RpcRejectedException $e) {
            $this->failAndRecredit($withdrawal, 'Broadcast rejected by the network: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Transport/ambiguous. Keep the computed hash so an operator can
            // check the explorer before anything else happens.
            $this->markAmbiguous($withdrawal, $e->getMessage());
        }
    }

    /** Resume a BROADCASTING row: mine-check, else re-broadcast when stale. */
    protected function resumeBroadcasting(Withdrawal $withdrawal): void
    {
        $hash = (string) $withdrawal->tx_hash;
        $rpc = new RpcClient();

        try {
            if ($hash !== '' && $rpc->receipt($hash) !== null) {
                $withdrawal->update(['status' => Withdrawal::SENT, 'broadcast_at' => now()]);

                return;
            }

            // In some node's mempool → leave it; a later pass mines it.
            if ($hash !== '' && $rpc->transaction($hash) !== null) {
                return;
            }

            // Nowhere to be found and the last attempt is old enough → retry.
            if ($withdrawal->updated_at && $withdrawal->updated_at->diffInSeconds(now()) < 60) {
                return;
            }

            $this->send($withdrawal);
        } catch (RpcRejectedException $e) {
            $this->failAndRecredit($withdrawal, 'Broadcast rejected by the network: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->markAmbiguous($withdrawal, $e->getMessage());
        }
    }

    /**
     * Sign + submit. Persists BROADCASTING (with the computed hash) *before*
     * sending so a crash mid-call is resumable, then marks SENT on success.
     */
    protected function send(Withdrawal $withdrawal): void
    {
        $rpc = new RpcClient();
        $privateKey = Keyring::treasuryPrivateKey();
        $treasury = Keyring::treasuryAddress();

        $nonce = $rpc->transactionCount($treasury, 'pending');
        $gasPrice = $rpc->gasPriceWei();
        $contract = NetworkConfig::usdtContract();

        $data = EthTxSigner::transferCalldata(
            $withdrawal->to_address,
            WeiMath::toWei(sprintf('%.8F', (float) $withdrawal->net_amount))
        );

        $raw = EthTxSigner::signTransfer(
            $privateKey,
            $nonce,
            $gasPrice,
            NetworkConfig::transferGasLimit(),
            $contract,
            '0', // value (BNB) — we're calling the token contract
            $data
        );

        // The tx hash is keccak(rlp(signed tx)) — computable offline, so we can
        // always point at "what would have been sent".
        $localHash = '0x' . EthereumCrypto::keccakHex(hex2bin(substr($raw, 2)));

        $withdrawal->update([
            'status' => Withdrawal::BROADCASTING,
            'tx_hash' => $localHash,
            'error' => null,
        ]);

        $confirmedHash = $rpc->sendRaw($raw);

        $withdrawal->update([
            'status' => Withdrawal::SENT,
            'tx_hash' => $confirmedHash !== '' ? $confirmedHash : $localHash,
            'broadcast_at' => now(),
            'error' => null,
        ]);
    }

    /** Definitive failure → atomically put the debited funds back. */
    protected function failAndRecredit(Withdrawal $withdrawal, string $error): void
    {
        DB::transaction(function () use ($withdrawal, $error) {
            /** @var Withdrawal|null $locked */
            $locked = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();
            if (!$locked || $locked->status === Withdrawal::SENT) {
                return;
            }

            $wallet = Wallet::firstOrCreate(
                ['user_id' => $locked->user_id, 'currency' => 'USDT'],
                ['available_balance' => 0, 'escrow_balance' => 0]
            );

            $wallet->credit((float) $locked->amount, WalletTransaction::TYPE_ADMIN, $locked);

            $locked->update(['status' => Withdrawal::FAILED, 'error' => $error, 'tx_hash' => null]);
        });

        Log::warning('Withdrawal #' . $withdrawal->id . ' failed and was re-credited: ' . $error);
    }

    /** Ambiguous failure — do NOT re-credit; keep the hash for verification. */
    protected function markAmbiguous(Withdrawal $withdrawal, string $error): void
    {
        $withdrawal->update([
            'status' => Withdrawal::FAILED,
            'error' => 'Broadcast outcome unknown: ' . $error
                . '. Check tx ' . ($withdrawal->tx_hash ?? '') . ' on the explorer before retrying.',
        ]);

        Log::error('Withdrawal #' . $withdrawal->id . ' left ambiguous (not re-credited): ' . $error);
    }
}
