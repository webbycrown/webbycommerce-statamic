<?php

namespace WebbyCrown\WebbyCommerceStatamic\Commands;

use Illuminate\Console\Command;
use WebbyCrown\WebbyCommerceStatamic\Install\StoreInstaller;

class InstallWebbyCommerce extends Command
{
    protected $signature = 'webbycommerce:install';

    protected $description = 'Create WebbyCommerce collections, blueprints, and settings when the site does not already have them';

    public function handle(): int
    {
        (new StoreInstaller)->install();

        $this->info('WebbyCommerce is installed. Existing products, customers, and other collections were left unchanged.');

        return self::SUCCESS;
    }
}
