<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\TradePresenter;
use App\Http\Requests\Trade\InitiateTradeRequest;
use App\Http\Requests\Trade\MarkTradePaidRequest;
use App\Http\Requests\Trade\OpenDisputeRequest;
use App\Http\Requests\Trade\ReleaseEscrowRequest;
use App\Models\P2pOffer;
use App\Models\P2pTrade;
use App\Models\P2pTradeMessage;
use App\Models\User;
use App\Models\Wallet;
use App\Services\P2p\TradeEscrow;
use App\Support\Money;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TradeController extends Controller
{
    public function __construct(
        protected TradeEscrow $escrow
    ) {
    }

    /**
     * Initiate a new trade and lock crypto into escrow.
     *
     * Which side is which is a property of the *ad*, not of who tapped it:
     * a 'sell' ad is a crypto seller advertising, so the taker buys and the
     * owner's balance backs the escrow. A 'buy' ad is a crypto buyer
     * advertising, so the taker is the one selling and the taker's USDT is
     * what gets locked.
     */
    public function initiate(InitiateTradeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $taker */
        $taker = Auth::user();

        try {
            $trade = DB::transaction(function () use ($validated, $taker) {
                // Lock the ad row to prevent concurrent over-allocation.
                $offer = P2pOffer::where('id', $validated['offer_id'])
                    ->where('status', P2pOffer::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ((int) $offer->user_id === (int) $taker->id) {
                    throw new DomainException('You cannot open a trade on your own offer.');
                }

                $amount = Money::of((string) $validated['crypto_amount']);

                if (!Money::isPositive($amount)) {
                    throw new DomainException('Enter an amount greater than zero.');
                }

                if (Money::gt($amount, (string) $offer->remaining_amount)) {
                    throw new DomainException('Requested amount exceeds remaining offer capacity.');
                }

                // Limits are quoted in fiat, so they bound the order's fiat
                // total. Enforced here as well as in the UI — the client is not
                // allowed to be the only thing standing between an ad and an
                // order outside its stated range.
                $fiatAmount = Money::mul($amount, (string) $offer->price, 2);

                if (Money::isPositive((string) $offer->min_limit)
                    && Money::lt($fiatAmount, (string) $offer->min_limit)) {
                    throw new DomainException(
                        'Order is below this ad\'s minimum of ' . $offer->min_limit . ' ' . $offer->fiat_currency . '.'
                    );
                }

                if (Money::isPositive((string) $offer->max_limit)
                    && Money::gt($fiatAmount, (string) $offer->max_limit)) {
                    throw new DomainException(
                        'Order is above this ad\'s maximum of ' . $offer->max_limit . ' ' . $offer->fiat_currency . '.'
                    );
                }

                /** @var User $offerOwner */
                $offerOwner = $offer->user;

                [$buyer, $seller] = $offer->ownerSellsCrypto()
                    ? [$taker, $offerOwner]
                    : [$offerOwner, $taker];

                $sellerWallet = Wallet::lockedFor($seller->id);

                try {
                    $sellerWallet->lockEscrow($amount);
                } catch (InsufficientBalanceException $e) {
                    throw new DomainException(
                        (int) $seller->id === (int) $taker->id
                            ? 'Your available USDT balance is too low to cover this order.'
                            : 'This ad\'s owner no longer has enough USDT to cover your order.'
                    );
                }

                $trade = P2pTrade::create([
                    'trade_ref' => 'TRD-' . strtoupper(Str::random(10)),
                    'offer_id' => $offer->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'payment_method_id' => $validated['payment_method_id'],
                    'crypto_amount' => $amount,
                    'fiat_amount' => $fiatAmount,
                    'unit_price' => (string) $offer->price,
                    'status' => P2pTrade::STATUS_PENDING,
                    'expires_at' => now()->addMinutes($offer->payment_window_minutes),
                ]);

                $offer->remaining_amount = Money::sub((string) $offer->remaining_amount, $amount);
                if (!Money::isPositive($offer->remaining_amount)) {
                    $offer->status = P2pOffer::STATUS_COMPLETED;
                }
                $offer->save();

                return $trade;
            });
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        return response()->json([
            'message' => 'Trade initiated successfully. Escrow locked.',
            'trade' => $this->present($trade->fresh(['offer', 'buyer', 'seller', 'paymentMethod']), $taker),
        ], 201);
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
            ->where('status', P2pTrade::STATUS_PENDING)
            ->firstOrFail();

        // Checked before the upload so a closed order doesn't leave a file
        // behind for a trade that can no longer be paid.
        if ($trade->hasExpired()) {
            return response()->json([
                'error' => 'The payment window for this order has closed. Place a new order to try again.',
            ], 409);
        }

        $path = $request->file('proof_image')->store('payment_proofs', 'public');

        DB::transaction(function () use ($trade, $path, $request, $user) {
            $trade->update([
                'status' => P2pTrade::STATUS_PAID,
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
            'trade' => $this->present($trade->fresh(['offer', 'buyer', 'seller', 'paymentMethod']), $user),
        ]);
    }

    /**
     * Seller approves payment and releases escrow to buyer.
     */
    public function releaseEscrow(ReleaseEscrowRequest $request, string $tradeRef): JsonResponse
    {
        /** @var User $seller */
        $seller = Auth::user();

        // Also allowed while the order is disputed. Releasing only ever moves
        // money towards the buyer and only the seller can trigger it, so
        // leaving this open means raising a dispute can never strand an honest
        // seller waiting on the platform owner to rule.
        $trade = P2pTrade::where('trade_ref', $tradeRef)
            ->where('seller_id', $seller->id)
            ->whereIn('status', P2pTrade::RESOLVABLE_STATUSES)
            ->firstOrFail();

        try {
            DB::transaction(function () use ($trade) {
                /** @var User $buyer */
                $buyer = $trade->buyer;

                $sellerWallet = Wallet::lockedFor($trade->seller_id);
                $buyerWallet = Wallet::lockedFor($buyer->id);

                // Atomic transfer of escrowed funds to buyer's available balance.
                $sellerWallet->releaseEscrowTo($buyerWallet, (string) $trade->crypto_amount, $trade);

                $trade->update([
                    'status' => P2pTrade::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            });
        } catch (InsufficientBalanceException $e) {
            // Escrow no longer covers the trade — an accounting fault that
            // needs a human, not a retry.
            report($e);

            return response()->json([
                'error' => 'This order could not be released: the escrowed balance no longer covers it. Support has been notified.',
            ], 409);
        }

        return response()->json([
            'message' => 'Escrow released successfully. Trade completed.',
            'trade' => $this->present($trade->fresh(['offer', 'buyer', 'seller', 'paymentMethod']), $seller),
        ]);
    }

    /**
     * Seller cancels an order the buyer never paid for, returning the escrow.
     *
     * Only available while the order is still unpaid: once the buyer has marked
     * it paid, the money is theirs to release or dispute, not to withdraw.
     */
    public function cancel(Request $request, string $tradeRef): JsonResponse
    {
        /** @var User $seller */
        $seller = Auth::user();

        $trade = P2pTrade::where('trade_ref', $tradeRef)
            ->where('seller_id', $seller->id)
            ->firstOrFail();

        try {
            $cancelled = $this->escrow->cancel($trade, 'Cancelled by the seller.');
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Order cancelled. The escrowed USDT is back in your available balance.',
            'trade' => $this->present($cancelled, $seller),
        ]);
    }

    /**
     * Flag a paid order for platform review.
     *
     * Either party may raise one. The query restricts to a participant, so a
     * stranger gets a 404 rather than learning the order exists — and no money
     * moves here at all: the escrow stays put until an administrator rules.
     */
    public function openDispute(OpenDisputeRequest $request, string $tradeRef): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $trade = P2pTrade::where('trade_ref', $tradeRef)
            ->where(function ($query) use ($user) {
                $query->where('buyer_id', $user->id)
                    ->orWhere('seller_id', $user->id);
            })
            ->firstOrFail();

        try {
            $disputed = $this->escrow->openDispute($trade, $user, $request->validated()['reason']);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Order flagged for review. The escrow stays locked until the platform decides.',
            'trade' => $this->present($disputed, $user),
        ]);
    }

    /**
     * Shape a trade for one viewer.
     *
     * The definition lives in TradePresenter because the admin controller
     * returns the same shape, and two copies of a redaction rule is one copy
     * too many.
     */
    protected function present(P2pTrade $trade, User $viewer): array
    {
        return TradePresenter::for($trade, $viewer);
    }
}
