<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;
use Statamic\Facades\Entry;
use WebbyCrown\WebbyCommerceStatamic\Products\Product;

class TaxAndStockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Support\Facades\Mail::fake();
        Config::set('webbycommerce.payment.gateways.stripe.secret_key', 'sk_test_mock_secret_key');
        Config::set('webbycommerce.tax.engine', 'basic');
        Config::set('webbycommerce.tax.rate', 0.2);
        Config::set('webbycommerce.tax.included_in_prices', false);
        Config::set('webbycommerce.tax.shipping_taxes', false);
        Config::set('webbycommerce.shipping.methods', [
            'standard' => ['name' => 'Standard', 'cost' => 0],
        ]);
    }

    public function test_tax_inclusive_checkout_does_not_add_extracted_tax(): void
    {
        Config::set('webbycommerce.tax.included_in_prices', true);
        $this->seedProduct('inclusive-product', 120, 5);

        $this->putCart('inclusive-product', 120, 1);
        $this->putCheckout(0);

        Http::fake([
            'https://api.stripe.com/v1/charges' => Http::response(['id' => 'ch_inclusive', 'paid' => true], 200),
        ]);

        $complete = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_inclusive',
        ]);

        $complete->assertOk();
        Http::assertSent(fn ($request) => (int) $request['amount'] === 12000);

        $order = Entry::query()->where('collection', 'orders')->get()->last();
        $this->assertNotNull($order);
        $this->assertEquals(120.0, (float) $order->get('total'));
        $this->assertEquals(20.0, (float) $order->get('tax'));

        $this->putCart('inclusive-product', 120, 1);
        $this->putCheckout(0);

        $process = $this->from('/checkout')->post(route('shop.checkout.process'), $this->processPayload('tok_inclusive_form'));
        $process->assertRedirect();
        $process->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request['source'] === 'tok_inclusive_form' && (int) $request['amount'] === 12000);
    }

    public function test_tax_exclusive_checkout_adds_tax_once(): void
    {
        Config::set('webbycommerce.tax.included_in_prices', false);
        $this->seedProduct('exclusive-product', 100, 5);
        $this->putCart('exclusive-product', 100, 1);
        $this->putCheckout(0);

        Http::fake([
            'https://api.stripe.com/v1/charges' => Http::response(['id' => 'ch_exclusive', 'paid' => true], 200),
        ]);

        $complete = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_exclusive',
        ]);

        $complete->assertOk();
        Http::assertSent(fn ($request) => (int) $request['amount'] === 12000);

        $this->putCart('exclusive-product', 100, 1);
        $this->putCheckout(0);

        $process = $this->from('/checkout')->post(route('shop.checkout.process'), $this->processPayload('tok_exclusive_form'));
        $process->assertRedirect();
        $process->assertSessionHas('success');
        Http::assertSent(fn ($request) => $request['source'] === 'tok_exclusive_form' && (int) $request['amount'] === 12000);
    }

    public function test_zero_stock_and_over_quantity_cannot_be_purchased(): void
    {
        $this->seedProduct('tracked-product', 40, 0);

        $add = $this->postJson(route('shop.cart.add'), [
            'product_id' => 'tracked-product',
            'quantity' => 1,
        ]);
        $add->assertStatus(400);

        $entry = Entry::find('tracked-product');
        $entry->set('stock', 1)->save();

        $over = $this->postJson(route('shop.cart.add'), [
            'product_id' => 'tracked-product',
            'quantity' => 2,
        ]);
        $over->assertStatus(400);

        $ok = $this->postJson(route('shop.cart.add'), [
            'product_id' => 'tracked-product',
            'quantity' => 1,
        ]);
        $ok->assertOk();

        $this->putCheckout(0);

        Http::fake([
            'https://api.stripe.com/v1/charges' => Http::response(['id' => 'ch_stock', 'paid' => true], 200),
        ]);

        $complete = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_stock',
        ]);
        $complete->assertOk();

        $this->assertSame(0, Product::fromEntry(Entry::find('tracked-product'))->quantity());

        $again = $this->postJson(route('shop.cart.add'), [
            'product_id' => 'tracked-product',
            'quantity' => 1,
        ]);
        $again->assertStatus(400);

        $this->putCart('tracked-product', 40, 1);
        $blocked = $this->postJson(route('shop.checkout.complete'), [
            'payment_method' => 'stripe',
            'stripe_token' => 'tok_stock_again',
        ]);
        $blocked->assertStatus(422);
        Http::assertNotSent(fn ($request) => ($request['source'] ?? null) === 'tok_stock_again');
    }

    protected function seedProduct(string $id, float $price, int $stock, bool $track = true): void
    {
        Entry::make()
            ->collection('products')
            ->id($id)
            ->slug($id)
            ->published(true)
            ->data([
                'title' => 'Product '.$id,
                'price' => $price,
                'sku' => strtoupper($id),
                'status' => 'active',
                'track_stock' => $track,
                'stock' => $stock,
            ])
            ->save();
    }

    protected function putCart(string $productId, float $price, int $quantity): void
    {
        Session::put('cart', [
            'items' => [
                'line' => [
                    'product_id' => $productId,
                    'name' => 'Product '.$productId,
                    'slug' => $productId,
                    'sku' => strtoupper($productId),
                    'price' => $price,
                    'image' => null,
                    'quantity' => $quantity,
                    'options' => [],
                ],
            ],
            'coupon' => null,
        ]);
    }

    protected function putCheckout(float $shipping): void
    {
        Session::put('checkout', [
            'address' => [
                'email' => 'buyer@example.com',
                'first_name' => 'Buyer',
                'last_name' => 'Test',
                'phone' => '555',
                'shipping_same_as_billing' => true,
                'billing_address' => [
                    'street' => '1 Road',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'postal_code' => '78701',
                    'country' => 'USA',
                ],
            ],
            'shipping' => [
                'shipping_method' => 'standard',
                'shipping_cost' => $shipping,
            ],
        ]);
    }

    protected function processPayload(string $token): array
    {
        return [
            'email' => 'buyer@example.com',
            'first_name' => 'Buyer',
            'last_name' => 'Test',
            'billing_address' => [
                'street' => '1 Road',
                'city' => 'Austin',
                'state' => 'TX',
                'country' => 'USA',
                'postal_code' => '78701',
            ],
            'shipping_same_as_billing' => true,
            'payment_method' => 'stripe',
            'stripe_token' => $token,
            'shipping_method' => 'standard',
        ];
    }
}
