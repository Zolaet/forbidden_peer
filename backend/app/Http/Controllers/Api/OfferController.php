<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Offer\StoreOfferRequest;
use App\Models\P2pOffer;
use App\Models\User; // 1. Import your User model
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OfferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type', 'buy'); // buy or sell
        $fiat = $request->query('fiat_currency');

        $offers = P2pOffer::with('user')
            ->where('status', 'active')
            ->where('type', $type)
            ->when($fiat, fn($q) => $q->where('fiat_currency', strtoupper($fiat)))
            ->orderBy('price', $type === 'buy' ? 'asc' : 'desc')
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
                if (!$wallet || $wallet->available_balance < $validated['total_amount']) {
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