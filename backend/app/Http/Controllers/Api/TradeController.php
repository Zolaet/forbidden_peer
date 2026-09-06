<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Trade\InitiateTradeRequest;
use App\Http\Requests\Trade\MarkTradePaidRequest;
use App\Http\Requests\Trade\ReleaseEscrowRequest;
use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\P2pTradeMessage;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TradeController extends Controller
{
    /**
     * Initiate a new trade and lock crypto into escrow.
     */
    public function initiate(InitiateTradeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $buyer */
        $buyer = Auth::user();

        try {
            $trade = DB::transaction(function () use ($validated, $buyer) {
                // Lock the offer row to prevent concurrent over-allocation
                $offer = P2pOffer::where('id', $validated['offer_id'])
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->firstOrFail();

                // Prevent trading on your own offer
                if ($offer->user_id === $buyer->id) {
                    throw new Exception("You cannot open a trade on your own offer.");
                }

                $amount = $validated['crypto_amount'];

                // Validate requested amount against offer limits
                if ($amount > $offer->remaining_amount) {
                    throw new Exception("Requested amount exceeds remaining offer capacity.");
                }

                /** @var User $seller */
                $seller = $offer->user;
                $sellerWallet = $seller->wallet()->lockForUpdate()->firstOrFail();

                // Lock seller's funds into escrow
                $sellerWallet->lockEscrow($amount);

                // Calculate fiat total
                $fiatAmount = $amount * $offer->price;

                // Create Trade Record
                $trade = P2pTrade::create([
                    'trade_ref' => 'TRD-' . strtoupper(Str::random(10)),
                    'offer_id' => $offer->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'payment_method_id' => $validated['payment_method_id'],
                    'crypto_amount' => $amount,
                    'fiat_amount' => $fiatAmount,
                    'unit_price' => $offer->price,
                    'status' => 'pending',
                    'expires_at' => now()->addMinutes($offer->payment_window_minutes),
                ]);

                // Update remaining amount on offer
                $offer->remaining_amount -= $amount;
                if ($offer->remaining_amount <= 0) {
                    $offer->status = 'completed';
                }
                $offer->save();

                return $trade;
            });

            return response()->json([
                'message' => 'Trade initiated successfully. Escrow locked.',
                'trade' => $trade->load(['offer', 'seller', 'paymentMethod']),
            ], 201);

        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Buyer marks trade as paid and uploads proof.
     */
    public function markPaid(MarkTradePaidRequest $request, string $tradeRef): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $trade = P2pTrade::where('trade_ref', $tradeRef)
            ->where('buyer_id', $user->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $path = $request->file('proof_image')->store('payment_proofs', 'public');

        DB::transaction(function () use ($trade, $path, $request, $user) {
            $trade->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // Create proof message record
            P2pTradeMessage::create([
                'trade_id' => $trade->id,
                'sender_id' => $user->id,
                'message' => $request->input('note', 'Payment has been sent.'),
                'attachment_path' => $path,
                'is_proof_of_payment' => true,
            ]);
        });

        return response()->json([
            'message' => 'Trade marked as paid. Seller notified.',
            'trade' => $trade,
        ]);
    }

    /**
     * Seller approves payment and releases escrow to buyer.
     */
    public function releaseEscrow(ReleaseEscrowRequest $request, string $tradeRef): JsonResponse
    {
        /** @var User $seller */
        $seller = Auth::user();

        $trade = P2pTrade::where('trade_ref', $tradeRef)
            ->where('seller_id', $seller->id)
            ->where('status', 'paid')
            ->firstOrFail();

        try {
            DB::transaction(function () use ($trade, $seller) {
                $sellerWallet = $seller->wallet;

                /** @var User $buyer */
                $buyer = $trade->buyer;
                $buyerWallet = $buyer->wallet;

                // Atomic transfer of escrowed funds to buyer's available balance
                $sellerWallet->releaseEscrowTo($buyerWallet, $trade->crypto_amount);

                $trade->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
            });

            return response()->json([
                'message' => 'Escrow released successfully. Trade completed.',
                'trade' => $trade,
            ]);

        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}