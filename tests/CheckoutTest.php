<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Config;

class CheckoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Mail::fake();

        Config::set('webbycommerce.tax.rate', 0.1);

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

        \Statamic\Facades\Entry::make()
            ->collection('products')
            ->id('test-product-id')
            ->slug('test-product')
            ->published(true)
            ->data([
                'title' => 'Test Product',
                'price' => 50,
                'sku' => 'TEST-SKU',
                'status' => 'active',
                'track_stock' => false,
                'stock' => 100,
            ])
            ->save();
    }

    public function test_checkout_complete_stripe_fails_closed_when_no_secret(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', null);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_mock_token_id',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('Stripe is not configured', $response->json('message'));
    }

    public function test_checkout_rejects_raw_card_fields(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_mock_token_id',
            'card_number' => '4242 4242 4242 4242',
            'card_cvv' => '123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('must not be sent to the server', $response->json('message'));
    }

    public function test_checkout_requires_stripe_token(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
        ]);

        $response->assertStatus(422);
    }

    public function test_checkout_complete_paypal_fails_closed(): void
    {
        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'paypal',
            'paypal_email' => 'buyer@example.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('PayPal capture is not available', $response->json('message'));
    }

    public function test_checkout_complete_stripe_api_success(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        Http::fake([
            'https://api.stripe.com/v1/charges' => Http::response([
                'id' => 'ch_mock_charge_id',
                'object' => 'charge',
                'paid' => true,
            ], 200),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_mock_token_id',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/charges' &&
                $request['source'] === 'tok_mock_token_id' &&
                (int) $request['amount'] === 10000;
        });
    }

    public function test_checkout_complete_stripe_charge_failure(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        Http::fake([
            'https://api.stripe.com/v1/charges' => Http::response([
                'error' => [
                    'message' => 'Your card has insufficient funds.',
                    'type' => 'card_error',
                ]
            ], 402),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_mock_token_id',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Your card has insufficient funds.');
    }

    public function test_process_checkout_rechecks_coupon_after_items_are_removed(): void
    {
        \Statamic\Facades\Entry::make()
            ->collection('coupons')
            ->slug('save50')
            ->published(true)
            ->data([
                'title' => 'SAVE50',
                'code' => 'SAVE50',
                'type' => 'fixed',
                'value' => 50,
                'minimum_amount' => 80,
            ])
            ->save();

        Session::put('checkout.coupon', [
            'code' => 'SAVE50',
            'discount' => 50,
        ]);

        $cart = Session::get('cart');
        $cart['items']['test_key']['quantity'] = 1;
        Session::put('cart', $cart);

        $ordersBefore = \Statamic\Facades\Entry::query()->where('collection', 'orders')->count();

        $response = $this->post(route('shop.checkout.process'), $this->bankTransferCheckout());

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Minimum order amount', session('error'));
        $this->assertNull(Session::get('checkout.coupon'));
        $this->assertSame($ordersBefore, \Statamic\Facades\Entry::query()->where('collection', 'orders')->count());
    }

    public function test_process_checkout_recalculates_a_smaller_cart_discount(): void
    {
        \Statamic\Facades\Entry::make()
            ->collection('coupons')
            ->slug('take20')
            ->published(true)
            ->data([
                'title' => 'TAKE20',
                'code' => 'TAKE20',
                'type' => 'percentage',
                'value' => 20,
                'minimum_amount' => 0,
            ])
            ->save();

        Session::put('checkout.coupon', [
            'code' => 'TAKE20',
            'discount' => 20,
        ]);

        $cart = Session::get('cart');
        $cart['items']['test_key']['quantity'] = 1;
        Session::put('cart', $cart);

        $response = $this->post(route('shop.checkout.process'), $this->bankTransferCheckout());

        $response->assertRedirect();
        $response->assertSessionMissing('error');

        $order = \Statamic\Facades\Entry::query()
            ->where('collection', 'orders')
            ->get()
            ->first(fn ($entry) => $entry->get('coupon') === 'TAKE20');
        $this->assertNotNull($order);
        $this->assertEquals(10.0, (float) $order->get('discount'));
        $this->assertEquals(10.0, (float) $order->get('coupon_total'));
    }

    private function bankTransferCheckout(): array
    {
        return [
            'email' => 'john@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '1234567890',
            'shipping_same_as_billing' => '1',
            'payment_method' => 'bank_transfer',
            'bank_sender_name' => 'John Doe',
            'shipping_method' => 'standard',
            'billing_address' => [
                'street' => '123 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'country' => 'USA',
            ],
        ];
    }

    public function test_checkout_complete_empty_cart_error_state(): void
    {
        Session::put('cart', ['items' => [], 'coupon' => null]);
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_mock_token_id',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }
}
