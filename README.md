# WebbyCommerce for Statamic

A lightweight Statamic Webby Commerce addon for managing products, orders, customers, coupons, taxes, shipping, cart, wishlist, and checkout.

## Overview

- Collection-based WebbyCommerce data: `products`, `orders`, `customers`, `coupons`, `tax_rates`, `shipping_rates`
- Built-in storefront endpoints under `/shop`
- AJAX-friendly checkout with shipping, tax, coupon, and payment gateway support
- Configurable payment redirect/callback/webhook URLs
- Statamic Control Panel integration with a dedicated WebbyCommerce menu

## Requirements

- Statamic 5
- PHP version supported by Statamic 5
- Composer
- Node.js / npm for asset rebuilds (optional)

## Installation

### Composer

```bash
composer require webbycrown/webbycommerce-statamic
```

### Local development

Add a path repository to your project `composer.json`:

```json
"repositories": [
  {
    "type": "path",
    "url": "addons/webbycrown/webbycommerce-statamic"
  }
]
```

Then install:

```bash
composer require webbycrown/webbycommerce-statamic:@dev
```

### Publish package assets

```bash
php artisan vendor:publish --provider="WebbyCrown\WebbyCommerceStatamic\ServiceProvider"
```

Optional tags:

```bash
php artisan vendor:publish --tag=webbycommerce-config
php artisan vendor:publish --tag=webbycommerce-views
php artisan vendor:publish --tag=webbycommerce-email-templates
php artisan vendor:publish --tag=webbycommerce-blueprints
```

Legacy `webbycommerce-*` publish tags are also supported for backwards compatibility.

### Clear caches

```bash
php artisan optimize:clear
php please stache:clear
```

## Environment Settings

Add or update these variables in your `.env`:

```dotenv
WEBBYCOMMERCE_CURRENCY=USD
WEBBYCOMMERCE_PAYMENT_GATEWAY=stripe
WEBBYCOMMERCE_PAYMENT_REDIRECT_URL=https://example.com/shop/checkout/success/{orderNumber}
WEBBYCOMMERCE_PAYMENT_CALLBACK_URL=https://example.com/shop/payment/callback?order={orderNumber}
WEBBYCOMMERCE_PAYMENT_WEBHOOK_URL=https://example.com/shop/payment/webhook
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
```

Gateway-specific overrides are also supported:

```dotenv
STRIPE_REDIRECT_URL=https://example.com/shop/checkout/success/{orderNumber}
STRIPE_CALLBACK_URL=https://example.com/shop/payment/callback?order={orderNumber}
STRIPE_WEBHOOK_URL=https://example.com/shop/payment/webhook
```

## Routes

Base prefix: `/shop`

| Method | URI | Description |
|---|---|---|
| GET | `/shop/products` | Product listing |
| GET | `/shop/products/{slug}` | Product detail |
| GET | `/shop/cart` | Cart page |
| POST | `/shop/cart/add` | Add item |
| POST | `/shop/cart/update` | Update item |
| POST | `/shop/cart/remove/{key}` | Remove item |
| POST | `/shop/checkout/process` | Synchronous checkout |
| POST | `/shop/checkout/complete` | Complete checkout (AJAX) |
| GET | `/shop/checkout/success/{orderNumber}` | Order success page |
| GET/POST | `/shop/payment/callback` | Payment callback endpoint |
| POST | `/shop/payment/webhook` | Payment webhook endpoint |
| GET | `/shop/payment/redirect` | Payment redirect endpoint |
| GET | `/shop/search` | Product search |

API endpoints:

| Method | URI | Description |
|---|---|---|
| GET | `/shop/api/cart` | Get cart contents |
| GET | `/shop/api/cart/count` | Cart count |
| POST | `/shop/api/coupon/validate` | Validate coupon |
| POST | `/shop/api/coupon/apply` | Apply coupon |
| GET | `/shop/api/shipping/methods` | Shipping options |
| GET | `/shop/api/wishlist` | Wishlist contents |
| GET | `/shop/api/wishlist/count` | Wishlist count |

## Antlers Tags

The addon provides the following Antlers tags under the `webbycommerce` namespace:

