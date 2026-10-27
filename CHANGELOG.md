# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

### Fixed

- `php please webbycommerce:install` creates the store collections. The command calls the installer directly, so a fresh site no longer fails while Laravel resolves the service provider.
- AJAX checkout (`/shop/checkout/complete`) uses the coupon already stored in the session when the request does not send `coupon_code` again. The discount cannot exceed the subtotal, so a large fixed coupon cannot make the charge negative.

### Changed

- `/shop/products` no longer includes `cost_price`.
- A checkout failure returns a generic message. The exception text stays in the log.

## 1.1.4 — 2026-10-26

### Fixed

- A Stripe charge is refunded, and its charge id is logged, when the order cannot be saved.
- Collections, blueprints, and settings are created by `php please webbycommerce:install`. Boot no longer writes them, and an existing products or customers collection is left unchanged.

### Removed

- Unused `WebbycommerceService` classes and `resources/statamic-vue/index.html`.

## 1.1.3 — 2026-10-26

### Fixed

- The form checkout route checks the coupon against the current cart before it calculates the total. A discount stored in the session is no longer reused after items are removed.

### Docs

- The README notes that the Stripe Charges flow does not support 3D Secure, and that `/shop/payment/webhook` is a placeholder rejected by CSRF unless the route is excluded.

## 1.1.2 — 2026-10-11

### Fixed

- Checkout totals, saved orders, and the Stripe charge no longer add tax that is already included in the product price.
- Product stock uses the Control Panel `stock` and `track_stock` fields. Cart and both checkout routes reject unavailable quantities and deduct stock when an order is accepted.

## 1.1.1 — 2026-09-16

### Fixed

- Stripe checkout requires a client `stripe_token` only; raw card fields are rejected (PCI).
- Empty-cart and missing-token checkout paths return clear error responses.

## 1.1.0 — 2026-09-16

### Fixed

- Checkout no longer marks Stripe or PayPal orders as paid without a verified capture (fail closed when gateways are missing or incomplete).
- Stripe webhooks require a valid signing secret and signature; payloads are no longer echoed.
- Payment secrets are loaded from environment / config only (not Globals).
- Composer constraint pinned to `statamic/cms: ^5.0`; package config is merged on boot.
- Removed debug `dump()` from the defaults seeder and broken lang/migration path loads.

### Docs

- README updated for marketplace honesty: shipping via Globals, install options, support path, payment limitations.
- Added `LICENSE`, `CHANGELOG.md`, and `THIRD_PARTY.md`.

## 1.0.0 — 2026-06-01

- Initial release.
