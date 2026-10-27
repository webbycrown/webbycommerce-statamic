<?php

namespace WebbyCrown\WebbyCommerceStatamic\Tests;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Collection;

class InstallCommandTest extends TestCase
{
    private string $emptyStache;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $this->emptyStache = sys_get_temp_dir().'/webbycommerce-install-stache-'.getmypid();
        File::deleteDirectory($this->emptyStache);
        File::makeDirectory($this->emptyStache.'/collections', 0755, true);
        File::makeDirectory($this->emptyStache.'/globals', 0755, true);

        $app['config']->set('statamic.stache.stores.collections.directory', $this->emptyStache.'/collections');
        $app['config']->set('statamic.stache.stores.entries.directory', $this->emptyStache.'/collections');
        $app['config']->set('statamic.stache.stores.globals.directory', $this->emptyStache.'/globals');
        $app['config']->set('statamic.stache.stores.global-variables.directory', $this->emptyStache.'/globals');
    }

    protected function tearDown(): void
    {
        foreach (['products', 'orders', 'customers', 'coupons', 'tax_rates', 'tax_categories', 'tax_zones'] as $handle) {
            File::delete(base_path('content/collections/'.$handle.'.yaml'));
            File::deleteDirectory(base_path('resources/blueprints/collections/'.$handle));
        }

        File::delete(base_path('content/globals/webbycommerce_settings.yaml'));
        File::delete(base_path('resources/blueprints/globals/webbycommerce_settings.yaml'));

        if (isset($this->emptyStache)) {
            File::deleteDirectory($this->emptyStache);
        }

        parent::tearDown();
    }

    public function test_install_command_creates_store_files_without_resolving_the_provider(): void
    {
        $this->assertNull(Collection::findByHandle('products'));
        $this->assertNull(Collection::findByHandle('orders'));
        $this->assertNull(Collection::findByHandle('customers'));

        $this->artisan('webbycommerce:install')->assertSuccessful();

        foreach (['products', 'orders', 'customers', 'coupons', 'tax_rates', 'tax_categories', 'tax_zones'] as $handle) {
            $this->assertFileExists(base_path('content/collections/'.$handle.'.yaml'));
            $this->assertFileExists(base_path('resources/blueprints/collections/'.$handle.'/'.$handle.'.yaml'));
        }

        $this->assertFileExists(base_path('content/globals/webbycommerce_settings.yaml'));
        $this->assertFileExists(base_path('resources/blueprints/globals/webbycommerce_settings.yaml'));
    }
}
