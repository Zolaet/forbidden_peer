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
use App\Support\Money;
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
 * - The user is re-credited for a broadcast failure ONLY when the transaction
 *   provably does not exist: the node rejected it as invalid *and* every
 *   configured node agrees it has never seen the hash. Anything less certain is
 *   parked in `needs_review` with the user still debited, because the two
 *   mistakes are not symmetrical — holding a user's funds until a human looks
 *   is recoverable, paying them twice out of the treasury is not.
 * - A retry re-broadcasts the *stored* raw_tx rather than signing again. A
 *   re-signed transaction carries a new nonce and a new hash, so a "retry"
 *   against a tx that actually succeeded becomes a genuine second payout.
 */
class WithdrawalService
{
    /** The node confirmed nothing was accepted — safe to reverse the debit. */
    public const OUTCOME_DEFINITIVE = 'definitive';

    /** These exact bytes are already in a mempool or a block — that is success. */
    public const OUTCOME_ALREADY_SENT = 'already_sent';

    /** We cannot tell. Never re-credit; a human decides. */
    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    /**
     * How long a broadcast may stay unresolved before it is parked for review.
     * Long enough that an RPC outage or a slow block resolves itself; short
     * enough that a genuinely stuck row reaches a human the same day.
     */
    public const STALE_REVIEW_SECONDS = 1800;

    /** Minimum age before a mempool-less transaction is considered retryable. */
    public const RETRY_AFTER_SECONDS = 60;

    /**
     * Injected so tests can drive the failure paths with a scripted node. The
     * decision of whether to hand money back turns entirely on what the node
     * said, and that decision is not something to leave unverified.
     */
    public function __construct(protected ?RpcClient $rpc = null)
    {
    }

    protected function rpc(): RpcClient
    {
        return $this->rpc ??= app(RpcClient::class);
    }

    /**
     * Validate, debit, create and immediately broadcast a withdrawal.
     * Returns the Withdrawal row; inspect ->status (sent | failed | needs_review).
     *
     * @throws \DomainException            validation / below minimum
     * @throws \Illuminate\Database\QueryException on duplicate client_ref (retry)
     */
    public function createAndBroadcast(User $user, string $toAddress, string $amount): Withdrawal
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

        // Money, not floats: `$amount` arrives as a decimal string and the fee
        // and net have to add up to it exactly, which float arithmetic on the
        // way into a decimal(18,8) column does not guarantee.
        $amount = Money::of($amount);
        $fee = Money::of(NetworkConfig::withdrawalFeeExact());
        $net = Money::sub($amount, $fee);

        if (!Money::isPositive($amount)) {
            throw new \DomainException('Enter an amount greater than zero.');
        }
        if (Money::gt($fee, Money::zero()) && !Money::isPositive($net)) {
            throw new \DomainException('Amount must be greater than the withdrawal fee.');
        }
        if (Money::lt($amount, NetworkConfig::withdrawalMinExact())) {
            throw new \DomainException(
                'Amount is below the minimum of ' . NetworkConfig::withdrawalMinExact() . ' USDT.'
            );
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
     *   - REQUESTED    (crash between debit & broadcast) → broadcast now.
     *   - BROADCASTING → if mined, mark sent; if the nodes agree it is gone and
     *                    the attempt is stale, re-broadcast the stored bytes.
     */
    public function processPending(int $limit = 25): array
    {
        $counts = ['requested' => 0, 'sent' => 0, 'failed' => 0, 'needs_review' => 0, 'still_pending' => 0];

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

            $counts[match ($withdrawal->fresh()->status) {
                Withdrawal::SENT => 'sent',
                Withdrawal::FAILED => 'failed',
                Withdrawal::NEEDS_REVIEW => 'needs_review',
                default => 'still_pending',
            }]++;
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
            $this->handleRejection($withdrawal, $e);
        } catch (\Throwable $e) {
            // Transport/ambiguous. The outcome is genuinely unknown, so the
            // debit stands and the hash is kept for an operator to check.
            $this->markNeedsReview($withdrawal, $e->getMessage());
        }
    }

