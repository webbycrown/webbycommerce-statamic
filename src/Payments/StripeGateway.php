<?php

namespace WebbyCrown\WebbyCommerceStatamic\Payments;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use WebbyCrown\WebbyCommerceStatamic\Helpers\CurrencyHelper;
use WebbyCrown\WebbyCommerceStatamic\Orders\EntryOrderRepository;

class StripeGateway
{
    /**
     * Confirm a card payment with Payment Intents.
     * The order is paid only when Stripe reports status succeeded for this amount.
     *
     * @param  array<string, mixed>  $paymentData
     * @return array<string, mixed>
     */
    public function resolveCard(array $paymentData, float $total, string $orderNumber, string $customerEmail): array
    {
        $secret = config('webbycommerce.payment.gateways.stripe.secret_key');

        if (! $secret) {
            return $this->fail('Stripe is not configured. Set STRIPE_SECRET_KEY in your environment. Orders are not marked paid without a verified charge.', 422);
        }

        $expected = CurrencyHelper::stripeAmount($total);
        $currency = CurrencyHelper::stripeCurrency();

        if ($expected <= 0) {
            return [
                'ok' => true,
                'is_paid' => true,
                'payment_status' => 'paid',
                'message' => null,
                'http_status' => 200,
            ];
        }

        $intentId = isset($paymentData['payment_intent']) ? (string) $paymentData['payment_intent'] : '';
        $paymentMethodId = isset($paymentData['stripe_payment_method']) ? (string) $paymentData['stripe_payment_method'] : '';

        try {
            if ($intentId !== '') {
                if (! preg_match('/^pi_[A-Za-z0-9_]+$/', $intentId)) {
                    return $this->fail('The PaymentIntent id is not valid.', 422);
                }

                if (app(EntryOrderRepository::class)->findByPaymentIntent($intentId)) {
                    return $this->fail('This payment has already been used for an order.', 422);
                }

                $response = Http::withBasicAuth($secret, '')
                    ->acceptJson()
                    ->get('https://api.stripe.com/v1/payment_intents/'.$intentId);
            } elseif ($paymentMethodId !== '') {
                if (! preg_match('/^pm_[A-Za-z0-9_]+$/', $paymentMethodId)) {
                    return $this->fail('The PaymentMethod id is not valid.', 422);
                }

                $response = Http::asForm()
                    ->withBasicAuth($secret, '')
                    ->withHeaders(['Idempotency-Key' => $this->idempotencyKey()])
                    ->post('https://api.stripe.com/v1/payment_intents', [
                        'amount' => $expected,
                        'currency' => $currency,
                        'payment_method' => $paymentMethodId,
                        'confirm' => 'true',
                        'confirmation_method' => 'automatic',
                        'use_stripe_sdk' => 'true',
                        'return_url' => route('shop.checkout.payment.callback'),
                        'description' => 'Order '.$orderNumber,
                        'receipt_email' => $customerEmail,
                        'metadata' => [
                            'order_number' => $orderNumber,
                        ],
                    ]);
            } else {
                return $this->fail('A Stripe PaymentMethod or PaymentIntent is required. Card numbers must not be posted to the server.', 422);
            }
        } catch (\Exception $e) {
            Log::error('Stripe API payment failed: '.$e->getMessage());

            return $this->fail('Payment processor error. Please try again.', 500);
        }

        if ($response->failed()) {
            return $this->fail($response->json('error.message') ?? 'Stripe payment failed.', 400);
        }

        return $this->interpret($response->json(), $expected, $currency);
    }

    /**
     * @return array{ok:bool,event?:array<string,mixed>,message?:string,http_status?:int}
     */
    public function verifiedEvent(string $payload, ?string $signature): array
    {
        $secret = config('webbycommerce.payment.gateways.stripe.webhook_secret');

        if (! $secret) {
            return [
                'ok' => false,
                'message' => 'Stripe webhook secret is not configured.',
                'http_status' => 503,
            ];
        }

        if (! $signature || ! $this->verifySignature($payload, $signature, $secret)) {
            return [
                'ok' => false,
                'message' => 'Invalid webhook signature.',
                'http_status' => 401,
            ];
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            return [
                'ok' => false,
                'message' => 'Invalid webhook payload.',
                'http_status' => 400,
            ];
        }

        return [
            'ok' => true,
            'event' => $event,
        ];
    }

