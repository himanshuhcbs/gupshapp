<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Exception\CardException;
use Stripe\Exception\ApiErrorException;
use Stripe\Customer;

class PaymentController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }
    
    /**
     * Create Stripe Customer (for new users or on demand)
     */
    public function createCustomer(Request $request)
    {
        $user = $request->user();  // Authenticated frontend user

        if ($user->stripe_customer_id) {
            return response()->json(['message' => 'Customer already exists', 'customer_id' => $user->stripe_customer_id], 200);
        }

        try {
            $customer = Customer::create([
                'name' => $user->name,
                'email' => $user->email,
                'metadata' => ['app_user_id' => $user->id],  // Link back to your app
            ]);

            $user->update(['stripe_customer_id' => $customer->id]);

            return response()->json([
                'message' => 'Customer created successfully',
                'customer_id' => $customer->id,
                'details' => $customer,
            ], 201);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Update Stripe Customer (e.g., name, email, address)
     */
    public function updateCustomer(Request $request)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email',
            'address' => 'sometimes|array',  // e.g., {'line1': '123 Main St', 'city': 'New York', 'state': 'NY', 'postal_code': '10001', 'country': 'US'}
            'metadata' => 'sometimes|array',
        ]);

        $user = $request->user();

        if (!$user->stripe_customer_id) {
            return response()->json(['error' => 'No customer ID found. Create one first!'], 404);
        }

        try {
            $customer = Customer::update(
                $user->stripe_customer_id,
                $request->only(['name', 'email', 'address', 'metadata'])
            );

            // Optionally sync back to user model if email/name changed
            if ($request->has('name')) $user->update(['name' => $request->name]);
            if ($request->has('email')) $user->update(['email' => $request->email]);

            return response()->json([
                'message' => 'Customer updated successfully',
                'customer_id' => $customer->id,
                'details' => $customer,
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Create a Payment Intent (supports all payment methods)
     */
    public function createIntent(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.50',  // Minimum amount in USD
            'currency' => 'required|string|size:3',   // e.g., 'usd'
            'payment_method_types' => 'array',        // Optional: ['card', 'us_bank_account', etc.]
        ]);

        $user = $request->user();  // Authenticated frontend user

        try {
            $intent = PaymentIntent::create([
                'amount' => $request->amount * 100,  // Convert to cents
                'currency' => $request->currency,
                'automatic_payment_methods' => ['enabled' => true],  // Enables all available methods
                'metadata' => ['user_id' => $user->id],  // For tracking
            ]);

            // Save to database
            Payment::create([
                'user_id' => $user->id,
                'stripe_payment_id' => $intent->id,
                'status' => $intent->status,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'payment_method_type' => 'pending',  // Updated later via webhook
            ]);

            return response()->json([
                'client_secret' => $intent->client_secret,  // Send to frontend for confirmation
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Confirm Payment (if needed for server-side confirmation, e.g., 3D Secure)
     */
    public function confirmIntent(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
        ]);

        try {
            $intent = PaymentIntent::retrieve($request->payment_intent_id);
            $intent->confirm();

            // Update payment record
            $payment = Payment::where('stripe_payment_id', $intent->id)->first();
            if ($payment) {
                $payment->update([
                    'status' => $intent->status,
                    'payment_method_type' => $intent->payment_method_types[0] ?? null,
                ]);
            }

            return response()->json(['status' => $intent->status], 200);
        } catch (CardException $e) {
            return response()->json(['error' => $e->getError()->message], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get User's Payment History
     */
    public function history(Request $request)
    {
        $user = $request->user();
        $payments = $user->payments()->get();

        return response()->json($payments, 200);
    }
}