# Changelog

All notable changes to this project are documented here. The project follows [Semantic Versioning](https://semver.org/).

## [1.7.0] - 2026-11-08

### Added
- Disputes of one-time payments: `payment.disputed` and `payment.dispute_closed` (outcome `won` or `lost`; a lost dispute sets the payment to `charged_back`). Mapped from Stripe, PayPal, Paystack and Razorpay. Each dispute is reported once.
- `AseManager::expireStalePayments($olderThanSeconds = 86400, $limit = 100)`: verifies stale pending payments with their provider (settling those whose webhook was lost) and expires the rest with `payment.expired`. An expired payment completed late is still settled.
- PayPal `PAYMENT.CAPTURE.REFUNDED` webhooks apply refunds of one-time payments, including refunds made in PayPal's dashboard.

### Changed
- Payment statuses: `expired` and `charged_back` were added. `PaymentRepositoryInterface` gains `findStalePending()`.

## [1.6.0] - 2026-11-08

### Added
- `AseManager::testGatewayCredentials($provider, $credentials)` and `CredentialProbeInterface`: hosts check credentials before enabling a gateway, for example an academy's own keys. Each built-in adapter makes a harmless call:

  | Adapter | Check |
  |---|---|
  | Stripe | `GET /v1/balance` |
  | PayPal | OAuth token |
  | MercadoPago | `GET /users/me` |
  | Paystack | `GET /balance` |
  | Razorpay | `GET /v1/payments?count=1` |
  | Alipay | RSA keys validated offline (Antom has no harmless call) |

  The result reports `livemode` when known. Incomplete credentials fail without calling the provider.

## [1.5.0] - 2026-11-08

One-time payments with a context, and gateways owned by accounts (an academy's own provider credentials).

### Upgrade notes
- Run the ASE migrations: `ase_gateways.account_ref` and the new `ase_payments` table.
- Existing gateways become platform gateways (`account_ref` NULL). `findAll()`, `findAllEnabled()`, `findByProvider()` and `getAllGateways()` now return platform gateways only. Custom `GatewayRepositoryInterface` implementations must add `findForAccount()` and `findByProviderForAccount()`.
- `WebhookUrls` resolvers receive `($provider, $context, $accountRef)`. One-argument resolvers keep working.
- Plan orders refuse gateways owned by an account.

### Added
- **Account-scoped gateways.** `upsertGateway(..., $accountRef)`, `getGatewayCredentialsByProvider($provider, $accountRef)` and `getAccountGateways($accountRef)`. `AccountRef` validates references such as `tenant:42`. The mock gateway is reserved for the platform.
- **One-time payments.**
  - `AseManager::createPayment(PaymentRequest)` stores the payment as pending and charges it through the account's gateway.
  - Also: `getPayment()`, `getPaymentsByReference()`, `refundPayment()` (partial or the remaining amount) and `verifyPayment()`.
- `Chargeable` and `OneTimePaymentGatewayInterface`. All built-in adapters implement it; plan orders and payments share each adapter's checkout. Stripe uses a payment-mode session with the payment id in the session and payment intent metadata.
- **Webhook routing by payment.** `handleWebhook($provider, $payload, $headers, $accountRef)`:
  - verifies the request with the credentials of the account behind the URL;
  - applies the event to the payment it refers to, which must belong to that account (`ProcessPaymentEvent`);
  - otherwise goes to the plan subscription flow, platform account only.
  - Events of an account that match no payment are acknowledged and ignored.
- **Payment events** `payment.succeeded`, `payment.failed` and `payment.refunded`, written to the outbox inside the payment transaction (`AseEventDispatcher::dispatchReliably`). Listeners receive the `outbox_event_id`.
- Settling requires the provider to report the payment currency and at least its amount (minor units). Each provider event and each refund id is applied once; API and webhook refunds are recognised as the same refund.
- `refund_reference` in normalized webhook data (Stripe payment intent, Razorpay payment link); Stripe refunds report `full_refund` and `refunded_total`.

## [1.4.0] - 2026-11-08

### Upgrade notes
- **Alipay needs the host's webhook URL.** Register it once at boot:
  `WebhookUrls::resolveUsing(fn (string $provider) => route('webhooks.ase', $provider));`
  Without it, Alipay checkout fails with a clear error instead of creating a payment whose result can never arrive.

### Added
- `WebhookUrls::resolveUsing()`, `for()` and `pathFor()`: the host supplies the public webhook URL of each provider. The URL must be an absolute http(s) URL without a query string.

### Fixed
- Alipay checkout sent `paymentNotifyUrl` as the success page (`{successUrl}?webhook=alipay`), so payment notifications never reached the webhook. It now sends the host's webhook URL.
- Alipay signature verification used a fixed `/webhooks/alipay` path. It now uses the path of that same webhook URL, so the URL that is sent and the path that is verified always match; an explicit `notify_path` credential still takes precedence. `AlipayAdapter::validateWebhook()` follows the same rule.
- Invalid Alipay signatures throw `WebhookSignatureException`, so hosts answer 401.

## [1.3.1] - 2026-11-08

### Fixed
- `WebhookManager::handle()` assigned `gateway_id` into the readonly `WebhookEvent::$data`. Every webhook processed through it failed with an Error, because `getGatewayCredentialsByProvider()` always returns `gateway_id`. Affects all providers since the WebhookManagement module was introduced. `WebhookEvent::withData()` now returns an enriched copy.

## [1.3.0] - 2026-11-08

### Added
- **Paystack** gateway (`paystack`). Checkout uses Paystack Standard with an amount in subunits and the order in `metadata.order_id`. Webhooks are verified with HMAC-SHA512 of the raw body using the secret key (`x-paystack-signature`). `charge.success` maps to INITIAL_PAYMENT. `refund.processed` maps to REFUND_PROCESSED; it is full when the refunds cover the transaction, which is checked against the API. Refunds are made in the transaction's own currency. Credentials: `secret_key`, `public_key`.
- **Razorpay** gateway (`razorpay`). Checkout uses Payment Links (`reference_id` = order id; the link id is the order's external id). Webhooks are verified with HMAC-SHA256 of the raw body using the dashboard webhook secret (`X-Razorpay-Signature`); a signature made with the API key secret is rejected. Idempotency uses `X-Razorpay-Event-Id`. `payment_link.paid` maps to INITIAL_PAYMENT and `refund.processed` to REFUND_PROCESSED. Credentials: `key_id`, `key_secret`, `webhook_secret`. Subscribe the webhook to `payment_link.paid` and `refund.processed`.
- Credential forms, adapter factories and webhook adapters for both gateways are registered by default.
- `CustomerContacts::resolveUsing(callable)`: the host supplies the customer's email and name for an `external_client_id`. Paystack requires the email; Razorpay uses it to prefill checkout.
- `WebhookSignatureException` (extends `\Exception`), thrown by the new webhook adapters for unauthentic requests, so hosts can answer 401.
- `RequestHeaders::get()` reads a header in any letter case, as a string or a list.

### Changed
- `ProcessRefund` finds the order by `internal_order_id` when the refunded transaction id is not the order's external id (Razorpay refunds point to the payment, not to the payment link).

### Notes
- Both gateways take one-time payments: `cancelSubscription()` and `pauseSubscription()` report that they are not supported, as Alipay does.

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
