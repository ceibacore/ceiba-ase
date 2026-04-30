# Agnostic Subscription Engine (ASE)

> A framework-agnostic, self-contained PHP module for managing subscriptions, billing, and payments — designed to be dropped into any PHP project (Laravel, Symfony, WordPress, or vanilla PHP) without coupling to its host's ORM, routing layer, or payment provider.

---

## Overview

**ASE** solves the recurring problem of rebuilding subscription logic from scratch every time a new SaaS or LMS product is launched. It provides a standardized, portable billing layer that:

- Works with **any PHP >= 8.1** project as a "copy-and-drop" module
- Supports **multiple payment gateways** (Stripe, PayPal, MercadoPago) via adapters
- Handles the **full order lifecycle**: checkout → payment → subscription → invoice → audit log
- Enforces **security by design** with HMAC-signed checkout sessions
- Guarantees **idempotent webhook processing** — no duplicate charges or subscriptions
- Persists everything through **LemurDB**, its own embedded query layer (no Eloquent, no Doctrine)

---

## Architecture at a Glance

Built on **Hexagonal Architecture (Ports & Adapters)** and **Domain-Driven Design (DDD)**:

```
┌─────────────────────────────────────────────────────────┐
│                   HOST APPLICATION                        │
│  (Laravel / Symfony / WordPress / Vanilla PHP)           │
│                                                           │
│  AseManager::createCheckoutSession($clientId, $planId)   │
│  AseManager::getClientDashboardData($clientId)           │
│  AseManager::expireStaleOrders()                         │
│  AseManager::checkHealth()                               │
└────────────────────────┬────────────────────────────────┘
                         │ Bridge / Service Provider
┌────────────────────────▼────────────────────────────────┐
│                     ASE MODULE                           │
│                                                           │
│  Application Layer (Use Cases)                           │
│  ├── CreateOrder          ← UC-02: checkout session       │
│  ├── ProcessWebhook       ← UC-03: atomic 4-table write   │
│  ├── GetClientDashboard   ← UC-04: data retrieval         │
│  └── ExpireStaleOrders    ← UC-05: cleanup job            │
│                                                           │
│  Domain Layer (Pure Business Logic)                       │
│  ├── Entities: Plan, Price, Order, Subscription, Invoice  │
│  └── ValueObjects: EntityId (UUID+short_id), Money, HMAC  │
│                                                           │
│  Infrastructure Layer (Adapters)                          │
│  ├── Persistence → LemurDB (own embedded query layer)     │
│  ├── Payments    → StripeAdapter, PayPalAdapter           │
│  └── Http        → Webhook receivers (PSR-7)              │
└─────────────────────────────────────────────────────────┘
```

---

## Database Schema (8 tables)

All tables use the `ase_` prefix to avoid collisions with the host system. Every table carries a UUID v4 primary key (`id CHAR(36)`) and a human-readable `short_id CHAR(12)` for support references and invoice numbers.

```
Catalog:
  ase_plans            → logical product / service
    └── ase_plan_prices    → prices per billing interval (monthly, annual, one-time)
            └── ase_custom_prices  → VIP / per-client overrides

Configuration:
  ase_gateways         → Stripe, PayPal, MercadoPago credentials (encrypted)

Transaction Lifecycle:
  ase_orders           → every checkout attempt (status: pending → paid/failed/expired)
    ├── ase_subscriptions  → active access record (recurring AND one-time)
    ├── ase_invoices       → human-readable billing document (per-client numbering)
    └── ase_transactions_log → immutable audit trail with raw gateway payload
```

**Full schema documentation:** [`docs-ia/ase/Diseño de Esquemas de Base de Datos.md`](../docs-ia/ase/Diseño%20de%20Esquemas%20de%20Base%20de%20Datos.md)

---

## Order Lifecycle

```
[Client initiates checkout]
        │
        ▼
  ase_orders  status: 'pending'
  (security_hash persisted, amount locked)
        │
        ├── [No payment before expires_at]  ──► status: 'expired'  (UC-05 cron)
        ├── [Gateway rejects]               ──► status: 'failed'
        └── [Gateway approves — webhook]    ──► status: 'paid'
                │
                ├── ase_subscriptions created (recurring or fixed-duration)
                ├── ase_invoices created  (INV-CLI-007-00003 format)
                └── ase_transactions_log appended (raw_payload stored here)
```

---

## Key Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Primary Key | UUID v4 `CHAR(36)` | Distributed-safe, no sequential exposure |
| Human reference | `short_id CHAR(12)` | Last 12 hex chars of UUID's final section |
| Invoice numbering | Per-client sequential | Fiscal / accounting compliance |
| `one_time` payments | Create fixed-duration subscription | Unified access model — host always checks `ase_subscriptions` |
| `raw_payload` location | `ase_transactions_log` only | Keeps operational tables lean |
| Checkout security | HMAC SHA-256 persisted in `ase_orders` | Prevents amount/plan tampering in frontend |
| Webhook idempotency | `external_transaction_id UNIQUE` in log | Duplicate events are silently ignored |

---

## Module Structure

