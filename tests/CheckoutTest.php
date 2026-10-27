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

    public function test_checkout_refunds_a_charge_when_the_order_cannot_be_saved(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        $entry = \Mockery::mock(\Statamic\Entries\Entry::class);
        $entry->shouldReceive('slug')->andReturnSelf();
        $entry->shouldReceive('data')->andReturnSelf();
        $entry->shouldReceive('published')->andReturnSelf();
        $entry->shouldReceive('save')->andReturn(false);

        $orders = \Mockery::mock(\WebbyCrown\WebbyCommerceStatamic\Orders\EntryOrderRepository::class)->makePartial();
        $orders->shouldReceive('make')->andReturn($entry);
        $this->app->instance(\WebbyCrown\WebbyCommerceStatamic\Orders\EntryOrderRepository::class, $orders);

        Http::fake([
            'https://api.stripe.com/v1/charges' => Http::response([
                'id' => 'ch_orphan',
                'object' => 'charge',
                'paid' => true,
            ], 200),
            'https://api.stripe.com/v1/refunds' => Http::response([
                'id' => 're_orphan',
                'object' => 'refund',
                'charge' => 'ch_orphan',
            ], 200),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_mock_token_id',
        ]);

        $response->assertStatus(500);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/refunds'
                && $request['charge'] === 'ch_orphan';
        });
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

    public function test_checkout_complete_charges_the_session_coupon_when_the_request_omits_it(): void
    {
        $this->saveCoupon('tenoff', 'TENOFF', 'fixed', 10);
        Session::put('checkout.coupon', [
            'code' => 'TENOFF',
            'discount' => 10,
        ]);

        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        Http::fake([
            'https://api.stripe.com/v1/charges' => Http::response([
                'id' => 'ch_coupon',
                'object' => 'charge',
                'paid' => true,
            ], 200),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_mock_token_id',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/charges'
                && (int) $request['amount'] === 9000;
        });
    }

    public function test_checkout_complete_caps_a_fixed_coupon_at_the_subtotal(): void
    {
        $this->saveCoupon('huge', 'HUGE', 'fixed', 500);
        Session::put('checkout.coupon', [
            'code' => 'HUGE',
            'discount' => 500,
        ]);

        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        Http::fake([
            'https://api.stripe.com/v1/charges' => Http::response([
                'id' => 'ch_capped',
                'object' => 'charge',
                'paid' => true,
            ], 200),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_mock_token_id',
        ]);

        $response->assertOk();
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/charges'
                && (int) $request['amount'] >= 0
                && (int) $request['amount'] === 0;
        });
    }

    public function test_product_listing_does_not_publish_cost_price(): void
    {
        \Statamic\Facades\Entry::make()
            ->collection('products')
            ->slug('margin-product')
            ->published(true)
            ->data([
                'title' => 'Margin Product',
                'price' => 20,
                'cost_price' => 5,
                'status' => 'active',
            ])
            ->save();

        $response = $this->getJson(route('shop.products.index'));

        $response->assertOk();
        $product = collect($response->json('products'))->firstWhere('slug', 'margin-product');
        $this->assertNotNull($product);
        $this->assertArrayNotHasKey('cost_price', $product);
        $this->assertSame(20.0, (float) $product['price']);
    }

    private function saveCoupon(string $slug, string $code, string $type, float $value): void
    {
        \Statamic\Facades\Entry::make()
            ->collection('coupons')
            ->slug($slug)
            ->published(true)
            ->data([
                'title' => $code,
                'code' => $code,
                'type' => $type,
                'value' => $value,
                'minimum_amount' => 0,
            ])
            ->save();
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
