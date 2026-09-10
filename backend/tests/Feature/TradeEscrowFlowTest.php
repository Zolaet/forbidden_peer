<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\User;
use App\Models\Wallet;
use App\Services\P2p\TradeEscrow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The escrow engine, from the money's point of view.
 *
 * Note these run on SQLite, where lockForUpdate() is a no-op — they prove the
 * arithmetic and the state machine, not the concurrency. The row-locking that
 * stops two simultaneous trades double-spending one balance is a MySQL
 * property and has to be reasoned about rather than tested here.
 */
class TradeEscrowFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Every trade in these tests is 180 ETB per USDT unless stated otherwise. */
    private const PRICE = '180.00';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
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

    /** Open a trade as $taker and return it. */
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

    public function test_a_sell_ad_escrows_the_offer_owners_balance_and_the_taker_is_the_buyer(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        $offer = $this->offer($owner);
        $trade = $this->openTrade($taker, $offer, '10');

        // The owner is selling, so their USDT is the USDT at risk.
        $this->assertSame('490.00000000', $this->walletOf($owner)->available_balance);
        $this->assertSame('10.00000000', $this->walletOf($owner)->escrow_balance);

        // The taker is buying — nothing of theirs is locked.
        $this->assertSame('500.00000000', $this->walletOf($taker)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($taker)->escrow_balance);

        $this->assertSame($taker->id, $trade->buyer_id);
        $this->assertSame($owner->id, $trade->seller_id);
        $this->assertSame('1800.00', $trade->fiat_amount);
    }

    public function test_a_buy_ad_escrows_the_takers_balance_and_the_offer_owner_is_the_buyer(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        // A 'buy' ad is a crypto buyer advertising, so the taker is the seller.
        $offer = $this->offer($owner, ['type' => P2pOffer::TYPE_BUY]);
        $trade = $this->openTrade($taker, $offer, '10');

        // This is the case that used to lock the wrong wallet: the taker's
        // USDT is what backs the order, not the advertiser's.
        $this->assertSame('490.00000000', $this->walletOf($taker)->available_balance);
        $this->assertSame('10.00000000', $this->walletOf($taker)->escrow_balance);

        $this->assertSame('500.00000000', $this->walletOf($owner)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($owner)->escrow_balance);

        $this->assertSame($owner->id, $trade->buyer_id);
        $this->assertSame($taker->id, $trade->seller_id);
    }

    public function test_it_refuses_a_taker_with_no_balance_without_touching_the_ad(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '5');

        $offer = $this->offer($owner, ['type' => P2pOffer::TYPE_BUY]);

        Sanctum::actingAs($taker);
        $this->postJson('/api/trades', [
            'offer_id' => $offer->id,
            'crypto_amount' => '10',
            'payment_method_id' => $this->paymentMethodFor($taker)->id,
        ])->assertStatus(400);

        $this->assertSame(0, P2pTrade::count());
        // A failed attempt must not consume the ad's capacity.
        $this->assertSame('1000.00000000', $offer->fresh()->remaining_amount);
        $this->assertSame('5.00000000', $this->walletOf($taker)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($taker)->escrow_balance);
    }

    public function test_it_enforces_the_ads_fiat_limits_server_side(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $method = $this->paymentMethodFor($taker);

        // At 180 ETB per USDT: 1 USDT is 180 ETB, 100 USDT is 18,000 ETB.
        $offer = $this->offer($owner, ['min_limit' => '1000', 'max_limit' => '5000']);

        Sanctum::actingAs($taker);

        $this->postJson('/api/trades', [
            'offer_id' => $offer->id,
            'crypto_amount' => '1',
            'payment_method_id' => $method->id,
        ])->assertStatus(400);

        $this->postJson('/api/trades', [
            'offer_id' => $offer->id,
            'crypto_amount' => '100',
            'payment_method_id' => $method->id,
        ])->assertStatus(400);

        $this->assertSame(0, P2pTrade::count());

        // 10 USDT is 1,800 ETB — inside the range.
        $this->postJson('/api/trades', [
            'offer_id' => $offer->id,
            'crypto_amount' => '10',
            'payment_method_id' => $method->id,
        ])->assertCreated();

        $this->assertSame('1800.00', P2pTrade::firstOrFail()->fiat_amount);
    }

    public function test_it_refuses_a_payment_method_belonging_to_someone_else(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $stranger = User::factory()->create();
        $this->walletWith($owner, '500');

        $offer = $this->offer($owner);

        Sanctum::actingAs($taker);
        $this->postJson('/api/trades', [
            'offer_id' => $offer->id,
            'crypto_amount' => '10',
            'payment_method_id' => $this->paymentMethodFor($stranger)->id,
        ])->assertStatus(422);

        $this->assertSame(0, P2pTrade::count());
    }

    public function test_it_refuses_a_trade_on_your_own_ad(): void
    {
        $owner = User::factory()->create();
        $this->walletWith($owner, '500');

        $offer = $this->offer($owner);

        Sanctum::actingAs($owner);
        $this->postJson('/api/trades', [
            'offer_id' => $offer->id,
            'crypto_amount' => '10',
            'payment_method_id' => $this->paymentMethodFor($owner)->id,
        ])->assertStatus(400);

        $this->assertSame(0, P2pTrade::count());
    }

    public function test_the_response_does_not_leak_emails_or_the_counterpartys_bank_details(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $taker = User::factory()->create(['email' => 'taker@example.com']);
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        $offer = $this->offer($owner);
        $trade = $this->openTrade($taker, $offer, '10');

        // The seller sees only the buyer's identity, and the payment channel
        // without the account credentials — the buyer owns those.
        Sanctum::actingAs($owner);
        $response = $this->postJson('/api/trades/' . $trade->trade_ref . '/cancel');

        $response->assertOk();
        $this->assertSame(['id', 'name'], array_keys($response->json('trade.seller')));
        $this->assertSame(['id', 'name'], array_keys($response->json('trade.buyer')));
        $this->assertSame('bank_transfer', $response->json('trade.payment_method.type'));
        $this->assertArrayNotHasKey('account_number', $response->json('trade.payment_method'));
        $this->assertArrayNotHasKey('account_name', $response->json('trade.payment_method'));
    }

    public function test_the_buyer_sees_their_own_bank_details_and_no_emails_appear(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $taker = User::factory()->create(['email' => 'taker@example.com']);
        $this->walletWith($owner, '500');

        $offer = $this->offer($owner);
        $trade = $this->openTrade($taker, $offer, '10');

        $this->post(
            '/api/trades/' . $trade->trade_ref . '/mark-paid',
            ['proof_image' => UploadedFile::fake()->image('proof.jpg')],
            ['Accept' => 'application/json']
        )->assertOk()
            // The owner of the method is the one person who should get the
            // full details back.
            ->assertJsonPath('trade.payment_method.account_number', '0123456789')
            ->assertJsonPath('trade.seller.name', $owner->name);

        $response = $this->get('/api/user', ['Accept' => 'application/json']);
        $this->assertStringNotContainsString('owner@example.com', $response->getContent());

        Sanctum::actingAs($taker);
        $asBuyer = $this->postJson('/api/trades/' . $trade->trade_ref . '/cancel');
        // Only the seller may cancel; the buyer gets a 404, and that 404 body
        // must not carry the other side's address either.
        $asBuyer->assertNotFound();
        $this->assertStringNotContainsString('owner@example.com', $asBuyer->getContent());
    }

    public function test_the_seller_releasing_escrow_credits_the_buyer(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        $offer = $this->offer($owner);
        $trade = $this->openTrade($taker, $offer, '10');

        $this->markPaid($trade);
        $this->assertSame(P2pTrade::STATUS_PAID, $trade->fresh()->status);

        Sanctum::actingAs($owner);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/release', [
            'confirm_receipt' => true,
        ])->assertOk();

        // Escrow drains and the buyer gains it. The seller's *available* is
        // untouched at 490: they locked 10 of their 500, and releasing hands
        // that 10 to the buyer rather than giving it back. The two wallets
        // together still hold exactly the 1000 they started with.
        $this->assertSame('490.00000000', $this->walletOf($owner)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($owner)->escrow_balance);
        $this->assertSame('510.00000000', $this->walletOf($taker)->available_balance);
        $this->assertSame(P2pTrade::STATUS_COMPLETED, $trade->fresh()->status);
    }

    public function test_the_buyer_cannot_mark_paid_after_the_window_closes(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');

        $offer = $this->offer($owner);
        $trade = $this->openTrade($taker, $offer, '10');

        $trade->update(['expires_at' => now()->subMinute()]);

        $this->post(
            '/api/trades/' . $trade->trade_ref . '/mark-paid',
            ['proof_image' => UploadedFile::fake()->image('proof.jpg')],
            ['Accept' => 'application/json']
        )->assertStatus(409);

        $this->assertSame(P2pTrade::STATUS_PENDING, $trade->fresh()->status);
    }

    public function test_the_seller_can_cancel_an_unpaid_order_and_gets_the_escrow_back(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');

        // An ad taken in full, so completing it must be undone by the cancel.
        $offer = $this->offer($owner, ['total_amount' => '10', 'remaining_amount' => '10']);
        $trade = $this->openTrade($taker, $offer, '10');

        $this->assertSame(P2pOffer::STATUS_COMPLETED, $offer->fresh()->status);

        Sanctum::actingAs($owner);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/cancel')->assertOk();

        $this->assertSame('500.00000000', $this->walletOf($owner)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($owner)->escrow_balance);
        $this->assertSame(P2pTrade::STATUS_CANCELLED, $trade->fresh()->status);
        $this->assertSame('10.00000000', $offer->fresh()->remaining_amount);
        $this->assertSame(P2pOffer::STATUS_ACTIVE, $offer->fresh()->status);
    }

    public function test_the_seller_cannot_cancel_once_the_buyer_has_paid(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');

        $offer = $this->offer($owner);
        $trade = $this->openTrade($taker, $offer, '10');

        $this->markPaid($trade);

        // Cancelling here would reclaim the escrow from a buyer who has
        // already sent the fiat.
        Sanctum::actingAs($owner);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/cancel')->assertStatus(409);

        $this->assertSame(P2pTrade::STATUS_PAID, $trade->fresh()->status);
        $this->assertSame('10.00000000', $this->walletOf($owner)->escrow_balance);
    }

    public function test_the_expiry_sweep_refunds_unpaid_orders_and_leaves_paid_ones_alone(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $paidBuyer = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($paidBuyer, '500');

        $offer = $this->offer($owner);

        $abandoned = $this->openTrade($taker, $offer, '10');
        $paid = $this->openTrade($paidBuyer, $offer, '6');

        $this->markPaid($paid);

        // Push both past their window: 16 USDT is locked, 10 of it abandoned.
        P2pTrade::query()->update(['expires_at' => now()->subMinute()]);
        $this->assertSame('16.00000000', $this->walletOf($owner)->escrow_balance);

        $stats = app(TradeEscrow::class)->cancelExpired();

        $this->assertSame(1, $stats['cancelled']);
        $this->assertSame(0, $stats['errored']);
        // The paid order is left alone: its money belongs to the buyer now.
        $this->assertSame(P2pTrade::STATUS_CANCELLED, $abandoned->fresh()->status);
        $this->assertSame(P2pTrade::STATUS_PAID, $paid->fresh()->status);
        $this->assertSame(TradeEscrow::REASON_EXPIRED, $abandoned->fresh()->cancel_reason);
        $this->assertNotNull($abandoned->fresh()->cancelled_at);

        // 10 came back; the 6 backing the paid order is still held.
        $this->assertSame('494.00000000', $this->walletOf($owner)->available_balance);
        $this->assertSame('6.00000000', $this->walletOf($owner)->escrow_balance);
    }

    public function test_the_sweep_is_idempotent(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');

        $offer = $this->offer($owner);
        $this->openTrade($taker, $offer, '10');

        P2pTrade::query()->update(['expires_at' => now()->subMinute()]);

        $escrow = app(TradeEscrow::class);
        $this->assertSame(1, $escrow->cancelExpired()['cancelled']);
        // Nothing is pending the second time, so nothing is refunded twice.
        $this->assertSame(0, $escrow->cancelExpired()['cancelled']);

        $this->assertSame('500.00000000', $this->walletOf($owner)->available_balance);
        $this->assertSame('0.00000000', $this->walletOf($owner)->escrow_balance);
    }

    public function test_a_non_participant_cannot_cancel_someone_elses_trade(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $stranger = User::factory()->create();
        $this->walletWith($owner, '500');

        $offer = $this->offer($owner);
        $trade = $this->openTrade($taker, $offer, '10');

        Sanctum::actingAs($stranger);
        $this->postJson('/api/trades/' . $trade->trade_ref . '/cancel')->assertNotFound();

        $this->assertSame(P2pTrade::STATUS_PENDING, $trade->fresh()->status);
        $this->assertSame('10.00000000', $this->walletOf($owner)->escrow_balance);
    }

    public function test_escrow_refuses_to_move_a_non_positive_amount(): void
    {
        $owner = User::factory()->create();
        $this->walletWith($owner, '500');

        // A negative credit would drain the balance rather than fill it, and a
        // zero one writes a meaningless ledger row: both are bugs upstream.
        $this->expectException(InvalidArgumentException::class);
        $this->walletOf($owner)->credit('-10', 'admin');
    }
}
