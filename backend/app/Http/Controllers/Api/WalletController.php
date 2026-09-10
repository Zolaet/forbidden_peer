<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientBalanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWithdrawalRequest;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Bsc\AddressManager;
use App\Services\Bsc\NetworkConfig;
use App\Services\Bsc\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class WalletController extends Controller
{
    public function __construct(
        protected WithdrawalService $withdrawals
    ) {
    }

    /**
     * GET /wallet — available/escrow balance + the user's deposit address
     * (lazily minted on first call).
     *
     * Balances ship as decimal strings. They live in decimal(18,8) columns, and
     * a JSON number cannot carry eight decimals of a large balance without
     * losing some of them — which is exactly the part a user is reconciling.
     */
    public function index(AddressManager $addressManager): JsonResponse
    {
        $user = Auth::user();

        $wallet = $user->wallet ?? Wallet::firstOrCreate(
            ['user_id' => $user->id, 'currency' => 'USDT'],
            ['available_balance' => 0, 'escrow_balance' => 0]
        );

        $deposit = null;
        $depositError = null;
        try {
            $address = $addressManager->ensure($user);
            $deposit = [
                'address' => $address->address,
                'network' => $address->network,
                'currency' => 'USDT',
                'contract' => NetworkConfig::usdtContract(),
                'min_confirmations' => NetworkConfig::minConfirmations(),
                'explorer_tx' => NetworkConfig::explorerTx(),
            ];
        } catch (\Throwable $e) {
            $depositError = $e->getMessage();
        }

        return response()->json([
            'message' => 'ok',
            'wallet' => [
                'available_balance' => (string) $wallet->available_balance,
                'escrow_balance' => (string) $wallet->escrow_balance,
                'currency' => 'USDT',
            ],
            'deposit' => $deposit,
            'deposit_error' => $depositError,
            'withdrawal' => [
                'fee' => NetworkConfig::withdrawalFeeExact(),
                'min' => NetworkConfig::withdrawalMinExact(),
                'network' => NetworkConfig::network(),
                'explorer_tx' => NetworkConfig::explorerTx(),
            ],
        ]);
    }

    /** GET /wallet/deposits */
    public function deposits(): JsonResponse
    {
        $user = Auth::user();

        $rows = $user->deposits()->orderByDesc('id')->paginate(15);

        return response()->json([
            'message' => 'ok',
            'deposits' => collect($rows->items())->map(fn ($d) => [
                'id' => $d->id,
                'amount' => (string) $d->amount,
                'tx_hash' => $d->tx_hash,
                'status' => $d->status,
                'confirmations' => $d->confirmations,
                'network' => $d->network,
                'created_at' => $d->created_at?->toIso8601String(),
                'credited_at' => $d->credited_at?->toIso8601String(),
                'explorer_tx' => NetworkConfig::explorerTx() . $d->tx_hash,
            ]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** GET /wallet/withdrawals */
    public function withdrawals(): JsonResponse
    {
        $user = Auth::user();

        $rows = $user->withdrawals()->orderByDesc('id')->paginate(15);

        return response()->json([
            'message' => 'ok',
            'withdrawals' => collect($rows->items())->map(fn ($w) => [
                'id' => $w->id,
                'amount' => (string) $w->amount,
                'fee' => (string) $w->fee,
                'net_amount' => (string) $w->net_amount,
                'to_address' => $w->to_address,
                'tx_hash' => $w->tx_hash,
                'status' => $w->status,
                'error' => $w->error,
                'network' => $w->network,
                'created_at' => $w->created_at?->toIso8601String(),
                'broadcast_at' => $w->broadcast_at?->toIso8601String(),
                'explorer_tx' => $w->tx_hash ? NetworkConfig::explorerTx() . $w->tx_hash : null,
            ]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /** GET /wallet/transactions — the internal ledger. */
    public function transactions(): JsonResponse
    {
        $user = Auth::user();

        $rows = $user->walletTransactions()->orderByDesc('id')->paginate(25);

        return response()->json([
            'message' => 'ok',
            'transactions' => collect($rows->items())->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => (string) $t->amount,
                'balance_after' => (string) $t->balance_after,
                'escrow_after' => (string) $t->escrow_after,
                'ref_type' => $t->ref_type,
                'ref_id' => $t->ref_id,
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /**
     * POST /wallet/withdrawals — debit + auto-broadcast a USDT transfer.
     *
     * 201 when broadcast; 400 when the network definitively rejected it and the
     * funds were re-credited; 409 when the balance was short, or when the
     * broadcast outcome is unknown and the debit therefore stands pending
     * review. The two 409s read very differently on purpose: one says the user
     * still has their money, the other says we have not given it back yet.
     */
    public function store(StoreWithdrawalRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $validated = $request->validated();

        try {
            $withdrawal = $this->withdrawals->createAndBroadcast(
                $user,
                (string) $validated['to_address'],
                // Passed straight through as the string the client sent — no
                // float cast between the request and the ledger.
                (string) $validated['amount']
            );
        } catch (InsufficientBalanceException $e) {
            return response()->json([
                'error' => 'Your available balance is too low for this withdrawal plus the network fee.',
            ], 409);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        $payload = [
            'id' => $withdrawal->id,
            'amount' => (string) $withdrawal->amount,
            'fee' => (string) $withdrawal->fee,
            'net_amount' => (string) $withdrawal->net_amount,
            'to_address' => $withdrawal->to_address,
            'status' => $withdrawal->status,
            'error' => $withdrawal->error,
            'tx_hash' => $withdrawal->tx_hash,
            'explorer_tx' => $withdrawal->tx_hash ? NetworkConfig::explorerTx() . $withdrawal->tx_hash : null,
            'network' => $withdrawal->network,
        ];

        if ($withdrawal->status === Withdrawal::SENT) {
            return response()->json([
                'message' => 'Withdrawal broadcast to the BSC network. It may take a minute to appear.',
                'withdrawal' => $payload,
            ], 201);
        }

        if ($withdrawal->status === Withdrawal::NEEDS_REVIEW) {
            // Explicitly not the FAILED message: nothing has been refunded,
            // because the transfer may already be on chain.
            return response()->json([
                'error' => 'We could not confirm whether this withdrawal reached the network. '
                    . 'Your balance stays debited while it is checked — contact support with the transaction hash.',
                'withdrawal' => $payload,
            ], 409);
        }

        // The network rejected it outright and the funds were re-credited.
        return response()->json([
            'error' => $withdrawal->error ?? 'Withdrawal could not be broadcast.',
            'withdrawal' => $payload,
        ], 400);
    }
}
