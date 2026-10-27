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
use WebbyCrown\WebbyCommerceStatamic\Install\StoreInstaller;
use WebbyCrown\WebbyCommerceStatamic\Commands\InstallWebbyCommerce;
use WebbyCrown\WebbyCommerceStatamic\Commands\SeedWebbyCommerceDefaults;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Statamic;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $listen = [];

    protected $subscribe = [];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/webbycommerce.php', 'webbycommerce');

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

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallWebbyCommerce::class,
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
        $this->syncGlobalPaymentSettings();
        $this->createNavItems();
    }

    /**
     * Create missing store collections, blueprints, and settings.
     * Existing collections, including products and customers, are left as they are.
     */
    public function installStore(): void
    {
        (new StoreInstaller)->install();
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
            $paypalClientId = $variables->get('paypal_client_id');

            // Secrets (Stripe secret, webhook secret, PayPal secret) must come from
            // environment / config — never from Globals committed to content/.

            if ($defaultGateway) {
                config(['webbycommerce.payment.default_gateway' => $defaultGateway]);
            }

            if ($stripePublishableKey) {
                config(['webbycommerce.payment.gateways.stripe.publishable_key' => $stripePublishableKey]);
            }

            if ($paypalClientId) {
                config(['webbycommerce.payment.gateways.paypal.client_id' => $paypalClientId]);
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
