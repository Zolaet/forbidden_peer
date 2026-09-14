<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The read side of trades: fetching one order, and the caller's order list.
 *
 * These endpoints are what the trade page polls, so the other party's actions
 * (mark paid, release, cancel) and the trades:expire sweep reach a browser
 * without a refresh — and what makes a trade exist on a device that never saw
 * it created.
 */
class TradeApiTest extends TestCase
{
    use RefreshDatabase;

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
            'price' => '180.00',
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

    public function test_both_parties_can_fetch_their_trade(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        $trade = $this->openTrade($taker, $this->offer($owner), '10');

        foreach ([$owner, $taker] as $party) {
            Sanctum::actingAs($party);

            $this->getJson('/api/trades/' . $trade->trade_ref)
                ->assertOk()
                ->assertJsonPath('trade.trade_ref', $trade->trade_ref)
                ->assertJsonPath('trade.status', P2pTrade::STATUS_PENDING)
                ->assertJsonPath('trade.crypto_amount', '10')
                // The seller sees their own role, not the buyer's.
                ->assertJsonPath('trade.role', $party->is($owner) ? 'seller' : 'buyer');
        }
    }

    public function test_a_stranger_gets_a_404_rather_than_learning_the_trade_exists(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $stranger = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        $trade = $this->openTrade($taker, $this->offer($owner), '10');

        Sanctum::actingAs($stranger);

        $this->getJson('/api/trades/' . $trade->trade_ref)->assertNotFound();
    }

    public function test_an_unauthenticated_caller_cannot_fetch_a_trade(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        $trade = $this->openTrade($taker, $this->offer($owner), '10');

        // openTrade() authenticated as the taker; drop back to being a guest.
        // (Sanctum::actingAs(null) would fatal — it calls methods on the user.)
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/trades/' . $trade->trade_ref)->assertUnauthorized();
    }

    public function test_the_index_returns_only_trades_the_caller_is_in(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $outsider = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');
        $this->walletWith($outsider, '500');

        // A sell ad: the taker buys. The owner is the seller in both.
        $asBuyer = $this->openTrade($taker, $this->offer($owner), '10');

        // A buy ad: the taker sells, so the same taker appears on both sides.
        $asSeller = $this->openTrade($taker, $this->offer($owner, ['type' => P2pOffer::TYPE_BUY]), '5');

        // A trade the caller has nothing to do with.
        $this->openTrade($outsider, $this->offer($owner), '1');

        Sanctum::actingAs($taker);

        $response = $this->getJson('/api/trades')->assertOk();

        $refs = collect($response->json('trades'))->pluck('trade_ref');

        $this->assertCount(2, $refs);
        $this->assertContains($asBuyer->trade_ref, $refs);
        $this->assertContains($asSeller->trade_ref, $refs);

        // Newest first, so the top of the list is what the user just did.
        $this->assertSame($asSeller->trade_ref, $refs->first());
    }

    public function test_payment_details_stay_with_the_method_owner(): void
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        // The taker's payment method backs the trade, and on a sell ad the
        // taker is the buyer — so the buyer sees their own account number and
        // the seller sees the channel but not the credentials.
        $trade = $this->openTrade($taker, $this->offer($owner), '10');

        Sanctum::actingAs($taker);
        $buyerView = $this->getJson('/api/trades/' . $trade->trade_ref)->assertOk();

        $this->assertSame('0123456789', $buyerView->json('trade.payment_method.account_number'));
        $this->assertSame('bank_transfer', $buyerView->json('trade.payment_method.type'));

        Sanctum::actingAs($owner);
        $sellerView = $this->getJson('/api/trades/' . $trade->trade_ref)->assertOk();

        $this->assertArrayNotHasKey(
            'account_number',
            $sellerView->json('trade.payment_method')
        );
        $this->assertSame('bank_transfer', $sellerView->json('trade.payment_method.type'));
    }
}
