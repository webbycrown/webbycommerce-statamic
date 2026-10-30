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
        $this->resetStoreSettings();

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
            'stripe_payment_method' => 'pm_mock_token_id',
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
            'stripe_payment_method' => 'pm_mock_token_id',
            'card_number' => '4242 4242 4242 4242',
            'card_cvv' => '123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('must not be sent to the server', $response->json('message'));
    }

    public function test_checkout_requires_stripe_payment_method(): void
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
        $this->assertStringContainsString('Unsupported payment method', $response->json('message'));
    }

    public function test_checkout_complete_stripe_api_success(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        $this->fakeSuccessfulPaymentIntent();

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_mock_token_id',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/payment_intents' &&
                $request['payment_method'] === 'pm_mock_token_id' &&
                (int) $request['amount'] === 10000;
        });
    }

    public function test_checkout_complete_stripe_charge_failure(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => Http::response([
                'error' => [
                    'message' => 'Your card has insufficient funds.',
                    'type' => 'card_error',
                ]
            ], 402),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_mock_token_id',
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
            'https://api.stripe.com/v1/payment_intents' => function ($request) {
                return Http::response([
                    'id' => 'pi_orphan',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => (int) $request['amount'],
                    'currency' => (string) $request['currency'],
                    'client_secret' => 'pi_orphan_secret',
                    'latest_charge' => 'ch_orphan',
                ], 200);
            },
            'https://api.stripe.com/v1/refunds' => Http::response([
                'id' => 're_orphan',
                'object' => 'refund',
                'payment_intent' => 'pi_orphan',
            ], 200),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_mock_token_id',
        ]);

        $response->assertStatus(500);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/refunds'
                && $request['payment_intent'] === 'pi_orphan';
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
        $this->fakeSuccessfulPaymentIntent();

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_mock_token_id',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/payment_intents'
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
        Http::fake();

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_mock_token_id',
        ]);

        $response->assertOk();
        Http::assertNothingSent();
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
            'stripe_payment_method' => 'pm_mock_token_id',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    public function test_stripe_charge_uses_minor_units_for_usd_and_jpy(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        $this->fakeSuccessfulPaymentIntent();

        $this->setCartTotal(10);
        $this->setStoreCurrency('USD');

        $usd = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_usd',
        ]);
        $usd->assertOk();

        $this->setCartTotal(1000);
        $this->setStoreCurrency('JPY');
        Session::put('checkout', [
            'address' => [
                'email' => 'john@example.com',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '1234567890',
                'shipping_same_as_billing' => true,
                'billing_address' => [
                    'street' => '123 Main St',
                    'city' => 'New York',
                    'state' => 'NY',
                    'postal_code' => '10001',
                    'country' => 'USA',
                ],
            ],
            'shipping' => [
                'shipping_method' => 'standard',
                'shipping_cost' => 0,
            ],
        ]);

        $jpy = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_jpy',
        ]);
        $jpy->assertOk();

        $payloads = [];
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) use (&$payloads) {
            if ($request->url() === 'https://api.stripe.com/v1/payment_intents') {
                $payloads[] = [
                    'amount' => (int) $request['amount'],
                    'currency' => $request['currency'],
                ];
            }

            return true;
        });

        $this->assertSame([
            ['amount' => 1000, 'currency' => 'usd'],
            ['amount' => 1000, 'currency' => 'jpy'],
        ], $payloads);
    }

    public function test_checkout_complete_rejects_an_unshippable_country(): void
    {
        $this->setShippingLocations([[
            'country' => 'US',
            'state' => '*',
            'city' => '*',
            'zip_code' => '*',
            'shipping_charge' => 15,
            'is_active' => true,
        ]]);

        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        $this->fakeSuccessfulPaymentIntent();

        Session::put('checkout.address.billing_address', [
            'street' => '1 King St',
            'city' => 'Toronto',
            'state' => 'ON',
            'postal_code' => 'M5V 2T6',
            'country' => 'Canada',
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_mock_token_id',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        Http::assertNothingSent();
    }

    public function test_process_checkout_rejects_an_unshippable_postcode(): void
    {
        $this->setShippingLocations([[
            'country' => 'USA',
            'state' => '*',
            'city' => '*',
            'zip_code' => '10001',
            'shipping_charge' => 15,
            'is_active' => true,
        ]]);

        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        $this->fakeSuccessfulPaymentIntent();

        $payload = $this->bankTransferCheckout();
        $payload['payment_method'] = 'stripe';
        $payload['stripe_payment_method'] = 'pm_mock_token_id';
        $payload['billing_address']['postal_code'] = '90210';

        $response = $this->post(route('shop.checkout.process'), $payload);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'We cannot ship to this address.');
        Http::assertNothingSent();
    }

    public function test_matching_free_shipping_rule_still_checks_out(): void
    {
        $this->setShippingLocations([[
            'country' => 'USA',
            'state' => '*',
            'city' => '*',
            'zip_code' => '*',
            'shipping_charge' => 0,
            'is_active' => true,
        ]]);

        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        $this->fakeSuccessfulPaymentIntent();

        $this->setCartTotal(10);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_free',
        ]);

        $response->assertOk();
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return $request->url() === 'https://api.stripe.com/v1/payment_intents'
                && (int) $request['amount'] === 1000;
        });
    }

    public function test_form_checkout_does_not_flash_card_fields(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');

        $valid = $this->bankTransferCheckout();
        $valid['payment_method'] = 'stripe';
        $valid['stripe_payment_method'] = 'pm_mock_token_id';
        $valid['card_number'] = '4242424242424242';
        $valid['card_cvv'] = '123';

        $rejected = $this->post(route('shop.checkout.process'), $valid);
        $rejected->assertSessionHas('error');
        $old = session('_old_input');
        $this->assertIsArray($old);
        $this->assertArrayNotHasKey('card_number', $old);
        $this->assertArrayNotHasKey('card_cvv', $old);
        $this->assertSame('john@example.com', $old['email']);

        $invalid = $valid;
        unset($invalid['email']);
        $invalid['first_name'] = 'Jane';

        $failed = $this->post(route('shop.checkout.process'), $invalid);
        $failed->assertSessionHasErrors('email');
        $old = session('_old_input');
        $this->assertIsArray($old);
        $this->assertArrayNotHasKey('card_number', $old);
        $this->assertArrayNotHasKey('card_cvv', $old);
        $this->assertSame('Jane', $old['first_name']);
    }

    public function test_checkout_stops_when_the_stock_lock_cannot_be_acquired(): void
    {
        $held = \Illuminate\Support\Facades\Cache::lock('webbycommerce-stock', 30);
        $this->assertTrue($held->get());

        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        $this->fakeSuccessfulPaymentIntent();

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_mock_token_id',
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
        $held->release();
    }

    public function test_authentication_step_does_not_mark_the_order_paid_until_the_intent_succeeds(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        $intentId = 'pi_auth_'.strtolower(\Illuminate\Support\Str::random(8));
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => function ($request) use ($intentId) {
                return Http::response([
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'status' => 'requires_action',
                    'amount' => (int) $request['amount'],
                    'currency' => (string) $request['currency'],
                    'client_secret' => $intentId.'_secret',
                ], 200);
            },
            'https://api.stripe.com/v1/payment_intents/'.$intentId => Http::response([
                'id' => $intentId,
                'object' => 'payment_intent',
                'status' => 'succeeded',
                'amount' => 10000,
                'currency' => 'usd',
                'client_secret' => $intentId.'_secret',
                'latest_charge' => 'ch_auth',
            ], 200),
        ]);

        $pending = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_card_authentication',
        ]);

        $pending->assertOk();
        $pending->assertJsonPath('success', false);
        $pending->assertJsonPath('requires_action', true);
        $pending->assertJsonPath('payment_intent', $intentId);
        $pending->assertJsonPath('client_secret', $intentId.'_secret');
        $this->assertNull(\Statamic\Facades\Entry::query()->where('collection', 'orders')->where('payment_intent', $intentId)->first());

        $paid = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'payment_intent' => $intentId,
        ]);

        $paid->assertOk();
        $paid->assertJsonPath('success', true);
        $order = \Statamic\Facades\Entry::query()->where('collection', 'orders')->where('payment_intent', $intentId)->first();
        $this->assertNotNull($order);
        $this->assertTrue((bool) $order->get('is_paid'));
        $this->assertSame('paid', $order->get('payment_status'));
        $this->assertSame($intentId, $order->get('payment_intent'));
    }

    public function test_a_succeeded_intent_for_the_wrong_amount_is_not_paid(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        Http::fake([
            'https://api.stripe.com/v1/payment_intents/pi_small' => Http::response([
                'id' => 'pi_small',
                'status' => 'succeeded',
                'amount' => 50,
                'currency' => 'usd',
            ], 200),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'payment_intent' => 'pi_small',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertNull(\Statamic\Facades\Entry::query()->where('collection', 'orders')->where('payment_intent', 'pi_small')->first());
    }

    public function test_a_payment_intent_already_on_an_order_cannot_pay_again(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        $intentId = 'pi_used_'.strtolower(\Illuminate\Support\Str::random(8));
        $orderNumber = 'ORD-USED-'.strtoupper(\Illuminate\Support\Str::random(6));

        \Statamic\Facades\Entry::make()
            ->collection('orders')
            ->slug(strtolower($orderNumber))
            ->published(true)
            ->data([
                'title' => $orderNumber,
                'order_number' => $orderNumber,
                'payment_intent' => $intentId,
                'payment_status' => 'paid',
                'is_paid' => true,
                'grand_total' => 100,
                'total' => 100,
            ])
            ->save();

        Http::fake([
            'https://api.stripe.com/v1/payment_intents/'.$intentId => Http::response([
                'id' => $intentId,
                'status' => 'succeeded',
                'amount' => 10000,
                'currency' => 'usd',
                'latest_charge' => 'ch_used',
            ], 200),
        ]);

        $response = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'payment_intent' => $intentId,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringContainsString('already been used', $response->json('message'));
        $this->assertSame(
            1,
            \Statamic\Facades\Entry::query()->where('collection', 'orders')->where('payment_intent', $intentId)->count()
        );
        Http::assertNothingSent();
    }

    public function test_a_declined_card_clears_idempotency_so_a_retry_can_charge(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        $keys = [];

        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => function ($request) use (&$keys) {
                $header = $request->header('Idempotency-Key');
                $keys[] = is_array($header) ? ($header[0] ?? null) : $header;

                if (count($keys) === 1) {
                    return Http::response([
                        'error' => [
                            'message' => 'Your card was declined.',
                            'type' => 'card_error',
                            'code' => 'card_declined',
                        ],
                    ], 402);
                }

                return Http::response([
                    'id' => 'pi_retry_ok',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => (int) $request['amount'],
                    'currency' => (string) $request['currency'],
                    'client_secret' => 'pi_retry_ok_secret',
                    'latest_charge' => 'ch_retry_ok',
                ], 200);
            },
        ]);

        $declined = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_card_declined',
        ]);

        $declined->assertStatus(400);
        $declined->assertJsonPath('success', false);
        $this->assertNull(Session::get('checkout.stripe_idempotency'));

        $retry = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_payment_method' => 'pm_card_visa',
        ]);

        $retry->assertOk();
        $retry->assertJsonPath('success', true);
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_checkout_success_looks_up_the_order(): void
    {
        $orderNumber = 'ORD-OK-'.strtoupper(\Illuminate\Support\Str::random(6));
        \Statamic\Facades\Entry::make()
            ->collection('orders')
            ->slug(strtolower($orderNumber))
            ->published(true)
            ->data([
                'title' => $orderNumber,
                'order_number' => $orderNumber,
                'status' => 'pending',
                'payment_status' => 'paid',
                'is_paid' => true,
            ])
            ->save();

        $found = $this->getJson(route('shop.checkout.success', ['orderNumber' => $orderNumber]));
        $found->assertOk();
        $found->assertJsonPath('success', true);
        $found->assertJsonPath('order_number', $orderNumber);
        $found->assertJsonPath('payment_status', 'paid');
        $found->assertJsonPath('is_paid', true);

        $missing = $this->getJson(route('shop.checkout.success', ['orderNumber' => 'ORD-MISSING']));
        $missing->assertStatus(404);
        $missing->assertJsonPath('success', false);
    }

    public function test_webhook_route_is_outside_the_web_middleware(): void
    {
        $webhook = \Illuminate\Support\Facades\Route::getRoutes()->getByName('shop.checkout.payment.webhook');
        $checkout = \Illuminate\Support\Facades\Route::getRoutes()->getByName('shop.checkout.complete');

        $this->assertNotNull($webhook);
        $this->assertNotNull($checkout);
        $this->assertNotContains('web', $webhook->middleware());
        $this->assertContains('web', $checkout->middleware());
    }

    public function test_signed_webhook_marks_the_matching_order_paid(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.webhook_secret', 'whsec_test');
        $intentId = 'pi_wh_'.strtolower(\Illuminate\Support\Str::random(8));
        $orderNumber = 'ORD-WH-'.strtoupper(\Illuminate\Support\Str::random(6));
        \Statamic\Facades\Entry::make()
            ->collection('orders')
            ->slug(strtolower($orderNumber))
            ->published(true)
            ->data([
                'title' => $orderNumber,
                'order_number' => $orderNumber,
                'payment_intent' => $intentId,
                'payment_status' => 'pending',
                'is_paid' => false,
                'grand_total' => 100,
                'total' => 100,
            ])
            ->save();

        $payload = json_encode([
            'id' => 'evt_paid',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 10000,
                    'currency' => 'usd',
                    'latest_charge' => 'ch_webhook',
                    'metadata' => [
                        'order_number' => $orderNumber,
                    ],
                ],
            ],
        ]);

        $response = $this->postWebhook($payload, $this->stripeSignature($payload, 'whsec_test'));

        $response->assertOk();
        $response->assertJsonPath('message', 'Order marked paid.');
        $order = \Statamic\Facades\Entry::query()->where('collection', 'orders')->where('order_number', $orderNumber)->first();
        $this->assertTrue((bool) $order->get('is_paid'));
        $this->assertSame('paid', $order->get('payment_status'));
        $this->assertSame('ch_webhook', $order->get('charge_id'));
    }

    public function test_webhook_rejects_a_bad_signature_and_a_mismatched_amount(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.webhook_secret', 'whsec_test');
        $intentId = 'pi_bad_'.strtolower(\Illuminate\Support\Str::random(8));
        $orderNumber = 'ORD-BAD-'.strtoupper(\Illuminate\Support\Str::random(6));
        \Statamic\Facades\Entry::make()
            ->collection('orders')
            ->slug(strtolower($orderNumber))
            ->published(true)
            ->data([
                'title' => $orderNumber,
                'order_number' => $orderNumber,
                'payment_intent' => $intentId,
                'payment_status' => 'pending',
                'is_paid' => false,
                'grand_total' => 100,
                'total' => 100,
            ])
            ->save();

        $payload = json_encode([
            'id' => 'evt_bad',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 10000,
                    'currency' => 'usd',
                ],
            ],
        ]);

        $unsigned = $this->postWebhook($payload, 't='.time().',v1=not-a-real-signature');
        $unsigned->assertStatus(401);
        $order = \Statamic\Facades\Entry::query()->where('collection', 'orders')->where('order_number', $orderNumber)->first();
        $this->assertFalse((bool) $order->get('is_paid'));

        $wrongAmount = json_encode([
            'id' => 'evt_amount',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => 50,
                    'currency' => 'usd',
                ],
            ],
        ]);
        $mismatch = $this->postWebhook($wrongAmount, $this->stripeSignature($wrongAmount, 'whsec_test'));
        $mismatch->assertStatus(422);
        $order = \Statamic\Facades\Entry::query()->where('collection', 'orders')->where('order_number', $orderNumber)->first();
        $this->assertFalse((bool) $order->get('is_paid'));
        $this->assertSame('pending', $order->get('payment_status'));
    }

    public function test_failed_webhook_marks_an_unpaid_order_failed_and_leaves_a_paid_order_alone(): void
    {
        Config::set('webbycommerce.payment.gateways.stripe.webhook_secret', 'whsec_test');
        $intentId = 'pi_fail_'.strtolower(\Illuminate\Support\Str::random(8));
        $orderNumber = 'ORD-FAIL-'.strtoupper(\Illuminate\Support\Str::random(6));
        \Statamic\Facades\Entry::make()
            ->collection('orders')
            ->slug(strtolower($orderNumber))
            ->published(true)
            ->data([
                'title' => $orderNumber,
                'order_number' => $orderNumber,
                'payment_intent' => $intentId,
                'payment_status' => 'pending',
                'is_paid' => false,
                'grand_total' => 100,
                'total' => 100,
            ])
            ->save();

        $failed = json_encode([
            'id' => 'evt_failed',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'status' => 'requires_payment_method',
                    'amount' => 10000,
                    'currency' => 'usd',
                ],
            ],
        ]);
        $this->postWebhook($failed, $this->stripeSignature($failed, 'whsec_test'))->assertOk();
        $order = \Statamic\Facades\Entry::query()->where('collection', 'orders')->where('order_number', $orderNumber)->first();
        $this->assertFalse((bool) $order->get('is_paid'));
        $this->assertSame('failed', $order->get('payment_status'));

        $order->set('is_paid', true)->set('payment_status', 'paid')->save();
        $again = json_encode([
            'id' => 'evt_failed_again',
            'type' => 'payment_intent.payment_failed',
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'status' => 'requires_payment_method',
                ],
            ],
        ]);
        $this->postWebhook($again, $this->stripeSignature($again, 'whsec_test'))->assertOk();
        $order = \Statamic\Facades\Entry::query()->where('collection', 'orders')->where('order_number', $orderNumber)->first();
        $this->assertTrue((bool) $order->get('is_paid'));
        $this->assertSame('paid', $order->get('payment_status'));
    }

    private function stripeSignature(string $payload, string $secret): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return 't='.$timestamp.',v1='.$signature;
    }

    private function postWebhook(string $payload, string $signature)
    {
        return $this->call(
            'POST',
            '/shop/payment/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ],
            $payload
        );
    }

    private function setCartTotal(float $price): void
    {
        Session::put('cart', [
            'items' => [
                'test_key' => [
                    'product_id' => 'test-product-id',
                    'name' => 'Test Product',
                    'slug' => 'test-product',
                    'sku' => 'TEST-SKU',
                    'price' => $price,
                    'image' => null,
                    'quantity' => 1,
                    'options' => [],
                ],
            ],
            'coupon' => null,
        ]);
    }

    private function setStoreCurrency(string $currency): void
    {
        Config::set('webbycommerce.currency', 'USD');
        $variables = \Statamic\Facades\GlobalSet::findByHandle('webbycommerce_settings')?->inDefaultSite();
        $this->assertNotNull($variables);
        $variables->set('currency', $currency)->save();
    }

    private function setShippingLocations(array $locations): void
    {
        $variables = \Statamic\Facades\GlobalSet::findByHandle('webbycommerce_settings')?->inDefaultSite();
        $this->assertNotNull($variables);
        $variables->set('shipping_locations', $locations)->save();
        app(\WebbyCrown\WebbyCommerceStatamic\Shipping\ShippingManager::class)->reload();
    }
}
