<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Stripe\Stripe;
use Stripe\Customer;

class AuthController extends Controller
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }
    /**
     * Register a new user (with optional social_id)
     */
    public function register(Request $request)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'social_id' => 'sometimes|string|unique:users,social_id',
        ];

        // Password is required only if social_id is not provided
        if (!$request->has('social_id')) {
            $rules['password'] = 'required|string|min:8|confirmed';
        }

        $request->validate($rules);

        try {
            $customer = Customer::create([
                'name' => $request->name,
                'email' => $request->email,
                'metadata' => [
                    'app_user_id' => '',
                    'created_at' => now()->toISOString(),
                ],
            ]);
            
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->has('password') ? Hash::make($request->password) : null,
                'social_id' => $request->social_id,
                'stripe_customer_id' => $customer->id
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'token' => $token,
                'user' => $user,
                'message' => 'Registration successful',
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Registration failed: ' . $e->getMessage()], 400);
        }
    }

    /**
     * Login user (via email/password or social_id)
     */
    public function login(Request $request)
    {
        $request->validate([
            'social_id' => 'sometimes|string',
            'email' => 'required_without:social_id|string|email',
            'password' => 'required_without:social_id|string',
        ]);

        try {
            $user = null;

            // Try login via social_id if provided
            if ($request->has('social_id')) {
                $user = User::where('social_id', $request->social_id)->first();
                if (!$user) {
                    throw ValidationException::withMessages([
                        'social_id' => ['Invalid social ID.'],
                    ]);
                }
            } else {
                // Fallback to email/password
                $user = User::where('email', $request->email)->first();
                if (!$user || !Hash::check($request->password, $user->password)) {
                    throw ValidationException::withMessages([
                        'email' => ['The provided credentials are incorrect.'],
                    ]);
                }
            }

            $token = $user->createToken('auth_token')->plainTextToken;
            
            if(empty($user->stripe_customer_id)){
                $customer = Customer::create([
                    'name' => $user->name,
                    'email' => $user->email,
                    'metadata' => [
                        'app_user_id' => $user->id,
                        'created_at' => now()->toISOString(),
                    ],
                ]);
                $user->stripe_customer_id = $customer->id;
                $user->save();
            }

            return response()->json([
                'token' => $token,
                'user' => $user,
                'message' => 'Login successful',
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
