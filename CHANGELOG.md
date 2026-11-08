# Changelog

All notable changes to this project are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [1.2.0] - 2026-11-08

Security release for every payment integration.

### Upgrade notes
- **Initial payments are now verified against the order.** The provider must report an amount (in the order currency) at least equal to the order amount; trials may start at zero. Otherwise `PaymentVerificationException` is thrown and nothing is written.
- **PayPal approval no longer counts as payment.** Approved orders are captured (idempotently) by the webhook adapter and by `verifyOrder()`; only a COMPLETED capture activates. `PAYMENT.CAPTURE.COMPLETED` is now handled.
- Webhook time windows (default 900 s) are configurable per gateway: `transmission_tolerance` (PayPal), `signature_tolerance` (MercadoPago, Alipay). Alipay's signed path is configurable with `notify_path` (default `/webhooks/alipay`).
- The mock gateway is refused when `APP_ENV`/`ASE_ENV` is `production`, unless `ASE_ALLOW_MOCK_GATEWAY=true`.
- MercadoPago and Alipay idempotency keys now include the event status/type (`mercadopago:{id}:{status}`, `alipay:{type}:{id}`).

### Security
- Paid amount and currency are checked against the order before activation (blocks paying a low amount with the reference of an expensive order).
- An already paid order is never activated again: duplicate events no longer create extra subscriptions and invoices.
- PayPal: local pre-validation before any network call (certificate URL must be an https PayPal certificate endpoint of the right environment, `SHA256withRSA` only, transmission time window, transmission id format).
- MercadoPago: reject an empty webhook secret, enforce a time window on `ts`, validate payment ids before using them in API paths.
- Alipay/Antom: enforce a time window on `request-time`; the signature is verified over the exact raw body and configurable path.
- Stripe: `checkout.session.completed` activates only paid (or no-payment-required) sessions; `async_payment_succeeded`/`async_payment_failed` are handled.

### Fixed
- Stripe and Alipay amounts use ISO 4217 minor units (`MinorUnits`): zero-decimal currencies (JPY, CLP...) were charged 100 times the price, and three-decimal currencies were wrong.
- Stripe webhook amounts for checkout sessions are read from `amount_total` (they were always 0).
- Refunds use the payment's real currency (PayPal and Alipay always sent USD; Stripe now computes minor units from the charge or payment intent currency).
- Header values passed as arrays (`$request->headers->all()`, as ceiba-core does) are read correctly by every webhook adapter. PayPal verification always failed and MercadoPago raised a TypeError with them; Stripe failed with plain-string headers.
- `verifyOrder()` called a non-existent `Order::currency()`; only the mock adapter inherits the order amount now, and Alipay is labelled correctly.
- PayPal `parseWebhookEvent()` no longer reads an undefined `state` index.

### Tests
- 163 offline tests (from 148), including payment verification with in-memory repositories, the PayPal capture flow with a mocked HTTP transport (`HttpClientFactory::useHandler()`), and real Stripe/MercadoPago/Alipay signatures generated in the tests.

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
