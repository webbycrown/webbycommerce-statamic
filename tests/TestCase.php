<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

use Illuminate\Support\Facades\Http;
use Statamic\Testing\AddonTestCase;
use WebbyCrown\WebbyCommerceStatamic\ServiceProvider;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    }

    protected function resetStoreSettings(): void
    {
        \Illuminate\Support\Facades\Config::set('webbycommerce.currency', 'USD');

        $globalSet = \Statamic\Facades\GlobalSet::findByHandle('webbycommerce_settings');
        if (! $globalSet) {
            $globalSet = \Statamic\Facades\GlobalSet::make('webbycommerce_settings')
                ->title('WebbyCommerce Settings')
                ->sites(['default' => null]);
            $globalSet->save();
        }

        $variables = $globalSet->inDefaultSite() ?? $globalSet->in('default');
        if (! $variables) {
            $variables = $globalSet->makeLocalization('default');
        }

        $variables->set('currency', 'USD');
        $variables->set('shipping_locations', []);
        $variables->save();

        try {
            app(\WebbyCrown\WebbyCommerceStatamic\Shipping\ShippingManager::class)->reload();
        } catch (\Throwable $e) {
            // Shipping manager may not be resolved yet.
        }
    }

    protected function fakeSuccessfulPaymentIntent(): void
    {
        Http::fake([
            'https://api.stripe.com/v1/payment_intents' => function ($request) {
                return Http::response([
                    'id' => 'pi_mock',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                    'amount' => (int) $request['amount'],
                    'currency' => (string) $request['currency'],
                    'client_secret' => 'pi_mock_secret_test',
                    'latest_charge' => 'ch_mock',
                ], 200);
            },
        ]);
    }
}
