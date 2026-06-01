<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

use WebbyCrown\WebbyCommerceStatamic\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
