<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

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
}
