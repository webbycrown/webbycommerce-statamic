# Changelog

All notable changes to this project will be documented in this file.

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
