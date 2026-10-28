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
