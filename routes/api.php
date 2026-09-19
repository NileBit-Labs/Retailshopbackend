<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\SupplierController;
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

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::middleware('shop.access')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
        Route::get('/suppliers/{supplier}/ledger', [SupplierController::class, 'ledger']);
    });

    Route::middleware('shop.access:owner,manager')->group(function () {
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::patch('/categories/{category}', [CategoryController::class, 'update']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::post('/products/{product}/archive', [ProductController::class, 'archive']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::patch('/purchases/{purchase}', [PurchaseController::class, 'update']);
        Route::post('/purchases/{purchase}/confirm', [PurchaseController::class, 'confirm']);
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::post('/suppliers/{supplier}/payments', [SupplierController::class, 'pay']);
    });

    Route::middleware('shop.access:owner')->group(function () {
        Route::post('/inventory/adjustments', [InventoryController::class, 'adjustment']);
        Route::post('/inventory/damage', [InventoryController::class, 'damage']);
        Route::post('/inventory/loss', [InventoryController::class, 'loss']);
    });
});
