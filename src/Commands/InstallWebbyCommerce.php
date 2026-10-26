<?php

namespace WebbyCrown\WebbyCommerceStatamic\Commands;

use Illuminate\Console\Command;
use WebbyCrown\WebbyCommerceStatamic\ServiceProvider;

class InstallWebbyCommerce extends Command
{
    protected $signature = 'webbycommerce:install';

    protected $description = 'Create WebbyCommerce collections, blueprints, and settings when the site does not already have them';

    public function handle(): int
    {
        app(ServiceProvider::class)->installStore();

        $this->info('WebbyCommerce is installed. Existing products, customers, and other collections were left unchanged.');

        return self::SUCCESS;
    }
}
