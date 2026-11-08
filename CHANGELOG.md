# Changelog

All notable changes to this project are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-08

### Upgrade notes
- Run the ASE migrations: this release adds `credit_balances`, `credit_ledger` and `outbox_events` (prefixed `ase_`).
- Requires `ceibacore/ceiba-db` ^1.1. That version accepts the `mariadb` driver name and validates column identifiers.

### Added
- Credit balances and ledger tables for white-label sublicense credits.
- Outbox persistence of subscription lifecycle events (`subscription.created`, `subscription.payment_failed`) through `AseEventDispatcher`.
- Stripe Checkout uses `subscription` mode with recurring price data for recurring plan prices, and names the line item after the plan.

### Fixed
- `verifyOrder` fills `internal_order_id`, `external_client_id`, amount, currency and the correct provider (Stripe, Mock, MercadoPago, PayPal) for initial payments.

### Security
- Stripe customer lookup uses the exact-match `email` list filter instead of the Search query language, so a crafted email can never match another customer. The email is validated before calling Stripe.
- Failures to read or store the Stripe customer mapping are logged instead of silently ignored (a missing mapping leads to duplicate customers).

### Reliability
- All outbound HTTP calls to PayPal, MercadoPago and Alipay use a 5 s connect timeout and a 20 s total timeout (`HttpDefaults`). Previously Guzzle waited indefinitely, so a degraded provider could hang checkouts and webhook workers.

### Known limitations
- The outbox is best effort: events are written after the business write, outside its transaction, and a failed write is logged without interrupting the operation. See the `AseEventDispatcher` docblock.
- PayPal webhook validation relies entirely on PayPal's verify API. There is no local pre-check of the certificate URL domain or of a replay window. Several existing tests named after those checks only passed because the fake credentials were rejected; they are now tagged `external`. Local pre-validation is planned.

### Tests
- The default suite now runs the Domain, Application, Unit, Infrastructure and Integration suites offline (148 tests, up from 9). The 24 tests that call the live Stripe/PayPal APIs are tagged `external` and excluded by default (`phpunit --group external` with real sandbox credentials).
- New tests for the outbox writer and for the Stripe customer lookup, using an offline HTTP client.

## [1.0.2]

- Previous release.
