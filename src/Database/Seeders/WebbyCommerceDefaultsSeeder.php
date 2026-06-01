<?php

namespace WebbyCrown\WebbyCommerceStatamic\Database\Seeders;

use Illuminate\Database\Seeder;
use Statamic\Facades\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;

class WebbyCommerceDefaultsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \Statamic\Facades\Blink::flush();
        \Statamic\Facades\Stache::refresh();

        dump('Blueprints available for tax_categories before save: ' . json_encode(\Statamic\Facades\Blueprint::in('collections/tax_categories')->keys()->all()));

        $this->createDefaultTaxCategories();
        $this->createDefaultTaxZones();
        $this->createDefaultTaxRates();
        $this->createDefaultShippingRates();
    }

    /**
     * Create default tax rates.
     */
    protected function createDefaultTaxRates(): void
    {
        // Idempotent seeding. These defaults are intentionally minimal.
        // If you need jurisdiction-specific rates, override by creating your own
        // entries in the CP.
        $rates = [
            [
                'id' => 'standard-tax',
                'title' => 'Standard Tax',
                'name' => 'Standard Tax',
                'rate' => 0.0,
                'category' => 'standard',
                'zone' => 'everywhere',
            ],
        ];

        foreach ($rates as $data) {
            $existing = Entry::query()
                ->where('collection', 'tax_rates')
                ->where('id', $data['id'])
                ->first();

            if ($existing) {
                continue;
            }

            $entry = Entry::make()
                ->collection('tax_rates')
                ->locale('default')
                ->id($data['id'])
                ->slug($data['id'])
                ->data($data)
                ->published(true);

            $entry->save();

            echo "✓ Created tax rate: {$data['title']}\n";
        }
    }


    /**
     * Create default tax categories.
     */
    protected function createDefaultTaxCategories(): void
    {
        $categories = [
            [
                'id' => 'default',
                'title' => 'Default',
                'name' => 'Default',
                'description' => 'Default product tax category',
            ],
            [
                'id' => 'standard',
                'title' => 'Standard',
                'name' => 'Standard',
                'description' => 'Standard product tax category',
            ],
            [
                'id' => 'shipping',
                'title' => 'Shipping',
                'name' => 'Shipping',
                'description' => 'Tax category used for shipping charges',
            ],
        ];

        foreach ($categories as $data) {
            $existing = Entry::query()
                ->where('collection', 'tax_categories')
                ->where('id', $data['id'])
                ->first();

            if ($existing) {
                continue;
            }

            $entry = Entry::make()
                ->collection('tax_categories')
                ->locale('default')
                ->id($data['id'])
                ->slug($data['id'])
                ->data($data)
                ->published(true);

            $entry->save();

            echo "✓ Created tax category: {$data['title']}\n";
        }
    }

    /**
     * Create default tax zone.
     */
    protected function createDefaultTaxZones(): void
    {
        $zones = [
            [
                'id' => 'everywhere',
                'title' => 'Everywhere',
                'name' => 'Everywhere',
                'country' => '',
                'region' => '',
            ],
        ];

        foreach ($zones as $data) {
            $existing = Entry::query()
                ->where('collection', 'tax_zones')
                ->where('id', $data['id'])
                ->first();

            if ($existing) {
                continue;
            }

            $entry = Entry::make()
                ->collection('tax_zones')
                ->locale('default')
                ->id($data['id'])
                ->slug($data['id'])
                ->data($data)
                ->published(true);

            $entry->save();

            echo "✓ Created tax zone: {$data['title']}\n";
        }
    }

    /**
     * Create default shipping rates.
     */
    protected function createDefaultShippingRates(): void
    {
        $defaults = [
            'shipping_locations' => [
                [
                    'country' => 'US',
                    'state' => '*',
                    'city' => '*',
                    'zip_code' => '*',
                    'shipping_charge' => 12.0,
                    'min_order_amount' => null,
                    'max_order_amount' => null,
                    'is_active' => true,
                ],
            ],
            'shipping_default_method' => 'standard',
            'shipping_fallback_cost' => 0,
            'shipping_fallback_name' => 'Standard Shipping',
            'shipping_fallback_description' => 'Default shipping rate',
        ];

        $globalSet = GlobalSet::findByHandle('webbycommerce_settings');

        if (! $globalSet) {
            echo "✓ webbycommerce_settings global set not found; skipping shipping defaults.\n";
            return;
        }

        $variables = $globalSet->inDefaultSite();

        if (! $variables) {
            echo "✓ webbycommerce_settings globals not available; skipping shipping defaults.\n";
            return;
        }

        $existingLocations = $variables->get('shipping_locations', []);

        if (! empty($existingLocations)) {
            echo "✓ webbycommerce_settings already contains shipping locations; skipping shipping seed.\n";
            return;
        }

        $variables->merge($defaults);
        $variables->save();

        echo "✓ Created webbycommerce_settings shipping locations with default shipping settings.\n";
    }
}
