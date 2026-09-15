<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                // Plain here on purpose: User casts `password` => 'hashed', so
                // hashing again with Hash::make() would only be a second pass
                // over an already-hashed value.
                'password' => $validated['password'],
            ]);

            // Auto-provision a default USDT wallet upon registration. Balances
            // are decimal(18,8) strings, never floats — see App\Support\Money.
            Wallet::create([
                'user_id' => $user->id,
                'currency' => 'USDT',
                'available_balance' => '0',
                'escrow_balance' => '0',
            ]);

            return $user;
        });

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Registration successful.',
            // Same payload as profile(), so the client never has to follow up
            // with GET /user before it can render payment methods.
            'user' => $user->load(['wallet', 'paymentMethods']),
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (!Auth::attempt(['email' => $validated['email'], 'password' => $validated['password']])) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $user = User::where('email', $validated['email'])->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful.',
            // profile()'s shape, not a reduced one: TradeModal gates on
            // user.payment_methods, and a shorter payload here leaves it
            // showing "no saved payment method" until Layout's refresh lands.
            'user' => $user->load(['wallet', 'paymentMethods']),
            'token' => $token,
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load(['wallet', 'paymentMethods']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}