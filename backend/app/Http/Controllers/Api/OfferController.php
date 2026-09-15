<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offer\StoreOfferRequest;
use App\Models\P2pOffer;
use App\Models\User; // 1. Import your User model
use App\Support\Money;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OfferController extends Controller
{
    /**
     * The public marketplace board. Unauthenticated (see routes/api.php).
     *
     * The eager load names its columns on purpose. `with('user')` would
     * serialise the whole row, and User only hides password/remember_token —
     * so an anonymous caller would page through every active ad collecting
     * merchants' email addresses and `role`s, which is a ready-made target
     * list for credential stuffing and for picking out staff accounts. The
     * board only ever renders the advertiser's name.
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type', 'buy'); // buy or sell
        $fiat = $request->query('fiat_currency');

        $offers = P2pOffer::with('user:id,name')
            ->where('status', 'active')
            ->where('type', $type)
            ->when($fiat, fn($q) => $q->where('fiat_currency', strtoupper($fiat)))
            // type=sell are crypto sellers (buyers want the cheapest first);
            // type=buy  are crypto buyers (sellers want the best rate first).
            ->orderBy('price', $type === 'sell' ? 'asc' : 'desc')
            ->paginate(15);

        return response()->json(['offers' => $offers]);
    }

    public function store(StoreOfferRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */ // 2. Tell the editor Auth::user() is App\Models\User
        $user = Auth::user();

        try {
            // Security Check: If posting a SELL offer, verify available wallet balance
            if ($validated['type'] === 'sell') {
                $wallet = $user->wallet;

                // Money::lt, not `<`: available_balance is a decimal(18,8) and
                // total_amount is whatever the client sent. PHP's loose `<` on
                // two numeric strings compares them as floats past the point
                // where floats are exact — the one comparison in the codebase
                // that would not have matched the ledger.
                if (!$wallet || Money::lt((string) $wallet->available_balance, (string) $validated['total_amount'])) {
                    throw new Exception("Insufficient wallet balance to create this sell offer.");
                }
            }

            $offer = $user->offers()->create(array_merge($validated, [
                'remaining_amount' => $validated['total_amount'],
                'fiat_currency' => strtoupper($validated['fiat_currency']),
                'status' => 'active',
            ]));

            return response()->json([
                'message' => 'Offer posted successfully.',
                'offer' => $offer,
            ], 201);

        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}