    /**
     * @param  array<string, mixed>  $paymentResult
     */
    public function refund(array $paymentResult): void
    {
        if (! ($paymentResult['is_paid'] ?? false)) {
            return;
        }

        $intentId = $paymentResult['payment_intent'] ?? null;
        $secret = config('webbycommerce.payment.gateways.stripe.secret_key');

        Log::error('Order was not saved after a successful Stripe payment.', [
            'payment_intent' => $intentId,
        ]);

        if (! $intentId || ! $secret) {
            return;
        }

        try {
            $refund = Http::asForm()
                ->withBasicAuth($secret, '')
                ->post('https://api.stripe.com/v1/refunds', [
                    'payment_intent' => $intentId,
                ]);

            if ($refund->failed()) {
                Log::error('Stripe refund failed after the order could not be saved.', [
                    'payment_intent' => $intentId,
                    'status' => $refund->status(),
                ]);

                return;
            }

            Log::info('Stripe payment refunded because the order could not be saved.', [
                'payment_intent' => $intentId,
                'refund_id' => $refund->json('id'),
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe refund failed after the order could not be saved.', [
                'payment_intent' => $intentId,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $intent
     * @return array<string, mixed>
     */
    public function interpret(?array $intent, int $expected, string $currency): array
    {
        $status = (string) ($intent['status'] ?? '');
        $amount = (int) ($intent['amount'] ?? -1);
        $intentCurrency = strtolower((string) ($intent['currency'] ?? ''));
        $id = $intent['id'] ?? null;
        $charge = $intent['latest_charge'] ?? null;

        if ($amount !== $expected || $intentCurrency !== strtolower($currency)) {
            Log::error('Stripe PaymentIntent amount did not match the order.', [
                'payment_intent' => $id,
                'expected' => $expected,
                'amount' => $amount,
                'currency' => $intentCurrency,
            ]);

            return $this->fail('The payment amount does not match this order.', 422);
        }

        if ($status === 'succeeded') {
            return [
                'ok' => true,
                'is_paid' => true,
                'payment_status' => 'paid',
                'payment_intent' => $id,
                'charge_id' => is_string($charge) ? $charge : null,
                'message' => null,
                'http_status' => 200,
            ];
        }

        if ($status === 'requires_action' || $status === 'requires_source_action') {
            return [
                'ok' => false,
                'requires_action' => true,
                'is_paid' => false,
                'payment_status' => 'pending',
                'payment_intent' => $id,
                'client_secret' => $intent['client_secret'] ?? null,
                'message' => 'Additional authentication is required.',
                'http_status' => 200,
            ];
        }

        $message = $intent['last_payment_error']['message'] ?? 'The payment was not completed.';

        return $this->fail($message, 400);
    }

    public function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, null);
            if ($k && $v !== null) {
                $parts[$k][] = $v;
            }
        }

        $timestamp = $parts['t'][0] ?? null;
        $signatures = $parts['v1'] ?? [];

        if (! $timestamp || $signatures === []) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fail(string $message, int $status): array
    {
        // Drop the key so a later card attempt is not locked to the failed request's parameters.
        $this->forgetIdempotencyKey();

        return [
            'ok' => false,
            'is_paid' => false,
            'payment_status' => 'pending',
            'message' => $message,
            'http_status' => $status,
        ];
    }

    protected function idempotencyKey(): string
    {
        $key = Session::get('checkout.stripe_idempotency');

        if (! is_string($key) || $key === '') {
            $key = (string) Str::uuid();
            Session::put('checkout.stripe_idempotency', $key);
        }

        return 'wc-'.$key;
    }

    protected function forgetIdempotencyKey(): void
    {
        Session::forget('checkout.stripe_idempotency');
    }
}
