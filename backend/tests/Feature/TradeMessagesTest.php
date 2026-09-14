<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Trade messages and payment proofs.
 *
 * A proof is a bank-transfer screenshot: it must reach the two parties (and an
 * administrator adjudicating a dispute) and nobody else — which is why the
 * files live on the private disk and are served only through the authorized
 * downloadProof() door, never as a bare public URL.
 */
class TradeMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    /** Buyer marks the trade paid with a proof image. */
    protected function markPaidWithProof(P2pTrade $trade, User $buyer, string $note = 'Sent via telebirr'): void
    {
        Sanctum::actingAs($buyer);

        $this->post(
            '/api/trades/' . $trade->trade_ref . '/mark-paid',
            [
                'proof_image' => UploadedFile::fake()->image('proof.jpg'),
                'note' => $note,
            ],
            ['Accept' => 'application/json']
        )->assertOk();
    }

    /** A paid trade with a proof, and the two parties. @return array{0: P2pTrade, 1: User, 2: User} */
    protected function paidTradeWithProof(): array
    {
        $owner = User::factory()->create();
        $taker = User::factory()->create();
        $this->walletWith($owner, '500');
        $this->walletWith($taker, '500');

        $trade = $this->openTrade($taker, $this->offer($owner), '10');
        $this->markPaidWithProof($trade, $taker);

        return [$trade, $taker, $owner];
    }

    public function test_the_seller_sees_the_proof_message_and_can_fetch_the_image(): void
    {
        [$trade, $buyer, $seller] = $this->paidTradeWithProof();

        Sanctum::actingAs($seller);

        $response = $this->getJson('/api/trades/' . $trade->trade_ref . '/messages')->assertOk();

        $response->assertJsonCount(1, 'messages');
        $message = $response->json('messages.0');

        $this->assertSame($buyer->id, $message['sender_id']);
        $this->assertSame('Sent via telebirr', $message['message']);
        $this->assertTrue($message['is_proof_of_payment']);
        $this->assertSame(
            "trades/{$trade->trade_ref}/proof/{$message['id']}",
            $message['attachment_url']
        );

        // The URL the message advertises actually serves the image.
        $this->get('/api/' . $message['attachment_url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_proofs_land_on_the_private_disk_not_the_public_one(): void
    {
        $this->paidTradeWithProof();

        $this->assertNotEmpty(Storage::disk('local')->allFiles('payment_proofs'));
        $this->assertEmpty(Storage::disk('public')->allFiles('payment_proofs'));
    }

    public function test_a_stranger_gets_404_from_both_messages_and_proof(): void
    {
        [$trade, $buyer, $seller] = $this->paidTradeWithProof();
        $stranger = User::factory()->create();

        $messageId = $trade->messages()->firstOrFail()->id;

        Sanctum::actingAs($stranger);

        $this->getJson('/api/trades/' . $trade->trade_ref . '/messages')->assertNotFound();
        $this->getJson('/api/trades/' . $trade->trade_ref . '/proof/' . $messageId)->assertNotFound();
    }

    public function test_an_administrator_can_fetch_the_proof_but_not_the_messages(): void
    {
        [$trade, $buyer, $seller] = $this->paidTradeWithProof();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => User::ROLE_ADMIN])->save();

        $messageId = $trade->messages()->firstOrFail()->id;

        Sanctum::actingAs($admin);

        // Adjudication reads the evidence, but an admin is not a participant:
        // the conversation stays with the two parties.
        $this->getJson('/api/trades/' . $trade->trade_ref . '/messages')->assertNotFound();
        $this->getJson('/api/trades/' . $trade->trade_ref . '/proof/' . $messageId)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_a_proof_id_from_another_trade_does_not_leak(): void
    {
        [$trade, $buyer, $seller] = $this->paidTradeWithProof();

        // A second, unrelated paid trade with its own proof.
        $owner2 = User::factory()->create();
        $taker2 = User::factory()->create();
        $this->walletWith($owner2, '500');
        $this->walletWith($taker2, '500');
        $trade2 = $this->openTrade($taker2, $this->offer($owner2), '5');
        $this->markPaidWithProof($trade2, $taker2);

        $otherProofId = $trade2->messages()->firstOrFail()->id;

        // A participant of trade 1 asking for trade 2's proof by id.
        Sanctum::actingAs($buyer);
        $this->getJson('/api/trades/' . $trade->trade_ref . '/proof/' . $otherProofId)->assertNotFound();
    }
}
