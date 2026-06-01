<?php

namespace WebbyCrown\WebbyCommerceStatamic;

use WebbyCrown\WebbyCommerceStatamic\Coupons\EntryCouponRepository;
use WebbyCrown\WebbyCommerceStatamic\Customers\EntryCustomerRepository;
use WebbyCrown\WebbyCommerceStatamic\Orders\EntryOrderRepository;
use WebbyCrown\WebbyCommerceStatamic\Products\EntryProductRepository;
use WebbyCrown\WebbyCommerceStatamic\Tags\Cart;
use WebbyCrown\WebbyCommerceStatamic\Tags\Checkout;
use WebbyCrown\WebbyCommerceStatamic\Tags\WebbyCommerce;
use WebbyCrown\WebbyCommerceStatamic\Tags\ProductTag;
use WebbyCrown\WebbyCommerceStatamic\Tags\Wishlist;
use WebbyCrown\WebbyCommerceStatamic\Tax\TaxManager;
use WebbyCrown\WebbyCommerceStatamic\Commands\SeedWebbyCommerceDefaults;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Statamic;
use Statamic\Providers\AddonServiceProvider;
use Symfony\Component\Yaml\Yaml;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [];

    protected $subscribe = [];

    public function register()
    {
        parent::register();

        $this->app->singleton(EntryProductRepository::class);
        $this->app->singleton(EntryOrderRepository::class);
        $this->app->singleton(EntryCustomerRepository::class);
        $this->app->singleton(EntryCouponRepository::class);
        $this->app->singleton(TaxManager::class);
        $this->app->singleton(\WebbyCrown\WebbyCommerceStatamic\Cart\Cart::class);
        $this->app->alias(\WebbyCrown\WebbyCommerceStatamic\Cart\Cart::class, 'cart');
        $this->app->singleton(\WebbyCrown\WebbyCommerceStatamic\Wishlist\Wishlist::class);
        $this->app->alias(\WebbyCrown\WebbyCommerceStatamic\Wishlist\Wishlist::class, 'wishlist');
    }

    protected $tags = [
        'product_tag' => ProductTag::class,
        'cart' => Cart::class,
        'checkout' => Checkout::class,
        'webbycommerce' => WebbyCommerce::class,
        'wishlist' => Wishlist::class,
    ];

    protected $fieldtypes = [
        \WebbyCrown\WebbyCommerceStatamic\Fieldtypes\JsonOptions::class,
    ];

    protected $widgets = [];

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
        ],

        'publicDirectory' => 'dist',
    ];

    /**
     * Prevent registering the CP nav more than once.
     *
     * @var bool
     */
    protected static $navRegistered = false;

    public function bootAddon()
    {
        parent::bootAddon();
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'webbycommerce');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedWebbyCommerceDefaults::class,
            ]);
        }

        
        $this->publishes([
            __DIR__.'/../config/webbycommerce.php' => config_path('webbycommerce.php'),
        ], 'webbycommerce-config');
        $this->publishes([
            __DIR__.'/../resources/blueprints' => resource_path('blueprints'),
        ], 'webbycommerce-blueprints');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'webbycommerce');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/webbycommerce'),
        ], 'webbycommerce-email-templates');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/webbycommerce'),
        ], 'webbycommerce-views');

        $this->registerPermissions();
        $this->registerRoutes();
        $this->registerCollections();
        $this->registerGlobals();
        $this->syncGlobalPaymentSettings();
        $this->createNavItems();
    }

    protected function registerCollections()
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

        // Create/update collection YAML files only if they don't exist or are missing key configs
        foreach ($collections as $handle => $config) {
            $yamlFile = $collectionsPath.'/'.$handle.'.yaml';

            if (! File::exists($yamlFile)) {
                File::put($yamlFile, Yaml::dump($config));
                Log::info('Collection YAML created: '.$handle);
                continue;
            }

            $existingConfig = Yaml::parseFile($yamlFile);
            $changed = false;

            if (! isset($existingConfig['date_behavior'])) {
                $existingConfig['date_behavior'] = $config['date_behavior'];
                $changed = true;
            }

            if (! isset($existingConfig['blueprints'])) {
                $existingConfig['blueprints'] = $config['blueprints'];
                $changed = true;
            }

            if ($changed) {
                File::put($yamlFile, Yaml::dump($existingConfig));
                Log::info('Collection YAML updated: '.$handle);
            }
        }

        // Copy collection blueprints from addon to project if they don't exist
        $sourceBlueprintsDir = __DIR__.'/../resources/blueprints/collections';
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

    protected function registerGlobals()
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
        $sourceBlueprintFile = __DIR__.'/../resources/blueprints/globals/webbycommerce_settings.yaml';
        $targetBlueprintFile = $blueprintPath.'/webbycommerce_settings.yaml';

        if (File::exists($sourceBlueprintFile) && ! File::exists($targetBlueprintFile)) {
            File::copy($sourceBlueprintFile, $targetBlueprintFile);
            Log::info('WebbyCommerce globals blueprint published.');
        }

        // Create default globals content if it doesn't exist
        $globalsFile = $globalsPath.'/webbycommerce_settings.yaml';

        if (! File::exists($globalsFile)) {
            $defaults = [
                'title' => 'WebbyCommerce Settings',
                'data' => [
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
                ],
            ];

            File::put($globalsFile, Yaml::dump($defaults));
            Log::info('WebbyCommerce globals content created with defaults.');
        }

        // Register the Global Set if not already present
        try {
            if (! GlobalSet::findByHandle('webbycommerce_settings')) {
                $globalSet = GlobalSet::make('webbycommerce_settings')
                    ->title('WebbyCommerce Settings');
                $globalSet->save();
                Log::info('Global set registered: webbycommerce_settings');
            }
        } catch (\Exception $e) {
            Log::error('Global set registration failed', [
                'handle' => 'webbycommerce_settings',
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function syncGlobalPaymentSettings()
    {
        try {
            $globalSet = GlobalSet::findByHandle('webbycommerce_settings');
            $variables = $globalSet?->inDefaultSite();

            if (! $variables) {
                return;
            }

            $defaultGateway = $variables->get('payment_gateway');
            $stripePublishableKey = $variables->get('stripe_publishable_key');
            $stripeSecretKey = $variables->get('stripe_secret_key');
            $stripeWebhookSecret = $variables->get('stripe_webhook_secret');
            $paypalClientId = $variables->get('paypal_client_id');
            $paypalSecret = $variables->get('paypal_secret');

            if ($defaultGateway) {
                config(['webbycommerce.payment.default_gateway' => $defaultGateway]);
            }

            if ($stripePublishableKey) {
                config(['webbycommerce.payment.gateways.stripe.publishable_key' => $stripePublishableKey]);
            }

            if ($stripeSecretKey) {
                config(['webbycommerce.payment.gateways.stripe.secret_key' => $stripeSecretKey]);
            }

            if ($stripeWebhookSecret) {
                config(['webbycommerce.payment.gateways.stripe.webhook_secret' => $stripeWebhookSecret]);
            }

            if ($paypalClientId) {
                config(['webbycommerce.payment.gateways.paypal.client_id' => $paypalClientId]);
            }

            if ($paypalSecret) {
                config(['webbycommerce.payment.gateways.paypal.secret' => $paypalSecret]);
            }

            $storeName = $variables->get('store_name');
            $storeEmail = $variables->get('store_email');
            $orderNumberPrefix = $variables->get('order_number_prefix');
            $orderStatusFlow = $variables->get('order_status_flow');
            $cartSessionKey = $variables->get('cart_session_key');
            $cartExpiresDays = $variables->get('cart_expires_days');
            $orderConfirmationEnabled = $variables->get('email_order_confirmation_enabled');
            $orderConfirmationToCustomer = $variables->get('email_order_confirmation_to_customer');
            $orderConfirmationToAdmin = $variables->get('email_order_confirmation_to_admin');
            $orderConfirmationAdminEmail = $variables->get('email_order_confirmation_admin_email');
            $orderShippedEnabled = $variables->get('email_order_shipped_enabled');

            if ($storeName) {
                config(['webbycommerce.store.name' => $storeName]);
            }

            if ($storeEmail) {
                config(['webbycommerce.store.email' => $storeEmail]);
            }

            if ($orderNumberPrefix) {
                config(['webbycommerce.orders.number_prefix' => $orderNumberPrefix]);
            }

            if (is_array($orderStatusFlow)) {
                config(['webbycommerce.orders.status_flow' => $orderStatusFlow]);
            }

            if ($cartSessionKey) {
                config(['webbycommerce.cart.session_key' => $cartSessionKey]);
            }

            if (is_numeric($cartExpiresDays)) {
                config(['webbycommerce.cart.expires_after' => (int) $cartExpiresDays * 60 * 24]);
            }

            if ($orderConfirmationEnabled !== null) {
                config(['webbycommerce.emails.order_confirmation.enabled' => (bool) $orderConfirmationEnabled]);
            }

            if ($orderConfirmationToCustomer !== null) {
                config(['webbycommerce.emails.order_confirmation.to_customer' => (bool) $orderConfirmationToCustomer]);
            }

            if ($orderConfirmationToAdmin !== null) {
                config(['webbycommerce.emails.order_confirmation.to_admin' => (bool) $orderConfirmationToAdmin]);
            }

            if ($orderConfirmationAdminEmail) {
                config(['webbycommerce.emails.order_confirmation.admin_email' => $orderConfirmationAdminEmail]);
            }

            if ($orderShippedEnabled !== null) {
                config(['webbycommerce.emails.order_shipped.enabled' => (bool) $orderShippedEnabled]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to sync webbycommerce_settings globals with payment config', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    protected function createNavItems()
    {
        if (self::$navRegistered) {
            return;
        }

        Nav::extend(function ($nav) {
            $nav->content('WebbyCommerce')
                ->route('collections.show', 'products')
                ->icon(Statamic::svg('icons/plump/shopping-cart'))
                ->children([
                    'Products' => cp_route('collections.show', 'products'),
                    'Orders' => cp_route('collections.show', 'orders'),
                    'Customers' => cp_route('collections.show', 'customers'),
                    'Coupons' => cp_route('collections.show', 'coupons'),
                    'Tax Categories' => cp_route('collections.show', 'tax_categories'),
                    'Tax Zones' => cp_route('collections.show', 'tax_zones'),
                    'Tax Rates' => cp_route('collections.show', 'tax_rates'),
                    'Settings' => cp_route('globals.variables.edit', 'webbycommerce_settings'),
                ]);

            // Remove Webby-Commerce collections from the default Collections sidebar menu
            $nav->remove('Content', 'Collections', 'Products');
            $nav->remove('Content', 'Collections', 'Orders');
            $nav->remove('Content', 'Collections', 'Customers');
            $nav->remove('Content', 'Collections', 'Coupons');
            $nav->remove('Content', 'Collections', 'Tax Categories');
            $nav->remove('Content', 'Collections', 'Tax Zones');
            $nav->remove('Content', 'Collections', 'Tax Rates');

            // Remove global settings item from the default Globals sidebar menu
            $nav->remove('Globals', 'Webby-Commerce Settings');
            $nav->remove('Globals', 'Webby-Commerce settings');
            $nav->remove('Globals', 'Webby Commerce Settings');
            $nav->remove('Globals', 'Webbycommerce Settings');

            // Try removing by the CP route (some Statamic versions index nav items by route)
            try {
                $nav->remove('Globals', cp_route('globals.variables.edit', 'webbycommerce_settings'));
            } catch (\Throwable $e) {
                // ignore — removal by route may not be supported in all versions
            }
        });

        self::$navRegistered = true;
    }

    protected function registerPermissions()
    {
        Permission::register('view webbycommerce products')
            ->label('View WebbyCommerce Products');

        Permission::register('edit webbycommerce products')
            ->label('Edit WebbyCommerce Products');

        Permission::register('create webbycommerce products')
            ->label('Create WebbyCommerce Products');

        Permission::register('delete webbycommerce products')
            ->label('Delete WebbyCommerce Products');

        Permission::register('view webbycommerce orders')
            ->label('View WebbyCommerce Orders');

        Permission::register('edit webbycommerce orders')
            ->label('Edit WebbyCommerce Orders');

        Permission::register('view webbycommerce customers')
            ->label('View WebbyCommerce Customers');

        Permission::register('edit webbycommerce customers')
            ->label('Edit WebbyCommerce Customers');

        Permission::register('view webbycommerce coupons')
            ->label('View WebbyCommerce Coupons');

        Permission::register('edit webbycommerce coupons')
            ->label('Edit WebbyCommerce Coupons');

        Permission::register('create webbycommerce coupons')
            ->label('Create WebbyCommerce Coupons');

        Permission::register('delete webbycommerce coupons')
            ->label('Delete WebbyCommerce Coupons');
    }

    protected function registerRoutes()
    {
        Route::middleware(['web'])
            ->prefix('shop')
            ->name('shop.')
            ->group(__DIR__.'/../routes/shop.php');
    }
}
