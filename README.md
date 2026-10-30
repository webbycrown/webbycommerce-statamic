# WebbyCommerce for Statamic

A Statamic 6 add-on for managing products, orders, customers, coupons, taxes, shipping, cart, wishlist, and checkout through collections, globals, Antlers tags, and `/shop` JSON endpoints.

## Overview

- Collection-based store data: `products`, `orders`, `customers`, `coupons`, `tax_rates`, `tax_zones`, `tax_categories`
- Shipping methods live in the **`webbycommerce_settings` Globals** (`shipping_locations`), not a separate collection
- Storefront API under `/shop` for cart, checkout, coupons, wishlist, and shipping options
- Control Panel menu for WebbyCommerce collections and settings
- Stripe Payment Intents when `STRIPE_SECRET_KEY` is set; bank transfer creates **unpaid pending** orders

## Requirements

- Statamic 6 (`statamic/cms: ^6.0`)
- PHP 8.3+
- Composer

## Installation

Marketplace and Composer installs use Packagist. No VCS repository entry is required.

```bash
composer require webbycrown/webbycommerce-statamic
php please webbycommerce:install
```

That constraint resolves `webbycrown/webbycommerce-statamic` `^1.2`, including `v1.2.0` and later. The package requires Statamic 6.

`webbycommerce:install` creates the store collections, blueprints, and settings once. It does not change a products or customers collection the site already has. Boot no longer writes those files.

Orders and customers are saved as flat-file entries under `content/collections/orders` and `content/collections/customers`. Those files include names, email addresses, and addresses. Keep them out of Git.

Control Panel access follows Statamic's collection permissions. A role that can view products cannot open orders or customers unless that role also has those collection permissions.

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

WebbyCommerce does **not** mark a card checkout as paid unless the Stripe PaymentIntent status is `succeeded`. PayPal is not a payment method.

| Method | Behaviour |
| --- | --- |
| **Stripe** (`stripe` / `credit_card`) | Requires `STRIPE_SECRET_KEY`. The browser confirms the card with Stripe.js and sends a PaymentMethod id (`pm_...`) or, after authentication, a PaymentIntent id (`pi_...`). Card numbers and CVVs must never be posted to the server. If the intent needs Strong Customer Authentication, checkout returns `requires_action` and `client_secret` and does **not** mark the order paid. The browser finishes that step with `stripe.confirmCardPayment`, then sends the PaymentIntent id. The order is paid only after Stripe reports `succeeded` for the same amount and currency, and only when that intent is not already stored on another order. A declined or failed card attempt clears the Stripe idempotency key so the next attempt can create a new PaymentIntent. Amounts use that currency's minor units: dollars are sent as cents, and yen are sent as yen. The currency is the Control Panel currency. If the payment succeeds and the order still cannot be saved, WebbyCommerce refunds that PaymentIntent and writes its id to the log. |
| **Bank transfer** | Order is created as **pending / unpaid** for manual reconciliation. |
| **Webhooks** | `POST /shop/payment/webhook` is outside the `web` middleware, so Laravel's CSRF protection does not reject Stripe. When `STRIPE_WEBHOOK_SECRET` is set, the route checks `Stripe-Signature`. A verified `payment_intent.succeeded` event marks the matching order paid when the amount and currency match. `payment_intent.payment_failed` marks an unpaid order failed. A paid order is not changed to unpaid. |

Put secrets in `.env` only. Do not store secret keys in Globals (they are often committed with content).

### Stripe.js PaymentIntent (required for card checkout)

Collect card details only in the browser with [Stripe.js](https://stripe.com/docs/js). Create a PaymentMethod, then confirm the PaymentIntent when Stripe asks for authentication:

```js
const { paymentMethod, error } = await stripe.createPaymentMethod({
  type: 'card',
  card: cardElement,
});
if (error) throw error;

const started = await fetch('/shop/checkout/complete', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrfToken,
    Accept: 'application/json',
  },
  body: JSON.stringify({
    payment_method: 'stripe',
    stripe_payment_method: paymentMethod.id,
  }),
});
const body = await started.json();

if (body.requires_action) {
  const confirmed = await stripe.confirmCardPayment(body.client_secret);
  if (confirmed.error) throw confirmed.error;
  await fetch('/shop/checkout/complete', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
      Accept: 'application/json',
    },
    body: JSON.stringify({
      payment_method: 'stripe',
      payment_intent: confirmed.paymentIntent.id,
    }),
  });
}
```

Requests that include `card_number` or `card_cvv` are rejected. Stripe's 3D Secure test card `4000002500003155` returns `requires_action` until `confirmCardPayment` succeeds.

Point Stripe at `POST /shop/payment/webhook` and set `STRIPE_WEBHOOK_SECRET` to the endpoint signing secret. `stripe listen --forward-to https://example.com/shop/payment/webhook` is the local check: a signed `payment_intent.succeeded` event changes that order's `payment_status` to `paid`.

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
| --- | --- | --- |
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
| POST | `/shop/payment/webhook` | Stripe webhook. No CSRF middleware. A verified `payment_intent.succeeded` event marks the matching order paid. |
| GET | `/shop/payment/redirect` | Payment redirect endpoint |
| GET | `/shop/search` | Product search |

API endpoints:

| Method | URI | Description |
| --- | --- | --- |
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
| `src/ServiceProvider.php` | Routes and config merge |
| `src/Install/StoreInstaller.php` | Collections, blueprints, and settings created by `webbycommerce:install` |
| `src/Commands/InstallWebbyCommerce.php` | Install command |
| `routes/shop.php` | Storefront routes |
| `config/webbycommerce.php` | Package configuration |
| `resources/blueprints/` | Collection and globals blueprints |
| `src/Http/Controllers/Shop/CheckoutController.php` | Checkout and payment logic |

---

Made by [WebbyCrown Solutions](https://www.webbycrown.com/)
