<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\PosCatalogController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/shops', [ShopController::class, 'store']);
    Route::get('/shops/{shop}', [ShopController::class, 'show']);
    Route::patch('/shops/{shop}', [ShopController::class, 'update']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('shop.access')->group(function () {
        Route::get('/pos/products', [PosCatalogController::class, 'index']);

        Route::get('/sales', [SaleController::class, 'index']);
        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales/{sale}', [SaleController::class, 'show']);

        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::post('/customers/{customer}/payments', [CustomerController::class, 'pay']);

        Route::post('/sync/push', [SyncController::class, 'push']);
        Route::get('/sync/pull', [SyncController::class, 'pull']);
    });

    Route::middleware('shop.access:owner,manager')->group(function () {
        Route::post('/sales/{sale}/void', [SaleController::class, 'void']);
        Route::get('/sales/{sale}/refundable', [RefundController::class, 'refundable']);
        Route::post('/sales/{sale}/refund', [RefundController::class, 'store']);
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
        Route::get('/customers/{customer}/ledger', [CustomerController::class, 'ledger']);

        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update']);
    });
});
