<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

use WebbyCrown\WebbyCommerceStatamic\Http\Controllers\Shop\CheckoutController;
use WebbyCrown\WebbyCommerceStatamic\Shipping\ShippingManager;
use Illuminate\Support\Facades\Config;

class ShippingTest extends TestCase
{
    public function test_state_code_and_state_name_match_using_region_lookup(): void
    {
        $manager = new ShippingManager();
        $reflection = new \ReflectionClass(ShippingManager::class);
        $method = $reflection->getMethod('stateMatches');
        $method->setAccessible(true);

        $this->assertTrue($method->invokeArgs($manager, ['CA', 'California', 'US']));
        $this->assertTrue($method->invokeArgs($manager, ['California', 'CA', 'US']));
        $this->assertFalse($method->invokeArgs($manager, ['CA', 'Texas', 'US']));
    }
}
