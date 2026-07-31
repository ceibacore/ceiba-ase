# LemurDB

A minimal, lightweight PHP database wrapper that exposes a fluent query builder backed by PDO.

**Current version: v1.1.0**

---

## Requirements

- PHP 8.x
- PDO extension enabled
- A supported PDO driver (e.g., `pdo_mysql`, `pdo_pgsql`)

---

## Installation

Copy `LemurDB.php` into your project and require it:

```php
require_once 'LemurDB.php';
```

---

## Getting Started

LemurDB uses a **Singleton pattern** — the connection is created once and reused across your application.

```php
$db = LemurDB::getInstance([
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'db'       => 'my_database',
    'username' => 'root',
    'password' => 'secret',
    'prefix'   => 'app_',   // optional table prefix
]);
```

> ⚠️ Pass the config only on the **first** call. Subsequent `getInstance()` calls return the existing connection.

---

## Query Builder — `LemurQuery`

All queries start with `$db->query('table_name')`, which returns a chainable `LemurQuery` instance.

### `select()`

```php
// Select all columns (default)
$db->query('users')->select()->get();

// Select specific columns
$db->query('users')->select(['id', 'name', 'email'])->get();

// Raw select string
$db->query('users')->select('id, name, email')->get();
```

---

### `where()` / `orWhere()`

```php
// Single AND condition
$db->query('users')
    ->where(['status' => 'active'])
    ->get();

// Multiple AND conditions
$db->query('users')
    ->where(['status' => 'active', 'role' => 'admin'])
    ->get();

// OR condition
$db->query('users')
    ->where(['status' => 'active'])
    ->orWhere(['status' => 'pending'])
    ->get();
```

---

### `like()` / `orLike()`

```php
// AND LIKE
$db->query('products')
    ->like('name', '%cable%')
    ->get();

// OR LIKE
$db->query('products')
    ->like('name', '%cable%')
    ->orLike('description', '%cable%')
    ->get();
```

---

### `between()` / `orBetween()`

```php
// AND BETWEEN
$db->query('orders')
    ->between('total', 100, 500)
    ->get();

// OR BETWEEN
$db->query('orders')
    ->between('total', 100, 500)
    ->orBetween('total', 1000, 2000)
    ->get();
```

---

### `orderby()`

```php
$db->query('users')
    ->orderby('created_at DESC')
    ->get();
```

---

### `limit()`

```php
// First 10 rows
$db->query('users')->limit(10)->get();

// Rows 21–30 (pagination: offset 20, limit 10)
$db->query('users')->limit(10, 20)->get();
```

---

## Chaining Example

```php
$results = $db->query('products')
    ->select(['id', 'name', 'price'])
    ->where(['status' => 'active'])
    ->like('name', '%usb%')
    ->between('price', 5, 100)
    ->orderby('price ASC')
    ->limit(20, 0)
    ->get();
```

---

## JOIN Support

Chain `join()`, `leftJoin()`, or `rightJoin()` before `select()` or `where()`.

```php
// INNER JOIN
$db->query('orders')
    ->join('users', 'orders.user_id = users.id')
    ->select(['orders.id', 'orders.total', 'users.name'])
    ->where(['orders.status' => 'paid'])
    ->get();

// LEFT JOIN
$db->query('subscriptions')
    ->leftJoin('invoices', 'subscriptions.id = invoices.subscription_id')
    ->select(['subscriptions.id', 'invoices.total'])
    ->get();

// RIGHT JOIN
$db->query('invoices')
    ->rightJoin('orders', 'invoices.order_id = orders.id')
    ->select('*')
    ->get();

// Multiple JOINs chained
$db->query('ase_subscriptions')
    ->join('ase_plans', 'ase_subscriptions.plan_id = ase_plans.id')
    ->leftJoin('ase_invoices', 'ase_subscriptions.id = ase_invoices.subscription_id')
    ->leftJoin('ase_orders', 'ase_subscriptions.order_id = ase_orders.id')
    ->select(['ase_subscriptions.id', 'ase_plans.name', 'ase_invoices.total'])
    ->where(['ase_subscriptions.status' => 'active'])
    ->get();
```

> **Note on table prefix:** The prefix is applied automatically to the `FROM` table. For JOIN tables and `ON` conditions, use the full table name (with prefix) explicitly in the `on` string.

---

## `whereRaw()` — Raw WHERE Conditions

Use for complex expressions that the fluent API cannot express: `IS NULL`, `IS NOT NULL`, `IN (...)`, `NOW()`, subqueries, etc.

