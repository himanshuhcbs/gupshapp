<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PaymentController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes (require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [UserController::class, 'index']);
    Route::get('/user/{id}', [UserController::class, 'show']);
    Route::put('/user/{id}', [UserController::class, 'update']);
    Route::delete('/user/{id}', [UserController::class, 'destroy']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    Route::post('/payments/intent', [PaymentController::class, 'createIntent']);
    Route::post('/payments/confirm', [PaymentController::class, 'confirmIntent']);
    Route::get('/payments/history', [PaymentController::class, 'history']);
    
    Route::post('/customer/create', [PaymentController::class, 'createCustomer']);
    Route::post('/customer/update', [PaymentController::class, 'updateCustomer']);
});