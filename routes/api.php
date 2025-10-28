<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StripeWebhookController;
use Illuminate\Support\Facades\Route;

// Public Auth Routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Protected Routes (Require Sanctum Token)
Route::middleware('auth:sanctum')->group(function () {
    // Auth Logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // User Management
    Route::get('/user', [UserController::class, 'index']);
    Route::get('/user/{id}', [UserController::class, 'show']);
    Route::put('/user/{id}', [UserController::class, 'update']);
    Route::delete('/user/{id}', [UserController::class, 'destroy']);

    // Payment Intents
    Route::post('/payments/intent', [PaymentController::class, 'createIntent']);
    Route::post('/payments/confirm', [PaymentController::class, 'confirmIntent']);
    Route::get('/payments/history', [PaymentController::class, 'history']);
    Route::post('/payments/refund', [PaymentController::class, 'refundPayment']);

    // Stripe Customers
    Route::post('/stripe/customer/create', [PaymentController::class, 'createCustomer']);
    Route::post('/stripe/customer/update', [PaymentController::class, 'updateCustomer']);

    // Stripe Prices (Plans)
    Route::get('/stripe/prices', [PaymentController::class, 'getPrices']);
    Route::get('/stripe/prices/{priceId}', [PaymentController::class, 'getPrice']);

    // Stripe Payment Methods
    Route::post('/stripe/payment-methods/create', [PaymentController::class, 'createPaymentMethod']);
    Route::post('/stripe/payment-methods/setup-intent', [PaymentController::class, 'createSetupIntent']);
    Route::post('/stripe/payment-methods/setup-intent/confirm', [PaymentController::class, 'confirmSetupIntent']);
    Route::get('/stripe/payment-methods', [PaymentController::class, 'listPaymentMethods']);
    Route::post('/stripe/payment-methods/attach', [PaymentController::class, 'attachPaymentMethod']);
    Route::post('/stripe/payment-methods/{paymentMethodId}/detach', [PaymentController::class, 'detachPaymentMethod']);
    Route::post('/stripe/payment-methods/{paymentMethodId}/default', [PaymentController::class, 'setDefaultPaymentMethod']);

    // Stripe Subscriptions
    Route::post('/stripe/subscription/create', [PaymentController::class, 'createSubscription']);
    Route::get('/stripe/subscription/{subscriptionId}', [PaymentController::class, 'getSubscription']);
    Route::post('/stripe/subscription/{subscriptionId}/update', [PaymentController::class, 'updateSubscription']);
    Route::post('/stripe/subscription/{subscriptionId}/cancel', [PaymentController::class, 'cancelSubscription']);
    
    // Stripe Invoices
    Route::get('/stripe/invoices/{invoiceId}', [PaymentController::class, 'getInvoice']);
    Route::post('/stripe/invoices/{invoiceId}/pay', [PaymentController::class, 'payInvoice']);
});

// Stripe Webhook (Unprotected)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);