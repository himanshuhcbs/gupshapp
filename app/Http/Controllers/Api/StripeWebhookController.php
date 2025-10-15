<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\PaymentIntent;
use Stripe\Exception\SignatureVerificationException;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StripeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $endpoint_secret = config('services.stripe.webhook_secret');

        $payload = @file_get_contents('php://input');
        $sig_header = $request->header('Stripe-Signature');

        try {
            $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe Webhook Invalid Payload: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe Webhook Invalid Signature: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe Webhook Processing Error: ' . $e->getMessage());
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }

        switch ($event->type) {
            case 'payment_intent.created':
                // $this->handlePaymentIntentCreated($event->data->object);
                break;
            case 'payment_intent.succeeded':
                $this->handlePaymentIntentSucceeded($event->data->object);
                break;

            case 'payment_intent.payment_failed':
                $this->handlePaymentIntentFailed($event->data->object);
                break;

            case 'invoice.paid':
                $this->handleInvoicePaid($event->data->object);
                break;

            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event->data->object);
                break;

            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event->data->object);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event->data->object);
                break;

            case 'payment_method.attached':
                $this->handlePaymentMethodAttached($event->data->object);
                break;

            case 'payment_method.detached':
                $this->handlePaymentMethodDetached($event->data->object);
                break;

            case 'customer.subscription.created':
                $this->handleSubscriptionCreated($event->data->object);
                break;

            default:
                Log::info('Unhandled Stripe Event: ' . $event->type, ['payload' => $event->data->object]);
                break;
        }

        return response()->json(['message' => 'Webhook handled successfully'], 200);
    }
    
    /**
     * Handle Payment Intent Created
     */
    private function handlePaymentIntentCreated($paymentIntent)
    {
        Payment::create([
            'user_id' => $paymentIntent->metadata->user_id,
            'amount' => $paymentIntent->amount,
            'currency' => $paymentIntent->currency,
            'stripe_payment_id' => $paymentIntent->id,
            'status' => $paymentIntent->status,
            'payment_method_type' => $paymentIntent->payment_method_types[0] ?? null,
        ]);
        
        Log::info('Payment Intent Created', [
            'payment_id' => $paymentIntent->id,
            'stripe_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
        ]);
    }

    /**
     * Handle Payment Intent Succeeded
     */
    private function handlePaymentIntentSucceeded($paymentIntent)
    {
        $payment = Payment::where('stripe_payment_id', $paymentIntent->id)->first();
        if ($payment) {
            $payment->update([
                'status' => 'succeeded',
                'payment_method_type' => $paymentIntent->payment_method_types[0] ?? null,
            ]);
            
            Log::info('Payment Intent Succeeded', [
                'payment_id' => $payment->id,
                'stripe_id' => $paymentIntent->id,
                'amount' => $payment->amount,
            ]);
        }
    }

    /**
     * Handle Payment Intent Failed
     */
    private function handlePaymentIntentFailed($paymentIntent)
    {
        $payment = Payment::where('stripe_payment_id', $paymentIntent->id)->first();
        if ($payment) {
            $payment->update([
                'status' => 'failed',
                'payment_method_type' => $paymentIntent->payment_method_types[0] ?? null,
            ]);
            
            Log::warning('Payment Intent Failed', [
                'payment_id' => $payment->id,
                'stripe_id' => $paymentIntent->id,
                'last_payment_error' => $paymentIntent->last_payment_error->message ?? null,
            ]);
        }
    }

    /**
     * Handle Invoice Paid
     */
    private function handleInvoicePaid($invoice)
    {
        $subscriptionId = $invoice->subscription;
        $subscription = Subscription::where('stripe_subscription_id', $subscriptionId)->first();
        
        if ($subscription) {
            $subscription->update([
                'status' => 'active',
                'current_period_end' => Carbon::createFromTimestamp($invoice->period_end),
            ]);

            // Record payment
            if ($invoice->payment_intent) {
                $paymentIntent = PaymentIntent::retrieve($invoice->payment_intent);
                Payment::create([
                    'user_id' => $subscription->user_id,
                    'stripe_payment_id' => $invoice->payment_intent,
                    'status' => 'succeeded',
                    'amount' => $invoice->amount_paid / 100,
                    'currency' => $invoice->currency,
                    'payment_method_type' => $paymentIntent->payment_method_types[0] ?? null,
                ]);
            }

            Log::info('Invoice Paid', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoice->id,
                'amount' => $invoice->amount_paid / 100,
            ]);
        }
    }

    /**
     * Handle Invoice Payment Failed
     */
    private function handleInvoicePaymentFailed($invoice)
    {
        $subscriptionId = $invoice->subscription;
        $subscription = Subscription::where('stripe_subscription_id', $subscriptionId)->first();
        
        if ($subscription) {
            $subscription->update(['status' => 'past_due']);
            
            Log::warning('Invoice Payment Failed', [
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoice->id,
                'last_payment_error' => $invoice->last_payment_error->message ?? null,
            ]);
        }
    }

    /**
     * Handle Subscription Updated
     */
    private function handleSubscriptionUpdated($subscription)
    {
        $localSubscription = Subscription::where('stripe_subscription_id', $subscription->id)->first();
        
        if ($localSubscription) {
            $localSubscription->update([
                'status' => $subscription->status,
                'current_period_end' => $subscription->current_period_end 
                    ? Carbon::createFromTimestamp($subscription->current_period_end) 
                    : null,
                'cancel_at' => $subscription->cancel_at 
                    ? Carbon::createFromTimestamp($subscription->cancel_at) 
                    : null,
            ]);

            Log::info('Subscription Updated', [
                'subscription_id' => $subscription->id,
                'new_status' => $subscription->status,
                'plan' => $subscription->items->data[0]->price->id ?? null,
            ]);
        }
    }

    /**
     * Handle Subscription Deleted
     */
    private function handleSubscriptionDeleted($subscription)
    {
        $localSubscription = Subscription::where('stripe_subscription_id', $subscription->id)->first();
        
        if ($localSubscription) {
            $localSubscription->update([
                'status' => $subscription->status,
                'cancel_at' => $subscription->cancel_at 
                    ? Carbon::createFromTimestamp($subscription->cancel_at) 
                    : null,
            ]);

            Log::info('Subscription Deleted', [
                'subscription_id' => $subscription->id,
                'canceled_at' => $subscription->canceled_at 
                    ? Carbon::createFromTimestamp($subscription->canceled_at) 
                    : null,
            ]);
        }
    }

    /**
     * Handle Payment Method Attached
     */
    private function handlePaymentMethodAttached($paymentMethod)
    {
        $user = User::where('stripe_customer_id', $paymentMethod->customer)->first();
        
        if ($user && !$user->paymentMethods()->where('stripe_payment_method_id', $paymentMethod->id)->exists()) {
            PaymentMethod::create([
                'user_id' => $user->id,
                'stripe_payment_method_id' => $paymentMethod->id,
                'type' => $paymentMethod->type,
                'last_four' => $paymentMethod->card ? $paymentMethod->card->last4 : null,
                'brand' => $paymentMethod->card ? $paymentMethod->card->brand : null,
                'is_default' => false,
            ]);

            Log::info('Payment Method Attached', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethod->id,
                'type' => $paymentMethod->type,
            ]);
        }
    }

    /**
     * Handle Payment Method Detached
     */
    private function handlePaymentMethodDetached($paymentMethod)
    {
        $localMethod = PaymentMethod::where('stripe_payment_method_id', $paymentMethod->id)->first();
        
        if ($localMethod) {
            $localMethod->delete();

            Log::info('Payment Method Detached', [
                'payment_method_id' => $paymentMethod->id,
                'user_id' => $localMethod->user_id,
            ]);
        }
    }

    /**
     * Handle Subscription Created
     */
    private function handleSubscriptionCreated($subscription)
    {
        $user = User::where('stripe_customer_id', $subscription->customer)->first();
        
        if ($user && !Subscription::where('stripe_subscription_id', $subscription->id)->exists()) {
            Subscription::create([
                'user_id' => $user->id,
                'stripe_subscription_id' => $subscription->id,
                'stripe_price_id' => $subscription->items->data[0]->price->id,
                'status' => $subscription->status,
                'current_period_end' => $subscription->current_period_end 
                    ? Carbon::createFromTimestamp($subscription->current_period_end) 
                    : null,
                'cancel_at' => $subscription->cancel_at 
                    ? Carbon::createFromTimestamp($subscription->cancel_at) 
                    : null,
            ]);

            Log::info('Subscription Created', [
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'status' => $subscription->status,
            ]);
        }
    }
}