    /** Resume a BROADCASTING row: mine-check, else re-broadcast when stale. */
    protected function resumeBroadcasting(Withdrawal $withdrawal): void
    {
        $hash = (string) $withdrawal->tx_hash;
        $rpc = $this->rpc();

        // Ask before deciding. A node we cannot reach is not evidence that the
        // transaction is gone, so a transport failure here means "try again
        // next run" rather than any change of state.
        $mined = false;
        $inMempool = false;
        $reachable = true;

        if ($hash !== '') {
            try {
                $mined = $rpc->receipt($hash) !== null;
                $inMempool = !$mined && $rpc->transaction($hash) !== null;
            } catch (\Throwable $e) {
                $reachable = false;
            }
        }

        if ($mined) {
            $this->markSent($withdrawal);

            return;
        }

        if (!$reachable) {
            $this->escalateIfStale($withdrawal, 'The BSC RPC nodes could not be reached.');

            return;
        }

        if ($inMempool) {
            // Waiting to be mined. A later pass will see it in a block.
            return;
        }

        // Neither mined nor in a mempool as far as one node can see — but one
        // node's silence is not the network's. Only a unanimous "unknown
        // everywhere" justifies sending again, and because send() re-broadcasts
        // the stored raw_tx, doing so is idempotent rather than a second payout.
        if ($hash !== '' && !$rpc->allNodesAgreeUnknown($hash)) {
            $this->escalateIfStale($withdrawal, 'Some RPC nodes could not confirm the transaction is gone.');

            return;
        }

        if ($withdrawal->updated_at
            && $withdrawal->updated_at->diffInSeconds(now()) < self::RETRY_AFTER_SECONDS) {
            return;
        }

        try {
            $this->send($withdrawal);
        } catch (RpcRejectedException $e) {
            $this->handleRejection($withdrawal, $e);
        } catch (\Throwable $e) {
            $this->markNeedsReview($withdrawal, $e->getMessage());
        }
    }

    /**
     * Sign + submit. Persists the signed bytes and their hash *before* sending
     * so a crash mid-call is resumable, then marks SENT on success.
     */
    protected function send(Withdrawal $withdrawal): void
    {
        $rpc = $this->rpc();

        // Re-broadcast the same bytes when we already signed them.
        //
        // A fresh signature picks the current pending nonce, and that nonce has
        // moved on precisely when the first attempt succeeded. The result is a
        // different nonce *and* a different hash, which makes it a new transfer
        // rather than a retry. Reusing raw_tx is what makes a retry safe: the
        // node either accepts bytes it has already seen (a no-op) or says so.
        $raw = (string) $withdrawal->raw_tx;

        if ($raw === '') {
            $nonce = $rpc->transactionCount(Keyring::treasuryAddress(), 'pending');

            $data = EthTxSigner::transferCalldata(
                $withdrawal->to_address,
                // Net amount straight from the decimal(18,8) column. Round
                //-tripping it through a float changed what we actually send.
                WeiMath::toWei((string) $withdrawal->net_amount)
            );

            $raw = EthTxSigner::signTransfer(
                Keyring::treasuryPrivateKey(),
                $nonce,
                $rpc->gasPriceWei(),
                NetworkConfig::transferGasLimit(),
                NetworkConfig::usdtContract(),
                '0', // value (BNB) — we're calling the token contract
                $data
            );

            // keccak(rlp(signed tx)) — computable offline, so we always know
            // which hash to go looking for, even if no node ever answers.
            $withdrawal->update([
                'raw_tx' => $raw,
                'nonce' => $nonce,
                'tx_hash' => '0x' . EthereumCrypto::keccakHex(hex2bin(substr($raw, 2))),
                'status' => Withdrawal::BROADCASTING,
                'error' => null,
            ]);
        } elseif ((string) $withdrawal->status === Withdrawal::REQUESTED) {
            $withdrawal->update(['status' => Withdrawal::BROADCASTING]);
        }

        $confirmedHash = $rpc->sendRaw($raw);

        $this->markSent($withdrawal, null, $confirmedHash);
    }

    /**
     * A JSON-RPC rejection, sorted by what it proves about the transaction.
     *
     * `sendRaw` stops at the first node that answers, so a rejection is one
     * node's opinion. Even a "definitive" rejection is therefore checked
     * against every other node before any money moves back.
     */
    protected function handleRejection(Withdrawal $withdrawal, RpcRejectedException $e): void
    {
        $message = $e->getMessage();

        switch ($this->classifyRejection($message)) {
            case self::OUTCOME_ALREADY_SENT:
                // The network is holding these exact bytes. That is the outcome
                // we wanted, not a failure.
                $this->markSent($withdrawal, 'Network reported the transaction as already known.');

                return;

            case self::OUTCOME_DEFINITIVE:
                if ($this->txIsUnknownEverywhere($withdrawal)) {
                    $this->failAndRecredit($withdrawal, 'Broadcast rejected by the network: ' . $message);

                    return;
                }

                $this->markNeedsReview(
                    $withdrawal,
                    'A node rejected the broadcast (' . $message . ') but others may still hold the transaction.'
                );

                return;

            default:
                $this->markNeedsReview($withdrawal, $message);
        }
    }