- `{{ webbycommerce:countries }}` - list countries with optional `only`, `exclude`, and `common` parameters.
- `{{ webbycommerce:regions country="US" }}` - list regions for a country by ISO or name.
- `{{ cart }}` - returns the current cart as an array.
- `{{ cart:has }}` - returns whether the cart contains items.
- `{{ cart:items }}` - returns cart items.
- `{{ cart:count }}` - returns total cart item count.
- `{{ cart:total_quantity }}` - returns total quantity of items in the cart.
- `{{ cart:subtotal }}` - returns cart subtotal.
- `{{ cart:total }}` - returns cart total.
- `{{ cart:tax }}` - returns total tax.
- `{{ cart:shipping }}` - returns shipping cost.
- `{{ cart:shipping_breakdown }}` - returns shipping breakdown.
- `{{ cart:discount }}` - returns discount amount.
- `{{ cart:tax_breakdown }}` - returns tax breakdown.
- `{{ cart:tax_rate }}` - returns the applicable tax rate.
- `{{ cart:is_tax_included }}` - returns whether tax is included.
- `{{ checkout:field key="email" default="" }}` - retrieves checkout field values from old input or session.
- `{{ checkout:payment key="method" default="" }}` - retrieves checkout payment values from old input or session.
- `{{ checkout:coupon_code }}` - returns the current coupon code.
- `{{ checkout:coupon_discount }}` - returns the current coupon discount.
- `{{ checkout:shipping_same_as_billing }}` - returns whether shipping is same as billing.
- `{{ checkout:countries }}` - loads checkout country data.
- `{{ checkout:states country="US" }}` - loads checkout states for a given country.
- `{{ product_tag }}` - returns product collection results as `results`.
- `{{ wishlist:count }}` - returns wishlist item count.
- `{{ wishlist:items }}` - returns wishlist items.
- `{{ wishlist:has product_id="..." }}` - returns whether a product is in the wishlist.

Example:

```antlers
{{ cart }}
  {{ total }}
  {{ items }}
    {{ title }}
  {{ /items }}
{{ /cart }}
```

## Seed Default Data

Create starter entries for taxes and shipping rates:

```bash
php artisan webbycommerce:seed-defaults
```

Use `--force` to skip confirmation:

```bash
php artisan webbycommerce:seed-defaults --force
```

## Configuration

The main configuration file is `config/webbycommerce.php`.

Key configuration areas:

- `currency`
- `shipping`
- `products`
- `orders`
- `customers`
- `coupons`
- `cart`
- `payment`

## Store Settings

The addon also supports store-level globals in `content/globals/webbycommerce_settings.yaml`:

```yaml
store_name: My Store
store_email: admin@example.com
```

- `store_name` is mapped to `config('webbycommerce.store.name')` and is used in email templates.
- `store_email` is mapped to `config('webbycommerce.store.email')` and is used as the fallback contact address in email notifications.

## Order Confirmation Email Setup

Order confirmation emails are controlled by both the global settings and the package config.

### Global settings

Update `content/globals/webbycommerce_settings.yaml` or the Statamic Control Panel global set:

```yaml
email_order_confirmation_enabled: true
email_order_confirmation_to_customer: true
email_order_confirmation_to_admin: true
email_order_confirmation_admin_email: admin@example.com
```

> If `email_order_confirmation_admin_email` is blank, the addon falls back to `store_email`.

### Package config

The addon maps the globals into `config/webbycommerce.php` under:

```php
'emails' => [
    'order_confirmation' => [
        'enabled' => true,
        'to_customer' => true,
        'to_admin' => false,
        'admin_email' => env('WEBBYCOMMERCE_ADMIN_EMAIL'),
    ],
    'order_shipped' => [
        'enabled' => true,
    ],
],
```

- `enabled` turns confirmation emails on or off.
- `to_customer` sends the email to the customer.
- `to_admin` sends the email to the admin email address.
- `admin_email` is the admin recipient address.

After changing settings, run:

```bash
php artisan optimize:clear
```

## Important Files

| File | Purpose |
|---|---|
| `src/ServiceProvider.php` | Register routes, collections, permissions, and commands |
| `routes/shop.php` | Storefront route definitions |
| `config/webbycommerce.php` | Package configuration |
| `resources/blueprints/collections` | Statamic blueprints |
| `src/Cart/Cart.php` | Cart service |
| `src/Wishlist/Wishlist.php` | Wishlist service |
| `src/Http/Controllers/Shop/CheckoutController.php` | Checkout and payment logic |

---
<div align="center">
  <strong>Made with ❤️ by <a href="https://www.webbycrown.com/statamic-addon-development-services/">WebbyCrown Solutions</a></strong>
</div>
