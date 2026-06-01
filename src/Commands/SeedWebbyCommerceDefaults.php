<?php

namespace WebbyCrown\WebbyCommerceStatamic\Commands;

use Illuminate\Console\Command;
use WebbyCrown\WebbyCommerceStatamic\Database\Seeders\WebbyCommerceDefaultsSeeder;

class SeedWebbyCommerceDefaults extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webbycommerce:seed-defaults {--force : Force seeding without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed default tax categories, tax zones, and shipping rates for WebbyCommerce addon';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (!$this->option('force') && !$this->confirm('This will create default tax categories, tax zones, and shipping rates. Continue?')) {
            return 0;
        }

        $this->info('Seeding WebbyCommerce defaults...');
        $this->newLine();

        try {
            $seeder = new WebbyCommerceDefaultsSeeder();
            $seeder->run();

            $this->newLine();
            $this->info('✓ WebbyCommerce defaults seeded successfully!');

            return 0;
        } catch (\Exception $e) {
            $this->error('✗ Error seeding defaults: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            return 1;
        }
    }
}