    /**
     * What does this rejection prove?
     *
     * The dangerous middle is anything that means "a transaction on this nonce
     * already exists" — that is often our *own* earlier attempt having been
     * mined while we were asking, so treating it as a failure and re-crediting
     * pays the user twice. Only errors that mean the transaction failed
     * validation before entering any mempool are safe to reverse.
     */
    protected function classifyRejection(string $message): string
    {
        $m = strtolower($message);

        foreach (['already known', 'known transaction', 'already imported', 'alreadyexists', 'already exists'] as $needle) {
            if (str_contains($m, $needle)) {
                return self::OUTCOME_ALREADY_SENT;
            }
        }

        // A transaction already occupies this nonce. Usually ours, already
        // mined. Never reversible.
        foreach ([
            'nonce too low',
            'nonce is too low',
            'nonce has already been used',
            'replacement transaction underpriced',
            'transaction underpriced',
            'replacement underpriced',
        ] as $needle) {
            if (str_contains($m, $needle)) {
                return self::OUTCOME_AMBIGUOUS;
            }
        }

        // Rejected at validation: no mempool took it, so nothing can be mined.
        foreach ([
            'insufficient funds',
            'exceeds block gas limit',
            'intrinsic gas too low',
            'gas required exceeds allowance',
            'invalid sender',
            'invalid chain id',
            'invalid signature',
            'negative value',
            'exceeds transaction cost',
            'oversized data',
            'txpool is full',
        ] as $needle) {
            if (str_contains($m, $needle)) {
                return self::OUTCOME_DEFINITIVE;
            }
        }

        // Wording we do not recognise. An uninterpretable rejection is one we
        // must not reverse money against.
        return self::OUTCOME_AMBIGUOUS;
    }

    /**
     * Did no node ever see this transaction?
     *
     * The last check before re-crediting. A withdrawal with no recorded hash
     * never reached a node, so it is trivially absent; anything else has to be
     * unanimously unknown across the configured RPC endpoints.
     */
    protected function txIsUnknownEverywhere(Withdrawal $withdrawal): bool
    {
        $hash = (string) $withdrawal->tx_hash;

        if ($hash === '') {
            return true;
        }

        try {
            return $this->rpc()->allNodesAgreeUnknown($hash);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Park a row for a human, but only once waiting has stopped being useful.
     * An unreachable node resolves itself; flagging every transient blip would
     * bury the rows that are genuinely stuck.
     */
    protected function escalateIfStale(Withdrawal $withdrawal, string $reason): void
    {
        if ($withdrawal->updated_at
            && $withdrawal->updated_at->diffInSeconds(now()) >= self::STALE_REVIEW_SECONDS) {
            $this->markNeedsReview($withdrawal, $reason);
        }
    }

    /** Terminal success. Keeps whichever hash we trust most. */
    protected function markSent(Withdrawal $withdrawal, ?string $note = null, ?string $confirmedHash = null): void
    {
        DB::transaction(function () use ($withdrawal, $note, $confirmedHash) {
            /** @var Withdrawal|null $locked */
            $locked = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();

            if (!$locked || $locked->status === Withdrawal::SENT) {
                return;
            }

            $locked->update([
                'status' => Withdrawal::SENT,
                'tx_hash' => ($confirmedHash !== null && $confirmedHash !== '')
                    ? $confirmedHash
                    : $locked->tx_hash,
                'broadcast_at' => now(),
                'error' => $note,
            ]);
        });
    }

    /**
     * Definitive failure → atomically put the debited funds back.
     *
     * Only ever reached when no node has seen the transaction, so there is
     * nothing on chain to conflict with the reversal.
     */
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

            $wallet->credit((string) $locked->amount, WalletTransaction::TYPE_ADMIN, $locked);

            // The hash is deliberately kept. Clearing it was how a failure and
            // a success became indistinguishable after the fact.
            $locked->update(['status' => Withdrawal::FAILED, 'error' => $error]);
        });

        Log::warning('Withdrawal #' . $withdrawal->id . ' failed and was re-credited: ' . $error);
    }

    /**
     * Outcome unknown — the user keeps the debit, and an operator gets a row
     * that says so. Deliberately not FAILED: a FAILED row has been re-credited,
     * and re-crediting this one would pay a transfer that may already be mined.
     */
    protected function markNeedsReview(Withdrawal $withdrawal, string $error): void
    {
        $hash = (string) $withdrawal->tx_hash;
        $explorer = NetworkConfig::explorerTx();

        $note = 'Broadcast outcome unknown: ' . $error
            . ' The user has NOT been re-credited — the funds may already be on chain.'
            . ($hash !== '' ? ' Check ' . $explorer . $hash . ' before deciding.' : ' No transaction hash was recorded.');

        DB::transaction(function () use ($withdrawal, $note) {
            /** @var Withdrawal|null $locked */
            $locked = Withdrawal::whereKey($withdrawal->id)->lockForUpdate()->first();
            if (!$locked || $locked->status === Withdrawal::SENT) {
                return;
            }

            $locked->update(['status' => Withdrawal::NEEDS_REVIEW, 'error' => $note]);
        });

        Log::error('Withdrawal #' . $withdrawal->id . ' needs review (not re-credited): ' . $error);
    }
}
