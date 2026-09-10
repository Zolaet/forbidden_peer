<?php

use App\Http\Controllers\Api\Admin\AdminTradeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\TradeController;
use App\Http\Controllers\Api\WalletController;
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
    Route::post('/trades/{tradeRef}/cancel', [TradeController::class, 'cancel']);
    Route::post('/trades/{tradeRef}/dispute', [TradeController::class, 'openDispute']);

    // --- USDT (BEP-20) Wallet: deposits & withdrawals ---
    Route::get('/wallet', [WalletController::class, 'index']);
    Route::get('/wallet/deposits', [WalletController::class, 'deposits']);
    Route::get('/wallet/withdrawals', [WalletController::class, 'withdrawals']);
    Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
    Route::post('/wallet/withdrawals', [WalletController::class, 'store']);

    /*
    |----------------------------------------------------------------------
    | Platform owner: dispute adjudication
    |----------------------------------------------------------------------
    |
    | Nested inside the auth:sanctum group, so `role:admin` only ever runs
    | against a real authenticated user — a guest is rejected for being a
    | guest before the role check is reached.
    */
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/trades', [AdminTradeController::class, 'index']);
        Route::get('/trades/{tradeRef}', [AdminTradeController::class, 'show']);
        Route::post('/trades/{tradeRef}/resolve', [AdminTradeController::class, 'resolve']);
    });

}); 