<?php

namespace Tests\Feature;

use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Models\Wallet;
use App\Services\P2p\TradeEscrow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Adjudicating a trade the two parties cannot settle themselves.
 *
 * The gap this closes: once a buyer marks an order paid, the seller could no
 * longer cancel, and nothing auto-released — so one payment proof, real or
 * not, parked the seller's escrow with no way out. Now either party can flag
 * it and the platform owner rules.
 *
 * As in TradeEscrowFlowTest these run on SQLite, where lockForUpdate() is a
 * no-op: they prove the status machine and the arithmetic, not the concurrency.
 */
class DisputeResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** Every trade in these tests is 180 ETB per USDT. */
    private const PRICE = '180.00';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /**
     * An administrator account.
     *
     * Set with forceFill rather than through a factory attribute so the grant
     * of privilege is explicit here. If it ever silently became a no-op, every
     * 200 in this file would turn into a 403 and read as a broken feature
     * rather than a broken fixture.
     */
    protected function adminUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => User::ROLE_ADMIN])->save();

        return $user;
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

    /** @param array<string, mixed> $overrides */
    protected function paymentMethodFor(User $user): PaymentMethod
    {
        return PaymentMethod::create([
            'user_id' => $user->id,
            'type' => 'bank_transfer',
            'account_name' => $user->name,
            'account_number' => '0123456789',
            'bank_or_provider_name' => 'Commercial Bank of Ethiopia',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function offer(User $owner, array $overrides = []): P2pOffer
    {
        return P2pOffer::create(array_merge([
            'user_id' => $owner->id,
            'type' => P2pOffer::TYPE_SELL,
            'fiat_currency' => 'ETB',
            'price' => self::PRICE,
            'total_amount' => '1000',
            'remaining_amount' => '1000',
            'min_limit' => '100',
            'max_limit' => '100000',
            'payment_window_minutes' => 15,
            'status' => P2pOffer::STATUS_ACTIVE,
        ], $overrides));
    }

    protected function openTrade(User $taker, P2pOffer $offer, string $cryptoAmount): P2pTrade
    {
        Sanctum::actingAs($taker);

        $this->postJson('/api/trades', [
            'offer_id' => $offer->id,
            'crypto_amount' => $cryptoAmount,
            'payment_method_id' => $this->paymentMethodFor($taker)->id,
        ])->assertCreated();

        return P2pTrade::where('buyer_id', $taker->id)
            ->orWhere('seller_id', $taker->id)
            ->latest('id')
            ->firstOrFail();
    }

    protected function markPaid(P2pTrade $trade): void
    {
        // postJson would serialise the upload into the JSON body; a file needs
        // the multipart post() with an explicit Accept header.
        $this->post(
            '/api/trades/' . $trade->trade_ref . '/mark-paid',
            ['proof_image' => UploadedFile::fake()->image('proof.jpg')],
            ['Accept' => 'application/json']
        )->assertOk();
    }

    protected function walletOf(User $user): Wallet
    {
        return Wallet::where('user_id', $user->id)->firstOrFail();
    }

    /**
     * A seller, a buyer, and an order on the seller's ad already marked paid.
     *
     * $seller starts with 500 and locks 10, so the resting state is
     * 490 available / 10 escrow, and the buyer is untouched at 500.
     *
     * @param  array<string, mixed>  $offerOverrides
     * @return array{0: User, 1: User, 2: P2pTrade, 3: P2pOffer}
     */
    protected function paidTrade(string $cryptoAmount = '10', array $offerOverrides = []): array
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $this->walletWith($seller, '500');
        $this->walletWith($buyer, '500');

        $offer = $this->offer($seller, $offerOverrides);
        $trade = $this->openTrade($buyer, $offer, $cryptoAmount);

        $this->markPaid($trade);

        return [$seller, $buyer, $trade->fresh(), $offer];
    }

    protected function dispute(User $party, P2pTrade $trade, string $reason = 'Something is wrong.'): void
    {
        Sanctum::actingAs($party);

        $this->postJson('/api/trades/' . $trade->trade_ref . '/dispute', ['reason' => $reason])
            ->assertOk();
    }

    // --- Opening a dispute ---

    public function test_the_buyer_can_flag_a_paid_order_without_moving_money(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        Sanctum::actingAs($buyer);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/dispute', [
            'reason' => 'Sent the money but the seller will not release.',
        ])->assertOk()
            ->assertJsonPath('trade.status', P2pTrade::STATUS_DISPUTED)
            ->assertJsonPath('trade.dispute_reason', 'Sent the money but the seller will not release.')
            ->assertJsonPath('trade.disputed_by', $buyer->id);

        // The whole point: flagging an order does not move a single unit. The
        // USDT sits exactly where it was until an administrator rules.
        $this->assertSame('490.00000000', $this->walletOf($seller)->available_balance);
        $this->assertSame('10.00000000', $this->walletOf($seller)->escrow_balance);
        $this->assertSame('500.00000000', $this->walletOf($buyer)->available_balance);
        $this->assertNotNull($trade->fresh()->disputed_at);
    }

    public function test_the_seller_can_flag_a_paid_order_too(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        // A seller holding a proof they believe is fake needs this exit just
        // as much as a buyer whose release never came.
        $this->dispute($seller, $trade, 'The proof is not a real transfer.');

        $this->assertSame(P2pTrade::STATUS_DISPUTED, $trade->fresh()->status);
        $this->assertSame($seller->id, $trade->fresh()->disputed_by);
        $this->assertSame('10.00000000', $this->walletOf($seller)->escrow_balance);
    }

    public function test_an_unpaid_order_cannot_be_disputed(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $this->walletWith($seller, '500');

        $offer = $this->offer($seller);
        $trade = $this->openTrade($buyer, $offer, '10');

        // Nothing has been claimed yet, so there is nothing to adjudicate —
        // and the seller can already just cancel.
        Sanctum::actingAs($buyer);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/dispute', [
            'reason' => 'Changed my mind.',
        ])->assertStatus(409);

        $this->assertSame(P2pTrade::STATUS_PENDING, $trade->fresh()->status);
    }

    public function test_a_stranger_cannot_flag_someone_elses_order(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/trades/' . $trade->trade_ref . '/dispute', [
            'reason' => 'Not mine to flag.',
        ])->assertNotFound();

        $this->assertSame(P2pTrade::STATUS_PAID, $trade->fresh()->status);
    }

    public function test_a_dispute_needs_a_reason(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        Sanctum::actingAs($buyer);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/dispute', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(P2pTrade::STATUS_PAID, $trade->fresh()->status);
    }

    public function test_an_order_cannot_be_flagged_twice(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        $this->dispute($buyer, $trade);

        // Already claimed by the other side: a second flag adds nothing and
        // must not overwrite the first party's stated reason.
        Sanctum::actingAs($seller);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/dispute', [
            'reason' => 'Me too.',
        ])->assertStatus(409);

        $this->assertSame('Something is wrong.', $trade->fresh()->dispute_reason);
        $this->assertSame($buyer->id, $trade->fresh()->disputed_by);
    }

    // --- The seller's own exit is not closed by a dispute ---

    public function test_the_seller_can_still_release_while_the_order_is_disputed(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        $this->dispute($buyer, $trade);

        // A dispute must not be a way to strand an honest seller. Releasing
        // only ever moves money towards the buyer and only the seller can
        // trigger it, so this path deliberately stays open.
        Sanctum::actingAs($seller);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/release', [
            'confirm_receipt' => true,
        ])->assertOk();

        $this->assertSame(P2pTrade::STATUS_COMPLETED, $trade->fresh()->status);
        $this->assertSame('490.00000000', $this->walletOf($seller)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($seller)->escrow_balance);
        $this->assertSame('510.00000000', $this->walletOf($buyer)->available_balance);
    }

    public function test_the_seller_cannot_cancel_a_disputed_order(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        $this->dispute($buyer, $trade);

        // Cancelling here would take the escrow back out from under the
        // adjudication, so the answer names the actual next step.
        Sanctum::actingAs($seller);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/cancel')
            ->assertStatus(409)
            ->assertJsonPath('error', 'This order is in dispute — only an administrator can close it now.');

        $this->assertSame('10.00000000', $this->walletOf($seller)->escrow_balance);
    }

    // --- Administering the queue ---

    public function test_a_regular_user_cannot_reach_the_admin_routes(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        Sanctum::actingAs($buyer);

        $this->getJson('/api/admin/trades')->assertStatus(403);

        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [
            'outcome' => 'release',
            'note' => 'Paying myself.',
        ])->assertStatus(403);

        $this->getJson('/api/admin/trades/' . $trade->trade_ref)->assertStatus(403);

        // And nothing moved.
        $this->assertSame(P2pTrade::STATUS_PAID, $trade->fresh()->status);
        $this->assertSame('10.00000000', $this->walletOf($seller)->escrow_balance);
    }

    public function test_the_queue_lists_disputed_orders_oldest_first(): void
    {
        [$sellerA, $buyerA, $tradeA] = $this->paidTrade();
        [$sellerB, $buyerB, $tradeB] = $this->paidTrade();

        $this->dispute($buyerA, $tradeA);
        $this->dispute($buyerB, $tradeB);

        Sanctum::actingAs($this->adminUser());

        $response = $this->getJson('/api/admin/trades')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        // A queue, not a feed: the party whose money has been stuck longest is
        // the one whose case is read first.
        $this->assertSame($tradeA->trade_ref, $response->json('trades.0.trade_ref'));
        $this->assertSame($tradeB->trade_ref, $response->json('trades.1.trade_ref'));
    }

    public function test_the_queue_excludes_orders_nobody_disputed(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        Sanctum::actingAs($this->adminUser());

        // A paid order waiting on the seller normally is not the owner's
        // problem yet, so it does not clutter the queue.
        $this->assertSame(0, $this->getJson('/api/admin/trades')->assertOk()->json('meta.total'));

        // Any status is reachable explicitly, for auditing.
        $this->assertSame(
            1,
            $this->getJson('/api/admin/trades?status=paid')->assertOk()->json('meta.total')
        );
    }

    public function test_the_admin_view_shows_the_proof_but_not_emails_or_bank_details(): void
    {
        $seller = User::factory()->create(['email' => 'seller@example.com']);
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);
        $this->walletWith($seller, '500');
        $this->walletWith($buyer, '500');

        $offer = $this->offer($seller);
        $trade = $this->openTrade($buyer, $offer, '10');
        $this->markPaid($trade);
        $this->dispute($buyer, $trade);

        Sanctum::actingAs($this->adminUser());
        $response = $this->getJson('/api/admin/trades/' . $trade->trade_ref)->assertOk();

        // The proof is the entire basis for ruling, so it must be visible.
        $this->assertCount(1, $response->json('trade.proofs'));

        // Staff are not a party, so the same redaction that protects a
        // counterparty protects them: the channel, not the credentials.
        $response->assertJsonPath('trade.payment_method.type', 'bank_transfer');
        $this->assertArrayNotHasKey('account_number', $response->json('trade.payment_method'));
        $this->assertArrayNotHasKey('account_name', $response->json('trade.payment_method'));
        $this->assertNull($response->json('trade.role'));

        $body = $response->getContent();
        $this->assertStringNotContainsString('seller@example.com', $body);
        $this->assertStringNotContainsString('buyer@example.com', $body);
    }

    // --- Rulings ---

    public function test_an_admin_release_pays_the_buyer_and_does_not_reopen_the_ad(): void
    {
        // The ad is taken in full, so a capacity restore would be visible.
        [$seller, $buyer, $trade, $offer] = $this->paidTrade('10', [
            'total_amount' => '10',
            'remaining_amount' => '10',
        ]);

        $this->assertSame(P2pOffer::STATUS_COMPLETED, $offer->fresh()->status);

        $this->dispute($buyer, $trade);

        $admin = $this->adminUser();
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [
            'outcome' => 'release',
            'note' => 'Bank statement confirms the 1,800 ETB transfer arrived.',
        ])->assertOk()
            ->assertJsonPath('trade.status', P2pTrade::STATUS_COMPLETED)
            ->assertJsonPath('trade.resolution_note', 'Bank statement confirms the 1,800 ETB transfer arrived.');

        $this->assertSame('510.00000000', $this->walletOf($buyer)->available_balance);
        $this->assertSame('490.00000000', $this->walletOf($seller)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($seller)->escrow_balance);

        // This trade genuinely happened, so its capacity is spent and the ad
        // stays closed.
        $this->assertSame('0.00000000', $offer->fresh()->remaining_amount);
        $this->assertSame(P2pOffer::STATUS_COMPLETED, $offer->fresh()->status);

        $this->assertNotNull($trade->fresh()->resolved_at);
        $this->assertSame($admin->id, $trade->fresh()->resolved_by);
    }

    public function test_an_admin_refund_returns_the_escrow_and_reopens_the_ad(): void
    {
        [$seller, $buyer, $trade, $offer] = $this->paidTrade('10', [
            'total_amount' => '10',
            'remaining_amount' => '10',
        ]);

        $this->assertSame(P2pOffer::STATUS_COMPLETED, $offer->fresh()->status);

        $this->dispute($buyer, $trade);

        Sanctum::actingAs($this->adminUser());
        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [
            'outcome' => 'refund',
            'note' => 'No matching transfer on the statement.',
        ])->assertOk()
            ->assertJsonPath('trade.status', P2pTrade::STATUS_REFUNDED);

        // The escrow is back in the seller's spendable balance — 490 that never
        // left plus the 10 that came home — and the buyer is untouched.
        $this->assertSame('500.00000000', $this->walletOf($seller)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($seller)->escrow_balance);
        $this->assertSame('500.00000000', $this->walletOf($buyer)->available_balance);

        // The order is void, exactly as if it had been cancelled, so the ad can
        // be taken again.
        $this->assertSame('10.00000000', $offer->fresh()->remaining_amount);
        $this->assertSame(P2pOffer::STATUS_ACTIVE, $offer->fresh()->status);
    }

    public function test_resolving_twice_does_not_move_the_money_twice(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [
            'outcome' => 'refund',
            'note' => 'No transfer found.',
        ])->assertOk();

        $this->assertSame('500.00000000', $this->walletOf($seller)->available_balance);

        // A second ruling on the same order, this time the other way. The
        // status guard inside TradeEscrow refuses it, so the escrow cannot be
        // paid out twice by a double-submit.
        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [
            'outcome' => 'release',
            'note' => 'Changed my mind.',
        ])->assertStatus(409);

        $this->assertSame('500.00000000', $this->walletOf($seller)->available_balance);
        $this->assertSame('500.00000000', $this->walletOf($buyer)->available_balance);
        $this->assertSame(P2pTrade::STATUS_REFUNDED, $trade->fresh()->status);
    }

    public function test_an_admin_cannot_resolve_an_unpaid_order(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $this->walletWith($seller, '500');

        $offer = $this->offer($seller);
        $trade = $this->openTrade($buyer, $offer, '10');

        Sanctum::actingAs($this->adminUser());
        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [
            'outcome' => 'refund',
            'note' => 'Nothing to decide yet.',
        ])->assertStatus(409);

        // Nothing is owed on an unpaid order, and the seller can still cancel.
        $this->assertSame(P2pTrade::STATUS_PENDING, $trade->fresh()->status);
        $this->assertSame('10.00000000', $this->walletOf($seller)->escrow_balance);
    }

    public function test_an_admin_cannot_reopen_a_completed_order(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        Sanctum::actingAs($seller);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/release', [
            'confirm_receipt' => true,
        ])->assertOk();

        // The buyer has the USDT and may already have withdrawn it, so
        // reversing means debiting a wallet that can be empty. A dispute
        // turning into an overdraft would be worse than the dispute.
        Sanctum::actingAs($this->adminUser());
        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [
            'outcome' => 'refund',
            'note' => 'Second thoughts.',
        ])->assertStatus(409);

        $this->assertSame(P2pTrade::STATUS_COMPLETED, $trade->fresh()->status);
        $this->assertSame('510.00000000', $this->walletOf($buyer)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($seller)->escrow_balance);
    }

    public function test_a_ruling_needs_both_an_outcome_and_a_reason(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();
        $this->dispute($buyer, $trade);

        Sanctum::actingAs($this->adminUser());

        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outcome', 'note']);

        // There is no third outcome: the USDT is in escrow and has to end up
        // with one of the two parties.
        $this->postJson('/api/admin/trades/' . $trade->trade_ref . '/resolve', [
            'outcome' => 'burn',
            'note' => 'To the void.',
        ])->assertStatus(422)->assertJsonValidationErrors('outcome');

        $this->assertSame(P2pTrade::STATUS_DISPUTED, $trade->fresh()->status);
        $this->assertSame('10.00000000', $this->walletOf($seller)->escrow_balance);
    }

    // --- Interaction with the expiry sweep ---

    public function test_the_expiry_sweep_leaves_disputed_orders_alone(): void
    {
        [$seller, $buyer, $trade] = $this->paidTrade();

        $this->dispute($buyer, $trade);

        // Push it well past its window. Only PENDING rows are swept, so a
        // disputed order must not be cancelled and refunded out from under the
        // adjudication.
        P2pTrade::query()->update(['expires_at' => now()->subMinute()]);

        $stats = app(TradeEscrow::class)->cancelExpired();

        $this->assertSame(0, $stats['cancelled']);
        $this->assertSame(P2pTrade::STATUS_DISPUTED, $trade->fresh()->status);
        $this->assertSame('10.00000000', $this->walletOf($seller)->escrow_balance);
    }
}
