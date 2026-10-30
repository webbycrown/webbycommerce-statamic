<?php

namespace WebbyCrown\WebbyCommerceStatamic\Install;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Symfony\Component\Yaml\Yaml;

/**
 * Creates missing store collections, blueprints, and settings.
 * The install command calls this class directly. Laravel cannot resolve
 * the add-on service provider from the container.
 */
class StoreInstaller
{
    public function install(): void
    {
        $this->registerCollections();
        $this->registerGlobals();
    }

    public function registerCollections()
    {
        $collectionsPath = base_path('content/collections');
        $blueprintsPath = base_path('resources/blueprints/collections');

        if (! File::exists($collectionsPath)) {
            File::makeDirectory($collectionsPath, 0755, true);
        }

        if (! File::exists($blueprintsPath)) {
            File::makeDirectory($blueprintsPath, 0755, true);
        }

        $collections = [
            'products' => [
                'title' => 'Products',
                'route' => null,
                'template' => null,
                'layout' => null,
                'sites' => ['default'],
                'revisions' => false,
                'sort_by' => 'title',
                'sort_dir' => 'asc',
                'title_format' => '{title}',
                'blueprints' => ['products'],
                'show_sidebar' => false,
                'date_behavior' => [
                    'past' => 'public',
                    'future' => 'private',
                ],
            ],
            'orders' => [
                'title' => 'Orders',
                'route' => null,
                'template' => null,
                'layout' => null,
                'sites' => ['default'],
                'revisions' => false,
                'sort_by' => 'id',
                'sort_dir' => 'desc',
                'title_format' => 'Order #{id}',
                'blueprints' => ['orders'],
                'show_sidebar' => false,
                'date_behavior' => [
                    'past' => 'public',
                    'future' => 'private',
                ],
            ],
            'customers' => [
                'title' => 'Customers',
                'route' => null,
                'template' => null,
                'layout' => null,
                'sites' => ['default'],
                'revisions' => false,
                'sort_by' => 'email',
                'sort_dir' => 'asc',
                'title_format' => '{email}',
                'blueprints' => ['customers'],
                'show_sidebar' => false,
                'date_behavior' => [
                    'past' => 'public',
                    'future' => 'private',
                ],
            ],
            'coupons' => [
                'title' => 'Coupons',
                'route' => null,
                'template' => null,
                'layout' => null,
                'sites' => ['default'],
                'revisions' => false,
                'sort_by' => 'code',
                'sort_dir' => 'asc',
                'title_format' => '{code}',
                'blueprints' => ['coupons'],
                'show_sidebar' => false,
                'date_behavior' => [
                    'past' => 'public',
                    'future' => 'private',
                ],
            ],
            'tax_rates' => [
                'title' => 'Tax Rates',
                'route' => null,
                'template' => null,
                'layout' => null,
                'sites' => ['default'],
                'revisions' => false,
                'sort_by' => 'title',
                'sort_dir' => 'asc',
                'title_format' => '{name}',
                'blueprints' => ['tax_rates'],
                'show_sidebar' => false,
                'date_behavior' => [
                    'past' => 'public',
                    'future' => 'private',
                ],
            ],
            'tax_categories' => [
                'title' => 'Tax Categories',
                'route' => null,
                'template' => null,
                'layout' => null,
                'sites' => ['default'],
                'revisions' => false,
                'sort_by' => 'title',
                'sort_dir' => 'asc',
                'title_format' => '{name}',
                'blueprints' => ['tax_categories'],
                'show_sidebar' => true,
                'date_behavior' => [
                    'past' => 'public',
                    'future' => 'private',
                ],
            ],
            'tax_zones' => [
                'title' => 'Tax Zones',
                'route' => null,
                'template' => null,
                'layout' => null,
                'sites' => ['default'],
                'revisions' => false,
                'sort_by' => 'title',
                'sort_dir' => 'asc',
                'title_format' => '{name}',
                'blueprints' => ['tax_zones'],
                'show_sidebar' => true,
                'date_behavior' => [
                    'past' => 'public',
                    'future' => 'private',
                ],
            ],
        ];

        foreach ($collections as $handle => $config) {
            $yamlFile = $collectionsPath.'/'.$handle.'.yaml';

            if (File::exists($yamlFile) || Collection::findByHandle($handle)) {
                continue;
            }

            File::put($yamlFile, Yaml::dump($config));
            Log::info('Collection YAML created: '.$handle);
        }

        // Copy collection blueprints from addon to project if they don't exist
        $sourceBlueprintsDir = dirname(__DIR__, 2).'/resources/blueprints/collections';
        if (File::exists($sourceBlueprintsDir)) {
            foreach ($collections as $handle => $config) {
                $sourceBlueprintFile = $sourceBlueprintsDir.'/'.$handle.'/'.$handle.'.yaml';
                $targetBlueprintDir = $blueprintsPath.'/'.$handle;
                $targetBlueprintFile = $targetBlueprintDir.'/'.$handle.'.yaml';

                if (File::exists($sourceBlueprintFile) && ! File::exists($targetBlueprintFile)) {
                    if (! File::exists($targetBlueprintDir)) {
                        File::makeDirectory($targetBlueprintDir, 0755, true);
                    }
                    File::copy($sourceBlueprintFile, $targetBlueprintFile);
                    Log::info("Collection blueprint copied: {$handle}");
                }
            }
        }

        // Register Collections only if they don't already exist
        $newCollectionsRegistered = false;
        foreach ($collections as $handle => $config) {
            try {
                if (! Collection::findByHandle($handle)) {
                    $collection = Collection::make($handle)
                        ->title($config['title'])
                        ->handle($handle)
                        ->routes($config['route'] ?? null)
                        ->template($config['template'] ?? null)
                        ->layout($config['layout'] ?? null)
                        ->sites($config['sites'] ?? ['default']);

                    if (method_exists($collection, 'showSidebar')) {
                        $collection->showSidebar($config['show_sidebar']);
                    }

                    $collection->save();
                    $newCollectionsRegistered = true;
                    Log::info('Collection registered: '.$handle);
                }
            } catch (\Exception $e) {
                Log::error('Collection registration failed', [
                    'handle' => $handle,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($newCollectionsRegistered) {
            \Statamic\Facades\Blink::flush();
        }
    }

    public function registerGlobals()
    {
        $globalsPath = base_path('content/globals');
        $blueprintPath = base_path('resources/blueprints/globals');

        // Ensure directories exist
        if (! File::exists($globalsPath)) {
            File::makeDirectory($globalsPath, 0755, true);
        }

        if (! File::exists($blueprintPath)) {
            File::makeDirectory($blueprintPath, 0755, true);
        }

        // Copy the blueprint from the addon if it doesn't exist in the project
        $sourceBlueprintFile = dirname(__DIR__, 2).'/resources/blueprints/globals/webbycommerce_settings.yaml';
        $targetBlueprintFile = $blueprintPath.'/webbycommerce_settings.yaml';

        if (File::exists($sourceBlueprintFile) && ! File::exists($targetBlueprintFile)) {
            File::copy($sourceBlueprintFile, $targetBlueprintFile);
            Log::info('WebbyCommerce globals blueprint published.');
        }

        $defaults = [
            'currency' => 'USD',
            'shipping_locations' => [
                [
                    'country' => '*',
                    'state' => '*',
                    'city' => '*',
                    'zip_code' => '*',
                    'shipping_charge' => 5.00,
                    'min_order_amount' => null,
                    'max_order_amount' => null,
                    'is_active' => true,
                ],
            ],
            'shipping_default_method' => 'standard',
            'shipping_fallback_cost' => 0,
            'shipping_fallback_name' => 'Standard Shipping',
            'shipping_fallback_description' => 'Default shipping rate',
            'shipping_threshold_express' => 500,
            'shipping_threshold_overnight' => 1500,
            'payment_gateway' => 'stripe',
            'store_email' => 'admin@example.com',
            'order_number_prefix' => 'ORD',
            'order_status_flow' => ['pending', 'processing', 'shipped', 'delivered'],
            'cart_session_key' => 'cart',
            'cart_expires_days' => 7,
            'email_order_confirmation_enabled' => true,
            'email_order_confirmation_to_customer' => true,
            'email_order_confirmation_to_admin' => true,
            'email_order_shipped_enabled' => true,
        ];

        // Statamic 6 keeps the set and the site variables in separate files.
        $globalsFile = $globalsPath.'/webbycommerce_settings.yaml';
        $siteHandle = 'default';
        $variablesDir = $globalsPath.'/'.$siteHandle;
        $variablesFile = $variablesDir.'/webbycommerce_settings.yaml';

        if (! File::exists($globalsFile)) {
            File::put($globalsFile, Yaml::dump([
                'title' => 'WebbyCommerce Settings',
                'sites' => [
                    $siteHandle => null,
                ],
            ]));
            Log::info('WebbyCommerce globals set created.');
        }

        if (! File::exists($variablesFile)) {
            if (! File::exists($variablesDir)) {
                File::makeDirectory($variablesDir, 0755, true);
            }

            File::put($variablesFile, Yaml::dump($defaults));
            Log::info('WebbyCommerce globals variables created with defaults.');
        }

        try {
            $globalSet = GlobalSet::findByHandle('webbycommerce_settings');

            if (! $globalSet) {
                $globalSet = GlobalSet::make('webbycommerce_settings')
                    ->title('WebbyCommerce Settings')
                    ->sites([$siteHandle => null]);
                $globalSet->save();
                Log::info('Global set registered: webbycommerce_settings');
            }

            $variables = $globalSet->in($siteHandle) ?? $globalSet->inDefaultSite();

            if ($variables && blank($variables->get('currency'))) {
                foreach ($defaults as $key => $value) {
                    if ($variables->get($key) === null) {
                        $variables->set($key, $value);
                    }
                }
                $variables->save();
            }
        } catch (\Exception $e) {
            Log::error('Global set registration failed', [
                'handle' => 'webbycommerce_settings',
                'message' => $e->getMessage(),
            ]);
        }
    }

}
