<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Bsc\Exceptions\RpcRejectedException;
use App\Services\Bsc\RpcClient;
use App\Services\Bsc\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * What happens to a user's money when a broadcast does not go cleanly.
 *
 * The two failure modes are not symmetrical and must not be handled alike:
 * holding funds until a human looks is recoverable, crediting a transfer that
 * is already on chain is not. Every test here is a version of "which way does
 * this lean", and the answer is always towards not paying twice.
 */
class WithdrawalSafetyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An RpcClient with the network taken out.
     *
     * Extends the real class so the method signatures the service depends on
     * stay honest, but answers from a script instead of an RPC endpoint.
     */
    protected function scriptedNode(
        ?string $rejectWith = null,
        ?string $throwWith = null,
        bool $txUnknownEverywhere = true,
        bool $mined = false,
        bool $inMempool = false,
    ): RpcClient {
        return new class($rejectWith, $throwWith, $txUnknownEverywhere, $mined, $inMempool) extends RpcClient {
            /** @var list<string> */
            public array $rawSent = [];

            public function __construct(
                protected ?string $rejectWith,
                protected ?string $throwWith,
                protected bool $txUnknownEverywhere,
                protected bool $mined,
                protected bool $inMempool,
            ) {
                parent::__construct();
            }

            public function sendRaw(string $rawHex): string
            {
                $this->rawSent[] = $rawHex;

                if ($this->throwWith !== null) {
                    throw new RuntimeException($this->throwWith);
                }

                if ($this->rejectWith !== null) {
                    throw new RpcRejectedException('eth_sendRawTransaction rejected: ' . $this->rejectWith);
                }

                return '0x' . str_repeat('a', 64);
            }

            public function allNodesAgreeUnknown(string $txHash): bool
            {
                return $this->txUnknownEverywhere;
            }

            public function receipt(string $txHash): ?array
            {
                return $this->mined ? ['blockNumber' => '0x1'] : null;
            }

            public function transaction(string $txHash): ?array
            {
                return $this->inMempool ? ['hash' => $txHash] : null;
            }
        };
    }

    /** A debited withdrawal row in the given state. */
    protected function withdrawal(User $user, string $status, array $overrides = []): Withdrawal
    {
        return Withdrawal::create(array_merge([
            'user_id' => $user->id,
            'network' => 'testnet',
            'to_address' => '0x' . str_repeat('b', 40),
            'amount' => '100',
            'fee' => '0.5',
            'net_amount' => '99.5',
            'client_ref' => 'WDR-' . uniqid(),
            'status' => $status,
        ], $overrides));
    }

    protected function walletWith(User $user, string $balance): Wallet
    {
        return Wallet::create([
            'user_id' => $user->id,
            'currency' => 'USDT',
            'available_balance' => $balance,
            'escrow_balance' => '0',
        ]);
    }

    public function test_an_already_known_transaction_counts_as_sent(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');
        $hash = '0x' . str_repeat('c', 64);

        // Already debited, mid-broadcast, with the signed bytes stored.
        $withdrawal = $this->withdrawal($user, Withdrawal::REQUESTED, [
            'tx_hash' => $hash,
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        $service = new WithdrawalService($this->scriptedNode(rejectWith: 'already known'));

        $stats = $service->processPending();

        // The network holding our exact bytes is the outcome we wanted. Treating
        // it as a failure and re-crediting would hand back money already sent.
        $this->assertSame(Withdrawal::SENT, $withdrawal->fresh()->status);
        $this->assertSame($hash, $withdrawal->fresh()->tx_hash);
        $this->assertSame('900.00000000', $wallet->fresh()->available_balance);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(0, $stats['needs_review']);
    }

    public function test_a_nonce_too_low_rejection_never_recredits(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');
        $hash = '0x' . str_repeat('c', 64);

        $withdrawal = $this->withdrawal($user, Withdrawal::REQUESTED, [
            'tx_hash' => $hash,
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        $service = new WithdrawalService($this->scriptedNode(rejectWith: 'nonce too low'));

        $service->processPending();

        // "nonce too low" means something already occupies that nonce — most
        // likely our own earlier attempt, mined while we were asking. This is
        // the exact wording that used to trigger the double payout.
        $this->assertSame(Withdrawal::NEEDS_REVIEW, $withdrawal->fresh()->status);
        $this->assertSame('900.00000000', $wallet->fresh()->available_balance);
        $this->assertSame($hash, $withdrawal->fresh()->tx_hash);
        $this->assertStringContainsString('NOT been re-credited', $withdrawal->fresh()->error);
    }

    public function test_a_replacement_underpriced_rejection_never_recredits(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::REQUESTED, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        (new WithdrawalService($this->scriptedNode(rejectWith: 'replacement transaction underpriced')))
            ->processPending();

        $this->assertSame(Withdrawal::NEEDS_REVIEW, $withdrawal->fresh()->status);
        $this->assertSame('900.00000000', $wallet->fresh()->available_balance);
    }

    public function test_a_definitive_rejection_recredits_once_no_node_has_the_transaction(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');
        $hash = '0x' . str_repeat('c', 64);

        $withdrawal = $this->withdrawal($user, Withdrawal::REQUESTED, [
            'tx_hash' => $hash,
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        $service = new WithdrawalService($this->scriptedNode(
            rejectWith: 'insufficient funds for gas * price + value',
            txUnknownEverywhere: true,
        ));

        $service->processPending();

        // Rejected at validation, and no node anywhere has ever seen the hash:
        // the transfer cannot be on chain, so the debit is reversed.
        $this->assertSame(Withdrawal::FAILED, $withdrawal->fresh()->status);
        $this->assertSame('1000.00000000', $wallet->fresh()->available_balance);
        // The hash stays on the row: clearing it is how a failure and a
        // success became indistinguishable after the fact.
        $this->assertSame($hash, $withdrawal->fresh()->tx_hash);
    }

    public function test_a_definitive_rejection_does_not_recredit_when_a_node_still_has_the_transaction(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::REQUESTED, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        $service = new WithdrawalService($this->scriptedNode(
            rejectWith: 'insufficient funds for gas * price + value',
            txUnknownEverywhere: false,
        ));

        $service->processPending();

        // One node called it invalid; another still holds it. That disagreement
        // is not grounds to hand money back.
        $this->assertSame(Withdrawal::NEEDS_REVIEW, $withdrawal->fresh()->status);
        $this->assertSame('900.00000000', $wallet->fresh()->available_balance);
    }

    public function test_an_unrecognised_rejection_wording_is_treated_as_ambiguous(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::REQUESTED, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        (new WithdrawalService($this->scriptedNode(rejectWith: 'errcode 42: quantum flux')))
            ->processPending();

        // An error we cannot interpret is one we must not reverse money against.
        $this->assertSame(Withdrawal::NEEDS_REVIEW, $withdrawal->fresh()->status);
        $this->assertSame('900.00000000', $wallet->fresh()->available_balance);
    }

    public function test_a_transport_failure_never_recredits(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::REQUESTED, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        (new WithdrawalService($this->scriptedNode(throwWith: 'cURL error 28: timed out')))
            ->processPending();

        // A timeout tells us nothing about whether the bytes landed.
        $this->assertSame(Withdrawal::NEEDS_REVIEW, $withdrawal->fresh()->status);
        $this->assertSame('900.00000000', $wallet->fresh()->available_balance);
    }

    public function test_a_retry_rebroadcasts_the_stored_bytes_and_never_signs_again(): void
    {
        $user = User::factory()->create();
        $this->walletWith($user, '900');

        $storedRaw = '0xf86b018504a817c80082520894';
        $withdrawal = $this->withdrawal($user, Withdrawal::BROADCASTING, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => $storedRaw,
        ]);

        // Age it past the retry window without touching updated_at.
        Withdrawal::query()->whereKey($withdrawal->id)->update(['updated_at' => now()->subMinutes(5)]);

        $node = $this->scriptedNode(txUnknownEverywhere: true);
        (new WithdrawalService($node))->processPending();

        // The whole point: a re-signed transaction carries a new nonce and a new
        // hash, so retrying after the first attempt succeeded would be a second
        // payout. The stored bytes are the only safe thing to send again.
        $this->assertSame([$storedRaw], $node->rawSent);
        $this->assertSame(Withdrawal::SENT, $withdrawal->fresh()->status);
    }

    public function test_a_mined_transaction_is_marked_sent(): void
    {
        $user = User::factory()->create();
        $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::BROADCASTING, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        $node = $this->scriptedNode(mined: true);
        (new WithdrawalService($node))->processPending();

        $this->assertSame(Withdrawal::SENT, $withdrawal->fresh()->status);
        // Nothing was re-sent: it was already in a block.
        $this->assertSame([], $node->rawSent);
    }

    public function test_a_transaction_in_the_mempool_is_left_alone(): void
    {
        $user = User::factory()->create();
        $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::BROADCASTING, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        Withdrawal::query()->whereKey($withdrawal->id)->update(['updated_at' => now()->subMinutes(5)]);

        $node = $this->scriptedNode(inMempool: true);
        (new WithdrawalService($node))->processPending();

        // A later pass will see it mined; re-sending now would be pointless.
        $this->assertSame(Withdrawal::BROADCASTING, $withdrawal->fresh()->status);
        $this->assertSame([], $node->rawSent);
    }

    public function test_a_stale_undeterminable_row_is_escalated_for_review(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::BROADCASTING, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        // Past the escalation window with the nodes still disagreeing.
        Withdrawal::query()->whereKey($withdrawal->id)->update([
            'updated_at' => now()->subSeconds(WithdrawalService::STALE_REVIEW_SECONDS + 60),
        ]);

        (new WithdrawalService($this->scriptedNode(txUnknownEverywhere: false)))->processPending();

        // A row nobody can resolve must not sit in BROADCASTING forever, where
        // no one ever looks at it.
        $this->assertSame(Withdrawal::NEEDS_REVIEW, $withdrawal->fresh()->status);
        $this->assertSame('900.00000000', $wallet->fresh()->available_balance);
    }

    public function test_a_recent_row_is_given_time_before_being_escalated(): void
    {
        $user = User::factory()->create();
        $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::BROADCASTING, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        (new WithdrawalService($this->scriptedNode(txUnknownEverywhere: false)))->processPending();

        // A node blip resolves itself; escalating on the first one would bury
        // the rows that are genuinely stuck.
        $this->assertSame(Withdrawal::BROADCASTING, $withdrawal->fresh()->status);
    }

    public function test_it_does_not_recredit_twice_on_repeated_runs(): void
    {
        $user = User::factory()->create();
        $wallet = $this->walletWith($user, '900');

        $withdrawal = $this->withdrawal($user, Withdrawal::REQUESTED, [
            'tx_hash' => '0x' . str_repeat('c', 64),
            'raw_tx' => '0xf86b018504a817c800',
        ]);

        $service = new WithdrawalService($this->scriptedNode(
            rejectWith: 'insufficient funds for gas * price + value',
            txUnknownEverywhere: true,
        ));

        $service->processPending();
        $this->assertSame('1000.00000000', $wallet->fresh()->available_balance);

        // The row is terminal now, so a later sweep does not pick it up and
        // credit the same withdrawal a second time.
        $service->processPending();
        $this->assertSame('1000.00000000', $wallet->fresh()->available_balance);
        $this->assertSame(Withdrawal::FAILED, $withdrawal->fresh()->status);
    }
}
