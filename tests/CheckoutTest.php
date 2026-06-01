<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Config;
use Statamic\Facades\Entry;

class CheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Prevent real mail delivery
        \Illuminate\Support\Facades\Mail::fake();

        // Create default tax rate so Cart doesn't query database or fail
        Config::set('webbycommerce.tax.rate', 0.1);

        // Pre-populate cart items directly in session
        Session::put('cart', [
            'items' => [
                'test_key' => [
                    'product_id' => 'test-product-id',
                    'name' => 'Test Product',
                    'slug' => 'test-product',
                    'sku' => 'TEST-SKU',
                    'price' => 50.00,
                    'image' => null,
                    'quantity' => 2,
                    'options' => [],
                ]
            ],
            'coupon' => null,
        ]);

        // Pre-populate checkout session data
        Session::put('checkout', [
            'address' => [
                'email' => 'john@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '1234567890',
                'shipping_same_as_billing' => true,
                'billing_address' => [
                    'street' => '123 Main St',
                    'line2' => '',
                    'city' => 'New York',
                    'state' => 'NY',
                    'postal_code' => '10001',
                    'country' => 'USA',
                ],
            ],
            'shipping' => [
                'shipping_method' => 'standard',
                'shipping_cost' => 10.00,
            ],
        ]);
    }

    public function test_checkout_complete_stripe_simulated_success_when_no_secret(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', null);

        $payload = [
            'payment_method' => 'stripe',
            'card_name' => 'John Doe',
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/28',
            'card_cvv' => '123',
        ];

        $response = $this->postJson(route('shop.checkout.complete'), $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('order.order_number'));
    }

    public function test_checkout_complete_stripe_api_success(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        Http::fake([
            'https://api.stripe.com/v1/tokens' => Http::response([
                'id' => 'tok_mock_token_id',
                'object' => 'token',
            ], 200),
            'https://api.stripe.com/v1/charges' => Http::response([
                'id' => 'ch_mock_charge_id',
                'object' => 'charge',
                'paid' => true,
            ], 200),
        ]);

        $payload = [
            'payment_method' => 'stripe',
            'card_name' => 'John Doe',
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/28',
            'card_cvv' => '123',
        ];

        $response = $this->postJson(route('shop.checkout.complete'), $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/tokens' &&
                $request['card']['number'] === '4242424242424242' &&
                $request['card']['exp_month'] === '12' &&
                $request['card']['exp_year'] === '2028' &&
                $request['card']['cvc'] === '123';
        });

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/charges' &&
                $request['source'] === 'tok_mock_token_id' &&
                (int)$request['amount'] === 11000; // subtotal 100 + shipping 0 + tax 10 = 110 * 100 = 11000
        });
    }

    public function test_checkout_complete_stripe_token_failure(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        Http::fake([
            'https://api.stripe.com/v1/tokens' => Http::response([
                'error' => [
                    'message' => 'Your card number is incorrect.',
                    'type' => 'card_error',
                ]
            ], 402),
        ]);

        $payload = [
            'payment_method' => 'stripe',
            'card_name' => 'John Doe',
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/28',
            'card_cvv' => '123',
        ];

        $response = $this->postJson(route('shop.checkout.complete'), $payload);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Your card number is incorrect.');
    }

    public function test_checkout_complete_stripe_charge_failure(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        Http::fake([
            'https://api.stripe.com/v1/tokens' => Http::response([
                'id' => 'tok_mock_token_id',
                'object' => 'token',
            ], 200),
            'https://api.stripe.com/v1/charges' => Http::response([
                'error' => [
                    'message' => 'Your card has insufficient funds.',
                    'type' => 'card_error',
                ]
            ], 402),
        ]);

        $payload = [
            'payment_method' => 'stripe',
            'card_name' => 'John Doe',
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/28',
            'card_cvv' => '123',
        ];

        $response = $this->postJson(route('shop.checkout.complete'), $payload);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Your card has insufficient funds.');
    }

    public function test_checkout_complete_stripe_accepts_four_digit_expiry_year(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        Http::fake([
            'https://api.stripe.com/v1/tokens' => Http::response([
                'id' => 'tok_mock_token_id',
                'object' => 'token',
            ], 200),
            'https://api.stripe.com/v1/charges' => Http::response([
                'id' => 'ch_mock_charge_id',
                'object' => 'charge',
                'paid' => true,
            ], 200),
        ]);

        $payload = [
            'payment_method' => 'stripe',
            'card_name' => 'John Doe',
            'card_number' => '4242 4242 4242 4242',
            'card_expiry' => '12/2028',
            'card_cvv' => '123',
        ];

        $response = $this->postJson(route('shop.checkout.complete'), $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/tokens' &&
                $request['card']['exp_year'] === '2028';
        });
    }
}
