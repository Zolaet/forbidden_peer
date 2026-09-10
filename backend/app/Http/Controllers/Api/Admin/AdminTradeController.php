<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\TradePresenter;
use App\Http\Requests\Admin\ResolveDisputeRequest;
use App\Models\P2pTrade;
use App\Models\User;
use App\Services\P2p\TradeEscrow;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Platform-owner endpoints for adjudicating trades.
 *
 * Reached only through the `role:admin` middleware, so every method here can
 * assume the caller is staff. Authorization is the middleware's job; these
 * methods only decide what the staff view contains.
 */
class AdminTradeController extends Controller
{
    public function __construct(
        protected TradeEscrow $escrow
    ) {
    }

    /**
     * The adjudication queue.
     *
     * Defaults to disputed orders — the ones actually waiting on a human.
     * `?status=` takes any trade status, so the same endpoint doubles as an
     * audit of the completed and cancelled history.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'string', 'in:' . implode(',', [
                P2pTrade::STATUS_PENDING,
                P2pTrade::STATUS_PAID,
                P2pTrade::STATUS_COMPLETED,
                P2pTrade::STATUS_DISPUTED,
                P2pTrade::STATUS_CANCELLED,
                P2pTrade::STATUS_REFUNDED,
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = (string) $request->input('status', P2pTrade::STATUS_DISPUTED);

        $query = P2pTrade::with(['offer', 'buyer', 'seller', 'paymentMethod'])
            ->where('status', $status);

        if ($status === P2pTrade::STATUS_DISPUTED) {
            // A queue is worked oldest-first: the party who has been waiting
            // longest is the one whose money is stuck longest.
            $query->orderBy('disputed_at')->orderBy('id');
        } else {
            $query->latest('id');
        }

        $trades = $query->paginate((int) $request->input('per_page', 25));

        /** @var User $viewer */
        $viewer = Auth::user();

        return response()->json([
            'message' => 'ok',
            'trades' => array_map(
                fn (P2pTrade $trade) => TradePresenter::for($trade, $viewer),
                $trades->items()
            ),
            'meta' => [
                'current_page' => $trades->currentPage(),
                'last_page' => $trades->lastPage(),
                'per_page' => $trades->perPage(),
                'total' => $trades->total(),
            ],
        ]);
    }

    /**
     * One order in full, including the evidence.
     *
     * The payment proof attachments are the entire basis for adjudicating, so
     * they come back with the trade. The files live on the public disk, so the
     * path is a usable URL.
     */
    public function show(string $tradeRef): JsonResponse
    {
        $trade = P2pTrade::with(['offer', 'buyer', 'seller', 'paymentMethod', 'messages'])
            ->where('trade_ref', $tradeRef)
            ->firstOrFail();

        /** @var User $viewer */
        $viewer = Auth::user();

        $proofs = $trade->messages
            ->where('is_proof_of_payment', true)
            ->map(fn ($message) => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'message' => $message->message,
                'attachment_path' => $message->attachment_path,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'message' => 'ok',
            'trade' => TradePresenter::for($trade, $viewer) + ['proofs' => $proofs],
        ]);
    }

    /**
     * Rule on a disputed order.
     *
     * `release` pays the buyer, `refund` returns the escrow to the seller.
     * Both are terminal and both are guarded on the trade's status inside
     * TradeEscrow, so a double-submit cannot move the money twice.
     */
    public function resolve(ResolveDisputeRequest $request, string $tradeRef): JsonResponse
    {
        /** @var User $admin */
        $admin = Auth::user();

        $validated = $request->validated();

        // A missing trade is a 404 rather than a 409: this is the only place
        // the owner can be told the ref is wrong.
        $trade = P2pTrade::where('trade_ref', $tradeRef)->firstOrFail();

        try {
            $resolved = $validated['outcome'] === 'release'
                ? $this->escrow->resolveToBuyer($trade, $admin, $validated['note'])
                : $this->escrow->resolveToSeller($trade, $admin, $validated['note']);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        } catch (InsufficientBalanceException $e) {
            // The escrow no longer covers the order — an accounting fault that
            // needs a human, not a retry.
            report($e);

            return response()->json([
                'error' => 'This order could not be resolved: the escrowed balance no longer covers it. This needs manual correction.',
            ], 409);
        }

        return response()->json([
            'message' => $validated['outcome'] === 'release'
                ? 'Released to the buyer. The order is complete.'
                : 'Refunded to the seller. The order is closed.',
            'trade' => TradePresenter::for($resolved, $admin),
        ]);
    }
}
