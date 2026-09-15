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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TradeController extends Controller
{
    public function __construct(
        protected TradeEscrow $escrow
    ) {
    }

    /**
     * The caller's own orders, newest first.
     *
     * The frontend keeps a localStorage mirror of trades it has seen, but a
     * browser is not a database: this is what makes orders exist on a fresh
     * device, and what the trade page polls to pick up changes made by the
     * other party, an administrator, or the trades:expire sweep.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $trades = P2pTrade::where(function ($query) use ($user) {
            $query->where('buyer_id', $user->id)
                ->orWhere('seller_id', $user->id);
        })
            ->with(['offer', 'buyer', 'seller', 'paymentMethod'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'trades' => $trades
                ->map(fn (P2pTrade $trade) => $this->present($trade, $user))
                ->values(),
        ]);
    }

    /**
     * One order, for a participant.
     *
     * Scoped to buyer or seller, so a stranger gets a 404 rather than learning
     * the order exists — the same boundary openDispute() draws.
     */
    public function show(string $tradeRef): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $trade = $this->participantTrade($tradeRef, $user);

        return response()->json([
            'trade' => $this->present($trade->load(['offer', 'buyer', 'seller', 'paymentMethod']), $user),
        ]);
    }

    /**
     * The order's messages: notes and payment proofs.
     *
     * A proof attachment is referenced by a URL that points back at
     * downloadProof() rather than at a public file — a bank-transfer screenshot
     * is for the two parties and an administrator, not for anyone who can
     * guess a storage path.
     */
    public function messages(string $tradeRef): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $trade = $this->participantTrade($tradeRef, $user);

        $messages = $trade->messages()->with('sender')->orderBy('id')->get();

        return response()->json([
            'messages' => $messages->map(function (P2pTradeMessage $message) use ($trade) {
                return [
                    'id' => $message->id,
                    'sender_id' => $message->sender_id,
                    'sender_name' => $message->sender?->name,
                    'message' => $message->message,
                    'is_proof_of_payment' => $message->is_proof_of_payment,
                    // Relative to /api — the SPA's axios client carries the
                    // bearer token; an <img> tag alone cannot.
                    'attachment_url' => $message->attachment_path
                        ? "trades/{$trade->trade_ref}/proof/{$message->id}"
                        : null,
                    'sent_at' => $message->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    /**
     * Serve one proof image to someone entitled to see it.
     *
     * The caller must be a participant, or an administrator — the proof is the
     * evidence a dispute ruling turns on, so staff reach it too. New uploads
     * go to the private disk; the public-disk fallback covers proofs uploaded
     * before that switch.
     */
    public function downloadProof(string $tradeRef, int $message): Response
    {
        /** @var User $user */
        $user = Auth::user();

        $trade = P2pTrade::where('trade_ref', $tradeRef)->firstOrFail();

        if ($trade->roleOf($user) === null && !$user->isAdmin()) {
            // 404, not 403 — same rule as everywhere else: a stranger does
            // not get to learn the order exists.
            return response()->json(['error' => 'Not found.'], 404);
        }

        $proof = $trade->messages()
            ->whereKey($message)
            ->whereNotNull('attachment_path')
            ->first();

        if (!$proof) {
            return response()->json(['error' => 'Not found.'], 404);
        }

        $disk = Storage::disk('local')->exists($proof->attachment_path) ? 'local' : 'public';

        if (!Storage::disk($disk)->exists($proof->attachment_path)) {
            return response()->json(['error' => 'The proof file is missing.'], 404);
        }

        return response()->file(Storage::disk($disk)->path($proof->attachment_path));
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

        // This read draws the buyer boundary only. The status is deliberately
        // not filtered on here — it is re-read under the row lock below, which
        // is the read that decides.
        $trade = P2pTrade::where('trade_ref', $tradeRef)
            ->where('buyer_id', $user->id)
            ->firstOrFail();

        // The private disk, not 'public': proofs are bank-transfer screenshots
        // and must only ever reach a participant or an administrator, never a
        // bare URL. downloadProof() is the door.
        $path = $request->file('proof_image')->store('payment_proofs', 'local');

        try {
            DB::transaction(function () use ($trade, $path, $request, $user) {
                // Re-read under the lock. This used to check the status and the
                // expiry on an unlocked read, store the file, and then update
                // without re-checking anything: a double-submit could mark the
                // same order paid twice, and — worse — could land *after* the
                // trades:expire sweep had refunded the seller, leaving an order
                // marked paid whose escrow had already gone back.
                $locked = P2pTrade::whereKey($trade->id)->lockForUpdate()->first();

                if (!$locked || $locked->status !== P2pTrade::STATUS_PENDING) {
                    throw new DomainException(
                        'This order can no longer be marked paid — it has been cancelled, released or disputed.'
                    );
                }

                if ($locked->hasExpired()) {
                    throw new DomainException(
                        'The payment window for this order has closed. Place a new order to try again.'
                    );
                }

                $locked->update([
                    'status' => P2pTrade::STATUS_PAID,
                    'paid_at' => now(),
                ]);

                // Create proof message record
                P2pTradeMessage::create([
                    'trade_id' => $locked->id,
                    'sender_id' => $user->id,
                    'message' => $request->input('note', 'Payment has been sent.'),
                    'attachment_path' => $path,
                    'is_proof_of_payment' => true,
                ]);
            });
        } catch (DomainException $e) {
            // The upload has to happen before the guard so a closed order
            // doesn't strand a file, which means the rejection has to clean up
            // after itself here.
            Storage::disk('local')->delete($path);

            return response()->json(['error' => $e->getMessage()], 409);
        }

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

        // The seller boundary is drawn here so a stranger gets a 404; whether
        // the order is in a releasable state is TradeEscrow's call, made under
        // the row lock rather than on this read.
        $trade = P2pTrade::where('trade_ref', $tradeRef)
            ->where('seller_id', $seller->id)
            ->firstOrFail();

        try {
            $completed = $this->escrow->releaseBySeller($trade);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
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
            'trade' => $this->present($completed, $seller),
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

    /**
     * One order, scoped to a participant — the query behind show() and
     * messages(). A stranger gets a 404 rather than learning the order
     * exists.
     */
    protected function participantTrade(string $tradeRef, User $user): P2pTrade
    {
        /** @var P2pTrade */
        return P2pTrade::where('trade_ref', $tradeRef)
            ->where(function ($query) use ($user) {
                $query->where('buyer_id', $user->id)
                    ->orWhere('seller_id', $user->id);
            })
            ->firstOrFail();
    }
}
