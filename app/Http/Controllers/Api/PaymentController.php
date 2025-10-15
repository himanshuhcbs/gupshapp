<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Subscription as StripeSubscription;
use Stripe\Price;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\Product;
use Stripe\SetupIntent;
use Stripe\Invoice;
use Stripe\Refund;
use Stripe\Exception\ApiErrorException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create a Payment Intent (supports all payment methods)
     */
    public function createIntent(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.50',
            'currency' => 'required|string|size:3',
            'payment_method_types' => 'array',
            'metadata' => 'sometimes|array',
        ]);

        $user = $request->user();

        $params = [
            'amount' => $request->amount * 100,
            'currency' => $request->currency,
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => array_merge(['user_id' => $user->id], $request->metadata ?? []),
        ];

        if ($request->has('payment_method_types')) {
            $params['automatic_payment_methods']['enabled'] = false;
            $params['payment_method_types'] = $request->payment_method_types;
        }

        try {
            $intent = PaymentIntent::create($params);

            Payment::create([
                'user_id' => $user->id,
                'stripe_payment_id' => $intent->id,
                'status' => $intent->status,
                'amount' => $request->amount,
                'currency' => $request->currency,
                'payment_method_type' => 'pending',
                'metadata' => array_merge(['user_id' => $user->id], $request->metadata ?? []),
            ]);

            return response()->json([
                'client_secret' => $intent->client_secret,
                'payment_intent_id' => $intent->id,
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
            'payment_method_id' => 'nullable|string',
        ]);

        $user = $request->user();

        try {
            $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);

            // Ensure PaymentIntent belongs to the user's customer
            if ($paymentIntent->customer !== $user->stripe_customer_id) {
                // Update PaymentIntent with customer if not set
                if (!$paymentIntent->customer) {
                    PaymentIntent::update($request->payment_intent_id, [
                        'customer' => $user->stripe_customer_id,
                    ]);
                    $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);
                } else {
                    return response()->json(['error' => 'Payment intent does not belong to this user'], 403);
                }
            }

            // Get payment method: from request or default
            $paymentMethodId = $request->payment_method_id;
            if (!$paymentMethodId) {
                $defaultPaymentMethod = $user->paymentMethods()->where('is_default', true)->first();
                if (!$defaultPaymentMethod) {
                    return response()->json(['error' => 'No payment method provided and no default payment method found'], 400);
                }
                $paymentMethodId = $defaultPaymentMethod->stripe_payment_method_id;
            }

            // Verify payment method belongs to customer
            $paymentMethod = StripePaymentMethod::retrieve($paymentMethodId);
            if ($paymentMethod->customer !== $user->stripe_customer_id) {
                $paymentMethod->attach(['customer' => $user->stripe_customer_id]);
            }

            // Update PaymentIntent with payment method if not set
            if (!$paymentIntent->payment_method) {
                PaymentIntent::update($request->payment_intent_id, [
                    'payment_method' => $paymentMethodId,
                    'customer' => $user->stripe_customer_id, // Reinforce customer
                ]);
                $paymentIntent = PaymentIntent::retrieve($request->payment_intent_id);
            }

            // Confirm the PaymentIntent
            if ($paymentIntent->status === 'requires_confirmation' || $paymentIntent->status === 'requires_payment_method') {
                $paymentIntent->confirm();
            }

            if ($paymentIntent->status === 'succeeded') {
                // Save to payments table
                Payment::updateOrCreate(
                    ['stripe_payment_id' => $paymentIntent->id], // Search condition
                    [
                        'user_id' => $user->id,
                        'amount' => $paymentIntent->amount / 100,
                        'currency' => $paymentIntent->currency,
                        'status' => $paymentIntent->status,
                        'payment_method_id' => $paymentMethodId,
                    ]
                );

                return response()->json([
                    'message' => 'Payment confirmed! Cha-ching! 😎',
                    'payment_intent_id' => $paymentIntent->id,
                    'status' => $paymentIntent->status,
                    'amount' => $paymentIntent->amount / 100,
                    'currency' => $paymentIntent->currency,
                ], 200);
            }

            return response()->json(['error' => 'Payment intent not successful: ' . $paymentIntent->status], 400);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => 'Failed to confirm payment intent: ' . $e->getMessage()], 400);
        }
    }
    
    /**
     * Process Full Refund for a Payment Intent
     * Updates payments table status and adds refund_id to metadata
     */
    public function refundPayment(Request $request)
    {
        $request->validate([
            'payment_intent_id' => 'required|string',
            'reason' => 'nullable|string',
        ]);

        $user = $request->user();
        $paymentIntentId = $request->payment_intent_id;

        try {
            // Retrieve and validate PaymentIntent
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            // Verify belongs to user
            if ($paymentIntent->customer !== $user->stripe_customer_id) {
                return response()->json(['error' => 'Payment intent does not belong to this user'], 403);
            }

            // Check if already refunded
            if ($paymentIntent->status === 'canceled' || $paymentIntent->amount_refunded >= $paymentIntent->amount) {
                return response()->json(['error' => 'Payment has already been fully refunded or canceled'], 400);
            }

            // Check if payment succeeded
            if ($paymentIntent->status !== 'succeeded') {
                return response()->json(['error' => 'Only successful payments can be refunded'], 400);
            }

            // Create full refund
            $refund = Refund::create([
                'payment_intent' => $paymentIntentId,
                'reason' => $request->reason ?? 'requested_by_customer',
                'metadata' => [
                    'user_id' => $user->id,
                    'app_name' => 'gupshapp',
                    'refund_type' => 'full',
                    'original_payment_intent' => $paymentIntentId,
                ],
            ]);

            // Update local payments table
            $payment = Payment::where('user_id', $user->id)
                ->where('stripe_payment_id', $paymentIntentId)
                ->first();

            if ($payment) {
                // Update status and add refund_id to metadata
                $payment->update([
                    'status' => 'refunded',
                    'metadata' => array_merge(
                        $payment->metadata ?? [],
                        [
                            'refund_id' => $refund->id,
                            'refund_status' => $refund->status,
                            'refund_amount' => $refund->amount / 100,
                            'refund_reason' => $refund->reason,
                            'refund_created_at' => Carbon::createFromTimestamp($refund->created)->toDateTimeString(),
                        ]
                    ),
                ]);
            }

            return response()->json([
                'message' => 'Full refund processed successfully! Money back guaranteed! 💰😎',
                'refund' => [
                    'id' => $refund->id,
                    'status' => $refund->status,
                    'amount' => $refund->amount / 100,
                    'currency' => $refund->currency,
                    'reason' => $refund->reason,
                    'payment_intent' => $refund->payment_intent,
                    'created' => Carbon::createFromTimestamp($refund->created)->toDateTimeString(),
                ],
                'payment_intent' => [
                    'id' => $paymentIntentId,
                    'status' => $paymentIntent->status,
                    'amount' => $paymentIntent->amount / 100,
                    'amount_refunded' => $paymentIntent->amount_refunded / 100,
                ],
                'local_record_updated' => $payment ? true : false,
            ], 200);

        } catch (ApiErrorException $e) {
            return response()->json([
                'error' => 'Failed to process refund: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get User's Payment History
     */
    public function history(Request $request)
    {
        $user = $request->user();
        
        $perPage = $request->integer('per_page', 10);
        $page = $request->integer('page', 1);
        
        $payments = $user->payments()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
        
        return response()->json([
            'payments' => $payments->items(),
            'pagination' => [
                'current_page' => $payments->currentPage(),
                'total_pages' => $payments->lastPage(),
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'has_next' => $payments->hasMorePages(),
            ]
        ], 200);
    }

    /**
     * Create Stripe Customer (for new users or on demand)
     */
    public function createCustomer(Request $request)
    {
        $user = $request->user();

        if ($user->stripe_customer_id) {
            return response()->json([
                'message' => 'Customer already exists',
                'customer_id' => $user->stripe_customer_id,
                'details' => $user->stripe_customer_id,
            ], 200);
        }

        try {
            $customer = Customer::create([
                'name' => $user->name,
                'email' => $user->email,
                'metadata' => [
                    'app_user_id' => $user->id,
                    'created_at' => now()->toISOString(),
                ],
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
            'email' => 'sometimes|email|max:255',
            'address' => 'sometimes|array',
            'metadata' => 'sometimes|array',
        ]);

        $user = $request->user();

        if (!$user->stripe_customer_id) {
            return response()->json([
                'error' => 'No customer ID found. Create one first!'
            ], 404);
        }

        try {
            $updateData = $request->only(['name', 'email', 'address', 'metadata']);

            $customer = Customer::update(
                $user->stripe_customer_id,
                $updateData
            );

            // Sync back to user model if changed
            if ($request->has('name')) {
                $user->update(['name' => $request->name]);
            }
            if ($request->has('email')) {
                $user->update(['email' => $request->email]);
            }

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
     * Get All Active Prices (Subscription Plans)
     */
    public function getPrices(Request $request)
    {
        try {
            $prices = Price::all([
                'active' => true,
                'expand' => ['data.product'],
                'type' => 'recurring', // Only recurring prices for subscriptions
            ]);

            $formattedPrices = collect($prices->data)->map(function ($price) {
                return [
                    'price_id' => $price->id,
                    'product_name' => $price->product->name,
                    'product_description' => $price->product->description ?? null,
                    'amount' => $price->unit_amount / 100,
                    'currency' => $price->currency,
                    'interval' => $price->recurring ? $price->recurring->interval : null,
                    'interval_count' => $price->recurring ? $price->recurring->interval_count : 1,
                    'active' => $price->active,
                ];
            })->toArray();

            return response()->json([
                'prices' => $formattedPrices,
                'total' => count($formattedPrices),
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get Specific Price by ID
     */
    public function getPrice(Request $request, $priceId)
    {
        try {
            $price = Price::retrieve($priceId, ['expand' => ['product']]);
            
            if (!$price->active) {
                return response()->json(['error' => 'Price is not active'], 404);
            }
            
            // Check if product is a string (ID) instead of an object
            $product = $price->product;
            if (is_string($product)) {
                try {
                    $product = Product::retrieve($product);
                    if (!$product->active) {
                        return response()->json(['error' => 'Associated product is not active'], 404);
                    }
                } catch (ApiErrorException $e) {
                    return response()->json(['error' => 'Failed to retrieve product: ' . $e->getMessage()], 400);
                }
            }

            // Ensure product is valid and not deleted
            if (!$product || $product->deleted === true) {
                return response()->json(['error' => 'Associated product is deleted or unavailable'], 404);
            }
            
            return response()->json([
                'price_id' => $price->id,
                'product_name' => $product->name,
                'product_description' => $product->description ?? null,
                'amount' => $price->unit_amount / 100,
                'currency' => $price->currency,
                'interval' => $price->recurring ? $price->recurring->interval : null,
                'interval_count' => $price->recurring ? $price->recurring->interval_count : 1,
                'active' => $price->active,
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
    
    /**
     * Create a Stripe Payment Method
     */
    public function createPaymentMethod(Request $request)
    {
        $request->validate([
            'type' => 'required|string|in:card', // Expand for other types if needed
            'token' => 'required_if:type,card|string', // Use Stripe test token instead of raw card
        ]);

        $user = $request->user();

        // Ensure customer exists
        if (!$user->stripe_customer_id) {
            try {
                $customer = Customer::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'metadata' => ['app_user_id' => $user->id],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);
            } catch (ApiErrorException $e) {
                return response()->json(['error' => 'Failed to create customer: ' . $e->getMessage()], 400);
            }
        }

        try {
            // Create PaymentMethod using token (for testing)
            $paymentMethod = StripePaymentMethod::create([
                'type' => $request->type,
                'card' => ['token' => $request->token],
            ]);

            // Attach to customer
            $paymentMethod->attach(['customer' => $user->stripe_customer_id]);

            // Store in local DB
            PaymentMethod::create([
                'user_id' => $user->id,
                'stripe_payment_method_id' => $paymentMethod->id,
                'type' => $paymentMethod->type,
                'last_four' => $paymentMethod->card ? $paymentMethod->card->last4 : null,
                'brand' => $paymentMethod->card ? $paymentMethod->card->brand : null,
                'is_default' => false,
            ]);

            return response()->json([
                'message' => 'Payment method created and attached! Ready to roll? 😎',
                'payment_method_id' => $paymentMethod->id,
                'details' => [
                    'type' => $paymentMethod->type,
                    'last_four' => $paymentMethod->card ? $paymentMethod->card->last4 : null,
                    'brand' => $paymentMethod->card ? $paymentMethod->card->brand : null,
                    'exp_month' => $paymentMethod->card ? $paymentMethod->card->exp_month : null,
                    'exp_year' => $paymentMethod->card ? $paymentMethod->card->exp_year : null,
                ],
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => 'Failed to create payment method: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Create a Setup Intent for Secure Payment Method Collection
     */
    public function createSetupIntent(Request $request)
    {
        $user = $request->user();

        // Ensure customer exists
        if (!$user->stripe_customer_id) {
            try {
                $customer = Customer::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'metadata' => ['app_user_id' => $user->id],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);
            } catch (ApiErrorException $e) {
                return response()->json(['error' => 'Failed to create customer: ' . $e->getMessage()], 400);
            }
        }

        try {
            $setupIntent = SetupIntent::create([
                'customer' => $user->stripe_customer_id,
                'payment_method_types' => ['card'], // Add more types as needed
                'usage' => 'off_session', // For subscriptions or future payments
            ]);

            return response()->json([
                'message' => 'Setup intent created! Let’s add that card securely! 😎',
                'client_secret' => $setupIntent->client_secret,
                'setup_intent_id' => $setupIntent->id,
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => 'Failed to create setup intent: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Confirm Setup Intent (Optional, if server-side confirmation needed)
     */
    public function confirmSetupIntent(Request $request)
    {
        $request->validate([
            'setup_intent_id' => 'required|string',
            'payment_method_id' => 'nullable|string', // Optional, but required for requires_payment_method
        ]);

        $user = $request->user();

        try {
            $setupIntent = SetupIntent::retrieve($request->setup_intent_id);

            // Ensure SetupIntent belongs to the user's customer
            if ($setupIntent->customer !== $user->stripe_customer_id) {
                return response()->json(['error' => 'Setup intent does not belong to this user'], 403);
            }

            // Handle requires_payment_method status
            if ($setupIntent->status === 'requires_payment_method') {
                if (!$request->payment_method_id) {
                    $defaultPaymentMethod = $user->paymentMethods()->where('is_default', true)->first();
                    if (!$defaultPaymentMethod) {
                        return response()->json([
                            'error' => 'Setup intent requires a payment method. Please provide payment_method_id or set a default payment method.'
                        ], 400);
                    }
                    $request->merge(['payment_method_id' => $defaultPaymentMethod->stripe_payment_method_id]);
                }

                // Verify payment method belongs to customer
                $paymentMethod = StripePaymentMethod::retrieve($request->payment_method_id);
                if ($paymentMethod->customer !== $user->stripe_customer_id) {
                    $paymentMethod->attach(['customer' => $user->stripe_customer_id]);
                }

                // Attach payment method to SetupIntent
                SetupIntent::update($request->setup_intent_id, [
                    'payment_method' => $request->payment_method_id,
                    'customer' => $user->stripe_customer_id, // Reinforce customer
                ]);

                // Confirm the SetupIntent
                $setupIntent->confirm();
            } elseif ($setupIntent->status === 'requires_confirmation') {
                // Confirm if already has payment method
                $setupIntent->confirm();
            }

            // Save payment method if SetupIntent is successful
            if ($setupIntent->status === 'succeeded' && $setupIntent->payment_method) {
                $paymentMethod = StripePaymentMethod::retrieve($setupIntent->payment_method);
                if ($paymentMethod->customer !== $user->stripe_customer_id) {
                    $paymentMethod->attach(['customer' => $user->stripe_customer_id]);
                }

                if (!$user->paymentMethods()->where('stripe_payment_method_id', $paymentMethod->id)->exists()) {
                    PaymentMethod::create([
                        'user_id' => $user->id,
                        'stripe_payment_method_id' => $paymentMethod->id,
                        'type' => $paymentMethod->type,
                        'last_four' => $paymentMethod->card ? $paymentMethod->card->last4 : null,
                        'brand' => $paymentMethod->card ? $paymentMethod->card->brand : null,
                        'is_default' => $user->paymentMethods()->count() === 0, // Default if first
                    ]);
                }

                return response()->json([
                    'message' => 'Payment method confirmed and saved! Ready to pay? 😎',
                    'payment_method_id' => $paymentMethod->id,
                    'details' => [
                        'type' => $paymentMethod->type,
                        'last_four' => $paymentMethod->card ? $paymentMethod->card->last4 : null,
                        'brand' => $paymentMethod->card ? $paymentMethod->card->brand : null,
                        'exp_month' => $paymentMethod->card ? $paymentMethod->card->exp_month : null,
                        'exp_year' => $paymentMethod->card ? $paymentMethod->card->exp_year : null,
                    ],
                ], 200);
            }

            return response()->json(['error' => 'Setup intent not successful: ' . $setupIntent->status], 400);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => 'Failed to confirm setup intent: ' . $e->getMessage()], 400);
        }
    }

    /**
     * List User's Payment Methods
     */
    public function listPaymentMethods(Request $request)
    {
        $user = $request->user();

        if (!$user->stripe_customer_id) {
            return response()->json([
                'error' => 'No customer ID found. Create a customer first!'
            ], 404);
        }

        try {
            $paymentMethods = StripePaymentMethod::all([
                'customer' => $user->stripe_customer_id,
                'type' => 'card',
            ]);

            $formattedMethods = collect($paymentMethods->data)->map(function ($pm) {
                return [
                    'payment_method_id' => $pm->id,
                    'type' => $pm->type,
                    'last_four' => $pm->card ? $pm->card->last4 : null,
                    'brand' => $pm->card ? $pm->card->brand : null,
                    'exp_month' => $pm->card ? $pm->card->exp_month : null,
                    'exp_year' => $pm->card ? $pm->card->exp_year : null,
                    'is_default' => $pm->billing_details->email ?? false,
                ];
            })->toArray();

            return response()->json([
                'payment_methods' => $formattedMethods,
                'total' => count($formattedMethods),
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Attach Payment Method
     */
    public function attachPaymentMethod(Request $request)
    {
        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        $user = $request->user();

        if (!$user->stripe_customer_id) {
            try {
                $customer = Customer::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'metadata' => ['app_user_id' => $user->id],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);
            } catch (ApiErrorException $e) {
                return response()->json([
                    'error' => 'Failed to create customer: ' . $e->getMessage()
                ], 400);
            }
        }

        try {
            $paymentMethod = StripePaymentMethod::retrieve($request->payment_method_id);
            $paymentMethod->attach(['customer' => $user->stripe_customer_id]);
            
            // PaymentMethod create or update using stripe_payment_method_id
            PaymentMethod::updateOrCreate(
                ['stripe_payment_method_id' => $paymentMethod->id],
                [
                    'user_id' => $user->id,
                    'stripe_payment_method_id' => $paymentMethod->id,
                    'type' => $paymentMethod->type,
                    'last_four' => $paymentMethod->card ? $paymentMethod->card->last4 : null,
                    'brand' => $paymentMethod->card ? $paymentMethod->card->brand : null,
                    'is_default' => false,
                ]
            );

            return response()->json([
                'message' => 'Payment method attached successfully',
                'payment_method_id' => $paymentMethod->id,
                'details' => [
                    'type' => $paymentMethod->type,
                    'last_four' => $paymentMethod->card ? $paymentMethod->card->last4 : null,
                    'brand' => $paymentMethod->card ? $paymentMethod->card->brand : null,
                ],
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Detach Payment Method
     */
    public function detachPaymentMethod(Request $request, $paymentMethodId)
    {
        $user = $request->user();

        if (!$user->stripe_customer_id) {
            return response()->json(['error' => 'No customer ID found'], 404);
        }

        $localMethod = PaymentMethod::where('user_id', $user->id)
            ->where('stripe_payment_method_id', $paymentMethodId)
            ->first();

        if (!$localMethod) {
            return response()->json(['error' => 'Payment method not found'], 404);
        }

        try {
            $paymentMethod = StripePaymentMethod::retrieve($paymentMethodId);
            $paymentMethod->detach();

            $localMethod->delete();

            return response()->json([
                'message' => 'Payment method detached successfully',
                'payment_method_id' => $paymentMethodId,
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Set Default Payment Method
     */
    public function setDefaultPaymentMethod(Request $request, $paymentMethodId)
    {
        $user = $request->user();

        if (!$user->stripe_customer_id) {
            return response()->json(['error' => 'No customer ID found'], 404);
        }

        $localMethod = PaymentMethod::where('user_id', $user->id)
            ->where('stripe_payment_method_id', $paymentMethodId)
            ->first();

        if (!$localMethod) {
            return response()->json(['error' => 'Payment method not found'], 404);
        }

        try {
            Customer::update($user->stripe_customer_id, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);

            // Update local DB: clear existing default, set new
            PaymentMethod::where('user_id', $user->id)->update(['is_default' => false]);
            $localMethod->update(['is_default' => true]);

            return response()->json([
                'message' => 'Default payment method set successfully',
                'payment_method_id' => $paymentMethodId,
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Create Subscription
     */
    public function createSubscription(Request $request)
    {
        $request->validate([
            'price_id' => 'required|string',
            'payment_method_id' => 'nullable|string',
        ]);

        $user = $request->user();

        // Ensure customer exists
        if (!$user->stripe_customer_id) {
            try {
                $customer = Customer::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'metadata' => ['app_user_id' => $user->id],
                ]);
                $user->update(['stripe_customer_id' => $customer->id]);
            } catch (ApiErrorException $e) {
                return response()->json(['error' => 'Failed to create customer: ' . $e->getMessage()], 400);
            }
        }

        // Get payment method: from request or default
        $paymentMethodId = $request->payment_method_id;
        if (!$paymentMethodId) {
            $defaultPaymentMethod = $user->paymentMethods()->where('is_default', true)->first();
            if (!$defaultPaymentMethod) {
                return response()->json(['error' => 'No payment method provided and no default payment method found'], 400);
            }
            $paymentMethodId = $defaultPaymentMethod->stripe_payment_method_id;
        }

        try {
            // Verify payment method is attached to customer
            $paymentMethod = StripePaymentMethod::retrieve($paymentMethodId);
            if ($paymentMethod->customer !== $user->stripe_customer_id) {
                $paymentMethod->attach(['customer' => $user->stripe_customer_id]);
            }

            // Create subscription WITHOUT expand to avoid timing issues
            $stripeSubscription = StripeSubscription::create([
                'customer' => $user->stripe_customer_id,
                'items' => [['price' => $request->price_id]],
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => [
                    'payment_method_types' => ['card'],
                    'save_default_payment_method' => 'on_subscription',
                ],
                'default_payment_method' => $paymentMethodId,
            ]);

            // Get period dates from subscription (not items.data[0])
            // Get period dates from items.data[0]
            $currentPeriodStart = null;
            $currentPeriodEnd = null;
            if (!empty($stripeSubscription->items->data) && isset($stripeSubscription->items->data[0])) {
                $currentPeriodStart = $stripeSubscription->items->data[0]->current_period_start;
                $currentPeriodEnd = $stripeSubscription->items->data[0]->current_period_end;
            } else {
                return response()->json(['error' => 'Subscription items not found'], 400);
            }

            // Explicitly retrieve latest_invoice with expanded payment_intent
            $paymentIntentId = null;
            $clientSecret = null;
            $latestInvoiceId = $stripeSubscription->latest_invoice;

            if ($latestInvoiceId) {
                try {
                    // Retrieve and expand the invoice to get payment_intent
                    $invoice = Invoice::retrieve($latestInvoiceId, [
                        'expand' => ['payment_intent']
                    ]);

                    if ($invoice->payment_intent) {
                        $paymentIntent = $invoice->payment_intent;
                        $paymentIntentId = $paymentIntent instanceof \Stripe\PaymentIntent 
                            ? $paymentIntent->id 
                            : $paymentIntent;
                        $clientSecret = $paymentIntent instanceof \Stripe\PaymentIntent 
                            ? $paymentIntent->client_secret 
                            : null;
                    }
                } catch (ApiErrorException $invoiceError) {
                    // Log but don't fail - payment_intent might not be ready yet
                    Log::warning('Failed to retrieve invoice payment_intent', [
                        'subscription_id' => $stripeSubscription->id,
                        'invoice_id' => $latestInvoiceId,
                        'error' => $invoiceError->getMessage()
                    ]);
                }
            }

            // Save subscription to database
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'stripe_subscription_id' => $stripeSubscription->id,
                'stripe_price_id' => $request->price_id,
                'status' => $stripeSubscription->status,
                'current_period_start' => Carbon::createFromTimestamp($currentPeriodStart),
                'current_period_end' => Carbon::createFromTimestamp($currentPeriodEnd),
                'payment_method_id' => $paymentMethodId,
                'latest_invoice_id' => $latestInvoiceId, // Optional: store invoice ID
                'payment_intent_id' => $paymentIntentId, // Optional: store for quick access
            ]);

            // Check if payment confirmation is needed
            if ($stripeSubscription->status === 'incomplete' && $paymentIntentId) {
                return response()->json([
                    'message' => 'Subscription created, but payment needs confirmation! Let’s secure it! 😎',
                    'subscription_id' => $stripeSubscription->id,
                    'payment_intent_id' => $paymentIntentId,
                    'client_secret' => $clientSecret,
                    'status' => $stripeSubscription->status,
                    'invoice_id' => $latestInvoiceId,
                ], 200);
            } elseif ($stripeSubscription->status === 'incomplete') {
                // Fallback: return subscription details, let client poll or use getSubscription
                return response()->json([
                    'message' => 'Subscription created but payment intent not ready. Use GET /subscription to check status! 😎',
                    'subscription_id' => $stripeSubscription->id,
                    'status' => $stripeSubscription->status,
                    'invoice_id' => $latestInvoiceId,
                    'payment_intent_id' => null, // Will be available via GET /subscription
                    'action' => 'poll', // Suggest client polls getSubscription endpoint
                ], 200);
            }

            return response()->json([
                'message' => 'Subscription created successfully! You’re all set! 😎',
                'subscription_id' => $stripeSubscription->id,
                'status' => $stripeSubscription->status,
                'current_period_start' => $subscription->current_period_start->toDateTimeString(),
                'current_period_end' => $subscription->current_period_end->toDateTimeString(),
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => 'Failed to create subscription: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Retrieve Subscription
     */
    public function getSubscription(Request $request, $subscriptionId)
    {
        $user = $request->user();
        $subscription = Subscription::where('user_id', $user->id)
            ->where('stripe_subscription_id', $subscriptionId)
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        try {
            $stripeSubscription = StripeSubscription::retrieve($subscriptionId, [
                'expand' => ['items.data.price.product', 'latest_invoice', 'latest_invoice.payment_intent']
            ]);
            
            // Safely access latest_invoice as an object
            $invoice = $stripeSubscription->latest_invoice;
            $invoiceId = $invoice instanceof \Stripe\Invoice ? $invoice->id : $invoice;
            $paymentIntentId = $invoice instanceof \Stripe\Invoice && $invoice->payment_intent 
                ? ($invoice->payment_intent instanceof \Stripe\PaymentIntent ? $invoice->payment_intent->id : $invoice->payment_intent) 
                : null;

            return response()->json([
                'subscription' => [
                    'id' => $stripeSubscription->id,
                    'status' => $stripeSubscription->status,
                    'invoice_id' => $invoiceId,
                    'payment_intent_id' => $paymentIntentId,
                    'current_period_start' => $stripeSubscription->current_period_start 
                        ? Carbon::createFromTimestamp($stripeSubscription->current_period_start) 
                        : null,
                    'current_period_end' => $stripeSubscription->current_period_end 
                        ? Carbon::createFromTimestamp($stripeSubscription->current_period_end) 
                        : null,
                    'cancel_at' => $stripeSubscription->cancel_at 
                        ? Carbon::createFromTimestamp($stripeSubscription->cancel_at) 
                        : null,
                    'plan' => $stripeSubscription->items->data[0]->price->product->name ?? null,
                    'amount' => $stripeSubscription->items->data[0]->price->unit_amount / 100,
                    'currency' => $stripeSubscription->items->data[0]->price->currency,
                ],
                'local_record' => $subscription,
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Update Subscription (e.g., change plan)
     */
    public function updateSubscription(Request $request, $subscriptionId)
    {
        $request->validate([
            'price_id' => 'required|string',
        ]);

        $user = $request->user();
        $subscription = Subscription::where('user_id', $user->id)
            ->where('stripe_subscription_id', $subscriptionId)
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        try {
            $stripeSubscription = StripeSubscription::retrieve($subscriptionId);
            $stripeSubscription = StripeSubscription::update($subscriptionId, [
                'items' => [
                    [
                        'id' => $stripeSubscription->items->data[0]->id,
                        'price' => $request->price_id,
                    ],
                ],
                'proration_behavior' => 'create_prorations',
            ]);

            $subscription->update([
                'stripe_price_id' => $request->price_id,
                'status' => $stripeSubscription->status,
                'current_period_end' => $stripeSubscription->current_period_end 
                    ? Carbon::createFromTimestamp($stripeSubscription->current_period_end) 
                    : null,
                'cancel_at' => $stripeSubscription->cancel_at 
                    ? Carbon::createFromTimestamp($stripeSubscription->cancel_at) 
                    : null,
            ]);

            return response()->json([
                'message' => 'Subscription updated successfully',
                'subscription_id' => $stripeSubscription->id,
                'status' => $stripeSubscription->status,
                'plan' => $stripeSubscription->items->data[0]->price->product->name ?? null,
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Cancel Subscription
     */
    public function cancelSubscription(Request $request, $subscriptionId)
    {
        $user = $request->user();
        $subscription = Subscription::where('user_id', $user->id)
            ->where('stripe_subscription_id', $subscriptionId)
            ->first();

        if (!$subscription) {
            return response()->json(['error' => 'Subscription not found'], 404);
        }

        try {
            $stripeSubscription = StripeSubscription::retrieve($subscriptionId);
            $stripeSubscription->cancel([
                'prorate' => false,
            ]);

            $subscription->update([
                'status' => $stripeSubscription->status,
                'cancel_at' => $stripeSubscription->cancel_at 
                    ? Carbon::createFromTimestamp($stripeSubscription->cancel_at) 
                    : null,
            ]);

            return response()->json([
                'message' => 'Subscription canceled successfully',
                'subscription_id' => $stripeSubscription->id,
                'status' => $stripeSubscription->status,
                'canceled_at' => now()->toISOString(),
            ], 200);
        } catch (ApiErrorException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}