```php
// IS NULL check
$db->query('ase_orders')
    ->whereRaw('canceled_at IS NULL')
    ->get();

// With bound parameters
$db->query('ase_orders')
    ->whereRaw('amount > ?', [100])
    ->whereRaw('expires_at < ?', ['2026-12-31'])
    ->get();
// → WHERE amount > ? AND expires_at < ?
// Params: [100, '2026-12-31']

// OR connector
$db->query('ase_subscriptions')
    ->where(['status' => 'active'])
    ->whereRaw('trial_ends_at > NOW()', [], 'OR')
    ->get();
// → WHERE status = ? OR trial_ends_at > NOW()

// Combined with JOINs (real ASE use case: expire pending orders)
$db->query('ase_orders')
    ->join('ase_plan_prices', 'ase_orders.plan_price_id = ase_plan_prices.id')
    ->where(['ase_orders.status' => 'pending'])
    ->whereRaw('ase_orders.expires_at < NOW()')
    ->get();
```

---

## Atomic Transactions

Wrap multiple write operations in a single atomic transaction. On success, changes are committed. On any exception, all changes are rolled back automatically.

```php
// Basic usage
$db->transaction(function (LemurDB $db) {
    $db->query('orders')->insert(['status' => 'paid', ...]);
    $db->query('invoices')->insert(['total' => 99.00, ...]);
    // If any insert throws, both are rolled back
});

// The callable can return a value
$result = $db->transaction(function (LemurDB $db) {
    $db->query('ase_orders')
       ->where(['id' => $orderId])
       ->update(['status' => 'paid']);

    $db->query('ase_subscriptions')->insert([...]);
    $db->query('ase_invoices')->insert([...]);
    $db->query('ase_transactions_log')->insert([...]);

    return 'webhook_processed';
});
// $result === 'webhook_processed'
```

> **Rollback on failure:** If any statement inside the callable throws a `Throwable`, `rollBack()` is called automatically and the exception is re-thrown for the caller to handle.

---

## Debugging — `toSql()` and `getParams()`

Inspect the generated SQL and bound parameters before execution:

```php
$query = $db->query('users')
    ->select(['id', 'name'])
    ->where(['status' => 'active'])
    ->limit(5);

echo $query->toSql();
// SELECT id, name FROM app_users WHERE status = ? LIMIT 0, 5

print_r($query->getParams());
// Array ( [0] => active )
```

---

## Table Prefix

If a `prefix` is set in the config, it is automatically prepended to all table names:

```php
// Config: 'prefix' => 'app_'
$db->query('users');
// Queries the table: app_users
```

---

## PDO Configuration

LemurDB configures PDO with these defaults out of the box:

| Option | Value |
|---|---|
| Error mode | `ERRMODE_EXCEPTION` |
| Fetch mode | `FETCH_ASSOC` |
| Emulate prepares | `false` (native prepared statements) |
| Charset | `utf8mb4` |

---

## Architecture

```
LemurDB  (Singleton)
  ├─► transaction(callable) ← atomic commit/rollback wrapper
  ├─► pdo()                 ← exposes underlying PDO (advanced use)
  └─► LemurQuery  (Fluent builder)
        ├─ join()      / leftJoin()  / rightJoin()   ← JOIN clauses
        ├─ select()
        ├─ where()     / orWhere()
        ├─ whereRaw()                                ← raw conditions
        ├─ like()      / orLike()
        ├─ between()   / orBetween()
        ├─ orderby()
        ├─ limit()
        ├─ get()       ← executes SELECT, returns all rows
        ├─ first()     ← executes SELECT, returns single row
        ├─ insert()    ← single row INSERT
        ├─ insertBatch()  ← multi-row INSERT
        ├─ update()    ← UPDATE (requires WHERE)
        ├─ delete()    ← DELETE (requires WHERE)
        ├─ truncate()  ← TRUNCATE TABLE
        ├─ toSql()        ← SELECT SQL string (debug)
        ├─ toJoinSql()    ← JOIN clauses only (debug)
        ├─ toUpdateSql()  ← UPDATE SQL string (debug)
        ├─ toDeleteSql()  ← DELETE SQL string (debug)
        ├─ toInsertSql()  ← INSERT SQL string (debug)
        └─ getParams()    ← bound params (debug)
```

---

## Security

- All user-supplied values are **bound as parameters** via PDO prepared statements — SQL injection is not possible through the query builder API.
- Native prepared statements are enforced (`ATTR_EMULATE_PREPARES => false`).

---

## Limitations

- **No subquery support** — complex subqueries in SELECT or WHERE must use `whereRaw()` with raw SQL strings.
- **JOIN ON is raw** — the `ON` condition is not parameterized; use column names only (no user input).
- Only tested with **MySQL/MariaDB**. PostgreSQL and SQLite may require DSN adjustments.

---

## License

MIT