```
lemur-ase/
├── lemurdb/                    ← Embedded query layer (LemurDB v1.1.0)
│   ├── lemurdb.php             ← LemurDB + LemurQuery classes
│   ├── README.md               ← LemurDB documentation
│   └── tests/
│       └── LemurDBNewFeaturesTest.php
│
├── Config/                     ← Environment variable loader (isolated from host)
├── Migrations/                 ← Multi-environment migration engine
│   ├── laravel/
│   ├── pure_sql/               ← Raw .sql files for 8 tables
│   └── symfony/
├── src/
│   ├── Domain/
│   │   ├── Entities/           ← Plan, Price, Order, Subscription, Invoice, Transaction
│   │   ├── ValueObjects/       ← EntityId (UUID + short_id), Money, Currency, SecurityHash
│   │   ├── Repositories/       ← Interface definitions (ports)
│   │   └── Gateways/           ← PaymentGatewayInterface
│   ├── Application/
│   │   ├── DTOs/
│   │   └── UseCases/           ← CreateOrder, ProcessWebhook, CalculatePrice,
│   │                              ExpireStaleOrders, GetClientDashboard
│   ├── Infrastructure/
│   │   ├── Persistence/        ← LemurDB repository implementations
│   │   ├── Payments/           ← StripeAdapter, PayPalAdapter
│   │   └── Http/               ← PSR-7 webhook receivers
│   └── Shared/
│       ├── LemurInstance.php   ← LemurDB singleton wrapper
│       └── SecurityGuard.php   ← HMAC generation & validation
└── UI/                         ← Agnostic HTML/CSS error views
```

---

## Embedded Dependency: LemurDB

ASE uses **LemurDB** — a minimal PDO-backed query builder included directly in this repository (no Composer dependency). This keeps the module self-contained and avoids version conflicts with the host system's packages.

**LemurDB v1.1.0 provides everything ASE needs:**

| ASE Operation | LemurDB API |
|---|---|
| Insert order / subscription / invoice / log | `insert()` |
| Find by `external_client_id` + `status` | `where()->get()` |
| Update order/subscription status | `where()->update()` |
| Dashboard multi-table JOINs | `join()` / `leftJoin()` |
| `expires_at < NOW()` in ExpireStaleOrders | `whereRaw()` |
| Atomic 4-table webhook write | `transaction(callable)` |
| First-row check (idempotency) | `first()` |

→ Full LemurDB documentation: [`lemurdb/README.md`](lemurdb/README.md)

---

## Development Roadmap

| Phase | Focus | Status |
|---|---|---|
| **Phase 1** | Architecture skeleton, LemurDB setup, Migrations (8 tables), `EntityId` ValueObject | 🔄 In progress |
| **Phase 2** | Domain entities, use cases, price calculator, security service | ⏳ Pending |
| **Phase 3** | Gateway adapters (Stripe, PayPal), webhook processing, idempotency | ⏳ Pending |
| **Phase 4** | Host bridge (`AseManager` facade), Laravel Service Provider, UI views | ⏳ Pending |
| **Phase 5** | QA, load testing, HMAC attack simulation, technical manual | ⏳ Pending |

→ Full roadmap: [`docs-ia/ase/Plan de Desarrollo y Roadmap.md`](../docs-ia/ase/Plan%20de%20Desarrollo%20y%20Roadmap.md)

---

## Integration Example (Laravel)

```php
// In your Laravel controller — no Eloquent, no direct SQL
use AseManager;

// Create a checkout session (creates ase_orders internally)
$checkout = AseManager::createCheckoutSession(
    clientId: auth()->id(),
    planPriceId: 'plan-price-uuid-here'
);

// Redirect client to payment
return redirect($checkout->checkoutUrl);

// ─────────────────────────────────────────────────────────
// In your webhook route — Stripe calls this automatically
Route::post('/ase/webhooks/stripe', fn() => AseManager::handleWebhook('stripe'));
// ASE validates signature, processes atomically, returns HTTP 200

// ─────────────────────────────────────────────────────────
// In your dashboard controller
$data = AseManager::getClientDashboardData(auth()->id());
// Returns: active subscription, invoice list, period dates
```

---

## Security

- **HMAC SHA-256** signs every checkout session. The hash is persisted in `ase_orders` and re-validated when the webhook arrives. Any amount or plan tampering in the frontend is blocked.
- **Native PDO prepared statements** via LemurDB — SQL injection is not possible through the query builder API.
- **Gateway credentials** are stored encrypted (AES-256 at application level) in `ase_gateways.credentials`. LemurDB never receives credentials in plaintext.
- **Idempotent webhooks** — `external_transaction_id` has a UNIQUE constraint in `ase_transactions_log`. Duplicate events from the gateway are silently ignored (HTTP 200 returned).

---

## Requirements

- PHP >= 8.1
- PDO extension with `pdo_mysql` driver
- MySQL / MariaDB
- No Composer dependencies for core module *(LemurDB is embedded)*
- `ramsey/uuid` optional — `EntityId` can fallback to `com_create_guid()` on PHP 8.1+

---

## Documentation

All design documents live in [`docs-ia/ase/`](../docs-ia/ase/):

| Document | Contents |
|---|---|
| [Documento de Arquitectura de Software.md](../docs-ia/ase/Documento%20de%20Arquitectura%20de%20Software.md) | Hexagonal architecture, LemurDB gap analysis, EntityId pattern |
| [Diseño de Esquemas de Base de Datos.md](../docs-ia/ase/Diseño%20de%20Esquemas%20de%20Base%20de%20Datos.md) | Full 8-table schema with all fields, types, indexes |
| [Especificación de Casos de Uso.md](../docs-ia/ase/Especificación%20de%20Casos%20de%20Uso.md) | UC-01 through UC-05 with flows and alternates |
| [Plan de Desarrollo y Roadmap.md](../docs-ia/ase/Plan%20de%20Desarrollo%20y%20Roadmap.md) | 5-phase roadmap with milestones and Git Flow strategy |

---

## License

MIT — © Lemur Bookstores
