<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\TradeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (No Authentication Required)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Anyone can view active public market offers
Route::get('/offers', [OfferController::class, 'index']);

/*
|--------------------------------------------------------------------------
| Protected Routes (Requires `Authorization: Bearer <TOKEN>`)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // --- Authentication & User Profile ---
    Route::get('/user', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // --- Payment Methods Management ---
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy']);

    // --- P2P Offer Creation ---
    Route::post('/offers', [OfferController::class, 'store']);

    // --- Core P2P Escrow Engine ---
    Route::post('/trades', [TradeController::class, 'initiate']);
    Route::post('/trades/{tradeRef}/mark-paid', [TradeController::class, 'markPaid']);
    Route::post('/trades/{tradeRef}/release', [TradeController::class, 'releaseEscrow']);

}); 