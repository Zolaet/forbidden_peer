<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethod\StorePaymentMethodRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $methods = $user->paymentMethods()->where('is_active', true)->get();

        return response()->json(['payment_methods' => $methods]);
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = Auth::user();

        $method = $user->paymentMethods()->create(array_merge(
            $validated,
            ['is_active' => true]
        ));

        return response()->json([
            'message' => 'Payment method added successfully.',
            'payment_method' => $method,
        ], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $method = $user->paymentMethods()->findOrFail($id);
        $method->update(['is_active' => false]);

        return response()->json(['message' => 'Payment method removed.']);
    }
}