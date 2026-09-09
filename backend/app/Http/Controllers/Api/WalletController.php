<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWithdrawalRequest;
use App\Models\Wallet;
use App\Models\WalletTransaction;
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
                'available_balance' => (float) $wallet->available_balance,
                'escrow_balance' => (float) $wallet->escrow_balance,
                'currency' => 'USDT',
            ],
            'deposit' => $deposit,
            'deposit_error' => $depositError,
            'withdrawal' => [
                'fee' => NetworkConfig::withdrawalFee(),
                'min' => NetworkConfig::withdrawalMin(),
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
                'amount' => (float) $d->amount,
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
                'amount' => (float) $w->amount,
                'fee' => (float) $w->fee,
                'net_amount' => (float) $w->net_amount,
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
                'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'escrow_after' => (float) $t->escrow_after,
                'ref_type' => $t->ref_type,
                'ref_id' => $t->ref_id,
                'created_at' => $t->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()],
        ]);
    }

    /**
     * POST /wallet/withdrawals — debit + auto-broadcast a USDT transfer.
     * 201 on sent; 400 on anything that left the row failed (re-credited on
     * definitive rejection); 409 on insufficient balance.
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
                (float) $validated['amount']
            );
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            // Insufficient balance / DB errors bubble up from Wallet::debit.
            return response()->json(['error' => $e->getMessage()], 409);
        }

        $payload = [
            'id' => $withdrawal->id,
            'amount' => (float) $withdrawal->amount,
            'fee' => (float) $withdrawal->fee,
            'net_amount' => (float) $withdrawal->net_amount,
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

        // Row exists but broadcast did not finish cleanly. If the network
        // rejected it, funds were already re-credited (see $withdrawal->error).
        return response()->json([
            'error' => $withdrawal->error ?? 'Withdrawal could not be broadcast.',
            'withdrawal' => $payload,
        ], 400);
    }
}
