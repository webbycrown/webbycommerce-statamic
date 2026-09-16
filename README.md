# WebbyCommerce for Statamic

A Statamic 5 add-on for managing products, orders, customers, coupons, taxes, shipping, cart, wishlist, and checkout through collections, globals, Antlers tags, and `/shop` JSON endpoints.

## Overview

- Collection-based store data: `products`, `orders`, `customers`, `coupons`, `tax_rates`, `tax_zones`, `tax_categories`
- Shipping methods live in the **`webbycommerce_settings` Globals** (`shipping_locations`), not a separate collection
- Storefront API under `/shop` for cart, checkout, coupons, wishlist, and shipping options
- Control Panel menu for WebbyCommerce collections and settings
- Stripe charging when `STRIPE_SECRET_KEY` is set; bank transfer creates **unpaid pending** orders

## Requirements

- Statamic 5 (`statamic/cms: ^5.0`)
- PHP 8.1+
- Composer

## Installation

### From GitHub (supported)

Add a VCS repository to your project `composer.json`, then require the tagged release:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/webbycrown/webbycommerce-statamic"
  }
]
```

```bash
composer require webbycrown/webbycommerce-statamic:^1.1
```

Verified against release tag `v1.1.0`.

### From Packagist

When the package is listed on Packagist as `webbycrown/webbycommerce-statamic`, you can install without a VCS repository:

```bash
composer require webbycrown/webbycommerce-statamic:^1.1
```

### Local path development

```json
"repositories": [
  {
    "type": "path",
    "url": "addons/webbycrown/webbycommerce-statamic"
  }
]
```

```bash
composer require webbycrown/webbycommerce-statamic:@dev
```

### Publish assets

```bash
php artisan vendor:publish --tag=webbycommerce-config
php artisan vendor:publish --tag=webbycommerce-views
php artisan vendor:publish --tag=webbycommerce-email-templates
php artisan vendor:publish --tag=webbycommerce-blueprints
```

### Clear caches

```bash
php artisan optimize:clear
php please stache:refresh
```

## Payments (important)

WebbyCommerce does **not** mark card or PayPal checkouts as paid unless a charge is verified.

| Method | Behaviour |
| --- | --- |
| **Stripe** (`stripe` / `credit_card`) | Requires `STRIPE_SECRET_KEY` and a client-created **`stripe_token`** (Stripe.js / Elements). Card numbers and CVVs must never be posted to your server. Charge must succeed before `is_paid` / `payment_status: paid`. |
| **PayPal** | Not implemented for live capture in this release. Checkout returns an error instead of faking payment. |
| **Bank transfer** | Order is created as **pending / unpaid** for manual reconciliation. |
| **Webhooks** | Stripe webhooks require `STRIPE_WEBHOOK_SECRET` and a valid `Stripe-Signature`. Unsigned or unverified requests are rejected. |

Put secrets in `.env` only. Do not store secret keys in Globals (they are often committed with content).

### Stripe.js token (required for card checkout)

Collect card details only in the browser with [Stripe.js](https://stripe.com/docs/js). Send the resulting token to checkout:

```js
// after Stripe.js createToken / createPaymentMethod → token.id
fetch('/shop/checkout/complete', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrfToken,
    Accept: 'application/json',
  },
  body: JSON.stringify({
    payment_method: 'stripe',
    stripe_token: token.id,
  }),
});
```

Requests that include `card_number` or `card_cvv` are rejected.

```dotenv
WEBBYCOMMERCE_CURRENCY=USD
WEBBYCOMMERCE_PAYMENT_GATEWAY=stripe
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Optional redirect / callback URL overrides:

```dotenv
WEBBYCOMMERCE_PAYMENT_REDIRECT_URL=https://example.com/shop/checkout/success/{orderNumber}
WEBBYCOMMERCE_PAYMENT_CALLBACK_URL=https://example.com/shop/payment/callback?order={orderNumber}
WEBBYCOMMERCE_PAYMENT_WEBHOOK_URL=https://example.com/shop/payment/webhook
```

## Routes

Base prefix: `/shop`

| Method | URI | Description |
| --- | --- |
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
| POST | `/shop/payment/webhook` | Verified Stripe webhook endpoint |
| GET | `/shop/payment/redirect` | Payment redirect endpoint |
| GET | `/shop/search` | Product search |

API endpoints:

| Method | URI | Description |
| --- | --- |
| GET | `/shop/api/cart` | Get cart contents |
| GET | `/shop/api/cart/count` | Cart count |
| POST | `/shop/api/coupon/validate` | Validate coupon |
| POST | `/shop/api/coupon/apply` | Apply coupon |
| GET | `/shop/api/shipping/methods` | Shipping options |
| GET | `/shop/api/wishlist` | Wishlist contents |
| GET | `/shop/api/wishlist/count` | Wishlist count |

This add-on ships CP + API + Antlers tags. Storefront page templates belong in your theme or starter kit.

## Antlers tags

- `{{ webbycommerce:countries }}` / `{{ webbycommerce:regions country="US" }}`
- `{{ cart }}`, `{{ cart:items }}`, `{{ cart:count }}`, `{{ cart:subtotal }}`, `{{ cart:total }}`, `{{ cart:tax }}`, `{{ cart:shipping }}`, …
- `{{ checkout:field key="email" }}`, `{{ checkout:payment key="method" }}`, …
- `{{ product_tag }}`, `{{ wishlist:count }}`, `{{ wishlist:items }}`, `{{ wishlist:has product_id="..." }}`

Example:

```antlers
{{ cart }}
  {{ total }}
  {{ items }}
    {{ name }} — {{ quantity }} × {{ price }}
  {{ /items }}
{{ /cart }}
```

## Seed default tax / shipping data

```bash
php artisan webbycommerce:seed-defaults
php artisan webbycommerce:seed-defaults --force
```

Shipping defaults are written into Globals `shipping_locations`.

## Configuration

Published file: `config/webbycommerce.php` (also merged from the package on boot).

Globals set: `webbycommerce_settings` (store name/email, shipping locations, publishable keys, email toggles).

See `THIRD_PARTY.md` for bundled third-party materials.

## Support

- GitHub Issues: https://github.com/webbycrown/webbycommerce-statamic/issues
- Changelog: [CHANGELOG.md](CHANGELOG.md)

Community support via GitHub Issues unless you have a separate WebbyCrown support agreement.

## Important files

| File | Purpose |
| --- | --- |
| `src/ServiceProvider.php` | Routes, collections, permissions, config merge |
| `routes/shop.php` | Storefront routes |
| `config/webbycommerce.php` | Package configuration |
| `resources/blueprints/` | Collection and globals blueprints |
| `src/Http/Controllers/Shop/CheckoutController.php` | Checkout and payment logic |

---

Made by [WebbyCrown Solutions](https://www.webbycrown.com/)
