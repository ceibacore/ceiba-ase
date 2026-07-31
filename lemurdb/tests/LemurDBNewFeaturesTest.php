<?php

/**
 * LemurDB Test Suite — v1.1.0
 *
 * Tests para los features nuevos:
 *   - JOIN  : join(), leftJoin(), rightJoin(), toJoinSql()
 *   - WHERE : whereRaw()
 *   - TRANS : transaction() — commit y rollback atómico
 *
 * Diseño: Tests unitarios puros sin conexión real a BD.
 * Las pruebas de JOIN y whereRaw validan la generación de SQL via toSql().
 * Las pruebas de transaction() usan un mock de PDO para verificar el
 * flujo commit/rollback sin necesidad de una base de datos.
 *
 * Uso: php tests/LemurDBNewFeaturesTest.php
 */

require_once __DIR__ . '/../lemurdb.php';

// ─────────────────────────────────────────────────────────────────────────────
// MICRO TEST RUNNER
// ─────────────────────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;
$total  = 0;

function test(string $name, bool $assertion, string $detail = ''): void
{
    global $passed, $failed, $total;
    $total++;
    if ($assertion) {
        $passed++;
        echo "  ✅ PASS  {$name}\n";
    } else {
        $failed++;
        $hint = $detail ? "         → {$detail}\n" : '';
        echo "  ❌ FAIL  {$name}\n{$hint}";
    }
}

function section(string $title): void
{
    $sep = str_repeat('─', 60);
    echo "\n{$sep}\n  {$title}\n{$sep}\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS — instancia LemurQuery directamente con un PDO stub (sin BD real)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Crea un LemurQuery sin conexión real usando un PDOStub.
 * Solo útil para probar la generación de SQL (toSql, toJoinSql, getParams).
 */
function makeQuery(string $table, string $prefix = ''): LemurQuery
{
    return new LemurQuery(new PdoStub(), $table, $prefix);
}

/**
 * PDO stub mínimo: permite instanciar LemurQuery sin conexión real.
 * No implementa ejecución — solo el contrato de tipo.
 */
class PdoStub extends PDO
{
    public function __construct()
    {
        // No llamamos a parent::__construct() a propósito — sin conexión real.
    }

    public function prepare($query, $options = []): \PDOStatement|false { return false; }
    public function beginTransaction(): bool { return true; }
    public function commit(): bool          { return true; }
    public function rollBack(): bool        { return true; }
}

// ─────────────────────────────────────────────────────────────────────────────
// MOCK PDO — para tests de transaction() con control de flujo
// ─────────────────────────────────────────────────────────────────────────────

class MockPDO extends PDO
{
    public array $calls = [];

    public function __construct() {}

    public function beginTransaction(): bool
    {
        $this->calls[] = 'beginTransaction';
        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = 'commit';
        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'rollBack';
        return true;
    }
}

/**
 * Crea un LemurDB usando reflexión para inyectar un MockPDO sin conexión real.
 * Esto permite probar transaction() de forma unitaria.
 */
function makeLemurDBWithMock(MockPDO $mock): LemurDB
{
    // Reset singleton para test aislado
    $ref = new ReflectionClass(LemurDB::class);
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

    // Instanciamos LemurDB con config falsa (no conectará porque MockPDO ya está inyectado)
    // Usamos reflexión para sobrescribir $pdo directamente
    $db = $ref->newInstanceWithoutConstructor();
    $pdoProp = $ref->getProperty('pdo');
    $pdoProp->setAccessible(true);
    $pdoProp->setValue($db, $mock);

    $configProp = $ref->getProperty('config');
    $configProp->setAccessible(true);
    $configProp->setValue($db, ['prefix' => '']);

    // Inyectamos en el singleton
    $prop->setValue(null, $db);

    return $db;
}

// Limpiamos el singleton al final de cada bloque de tests de transaction
function resetSingleton(): void
{
    $ref = new ReflectionClass(LemurDB::class);
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

// ═════════════════════════════════════════════════════════════════════════════
echo "\n🦎 LemurDB Test Suite — v1.1.0 (JOINs + Transactions + whereRaw)\n";
// ═════════════════════════════════════════════════════════════════════════════


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: join() — INNER JOIN');
// ─────────────────────────────────────────────────────────────────────────────

$q = makeQuery('orders')
    ->join('users', 'orders.user_id = users.id')
    ->select(['orders.id', 'users.name']);

$sql = $q->toSql();

test(
    'INNER JOIN aparece en toSql()',
    str_contains($sql, 'INNER JOIN users ON orders.user_id = users.id'),
    "SQL generado: {$sql}"
);

test(
    'toJoinSql() retorna solo la cláusula JOIN',
    $q->toJoinSql() === 'INNER JOIN users ON orders.user_id = users.id',
    "toJoinSql: " . $q->toJoinSql()
);

test(
    'SELECT respeta columnas con alias de tabla',
    str_contains($sql, 'SELECT orders.id, users.name FROM `orders`'),
    "SQL: {$sql}"
);


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: leftJoin()');
// ─────────────────────────────────────────────────────────────────────────────

$q = makeQuery('subscriptions')
    ->leftJoin('plans', 'subscriptions.plan_id = plans.id')
    ->select(['subscriptions.id', 'plans.name']);

$sql = $q->toSql();

test(
    'LEFT JOIN aparece en toSql()',
    str_contains($sql, 'LEFT JOIN plans ON subscriptions.plan_id = plans.id'),
    "SQL: {$sql}"
);

test(
    'toJoinSql() retorna LEFT JOIN',
    str_contains($q->toJoinSql(), 'LEFT JOIN'),
    $q->toJoinSql()
);


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: rightJoin()');
// ─────────────────────────────────────────────────────────────────────────────

$q = makeQuery('invoices')
    ->rightJoin('orders', 'invoices.order_id = orders.id')
    ->select('*');

$sql = $q->toSql();

test(
    'RIGHT JOIN aparece en toSql()',
    str_contains($sql, 'RIGHT JOIN orders ON invoices.order_id = orders.id'),
    "SQL: {$sql}"
);


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: JOINs múltiples encadenados');
// ─────────────────────────────────────────────────────────────────────────────

$q = makeQuery('ase_subscriptions')
    ->join('ase_plans', 'ase_subscriptions.plan_id = ase_plans.id')
    ->leftJoin('ase_invoices', 'ase_subscriptions.id = ase_invoices.subscription_id')
    ->leftJoin('ase_orders', 'ase_subscriptions.order_id = ase_orders.id')
    ->select(['ase_subscriptions.id', 'ase_plans.name', 'ase_invoices.total'])
    ->where(['ase_subscriptions.status' => 'active']);

$sql = $q->toSql();

test(
    'INNER JOIN + 2 LEFT JOINs en toSql()',
    str_contains($sql, 'INNER JOIN ase_plans')
    && str_contains($sql, 'LEFT JOIN ase_invoices')
    && str_contains($sql, 'LEFT JOIN ase_orders'),
    "SQL: {$sql}"
);

test(
    'WHERE se aplica después de los JOINs',
    str_contains($sql, 'WHERE `ase_subscriptions`.`status` = ?'),
    "SQL: {$sql}"
);

test(
    'getParams() contiene el valor del WHERE',
    $q->getParams() === ['active'],
    'Params: ' . json_encode($q->getParams())
);

test(
    'toJoinSql() lista los 3 JOINs separados por espacio',
    substr_count($q->toJoinSql(), 'JOIN') === 3,
    "toJoinSql: " . $q->toJoinSql()
);


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: JOIN + prefix de tabla');
// ─────────────────────────────────────────────────────────────────────────────

$q = makeQuery('orders', 'app_')
    ->join('users', 'app_orders.user_id = app_users.id')
    ->select('*');

$sql = $q->toSql();

test(
    'Prefix aplica en FROM pero no en JOIN (ON es responsabilidad del dev)',
    str_contains($sql, 'FROM `app_orders`'),
    "SQL: {$sql}"
);


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: whereRaw()');
// ─────────────────────────────────────────────────────────────────────────────

$q = makeQuery('ase_orders')
    ->whereRaw('canceled_at IS NULL')
    ->select('*');

$sql = $q->toSql();

test(
    'whereRaw() sin params genera condición IS NULL',
    str_contains($sql, 'WHERE canceled_at IS NULL'),
    "SQL: {$sql}"
);

test(
    'getParams() vacío para whereRaw sin parámetros',
    $q->getParams() === [],
    'Params: ' . json_encode($q->getParams())
);

// whereRaw con parámetros
$q = makeQuery('ase_orders')
    ->whereRaw('amount > ?', [100])
    ->whereRaw('expires_at < ?', ['2026-12-31'])
    ->select('*');

$sql = $q->toSql();

test(
    'Múltiples whereRaw() con parámetros generan AND por defecto',
    str_contains($sql, 'WHERE amount > ? AND expires_at < ?'),
    "SQL: {$sql}"
);

test(
    'getParams() contiene valores de todos los whereRaw()',
    $q->getParams() === [100, '2026-12-31'],
    'Params: ' . json_encode($q->getParams())
);

// whereRaw con OR
$q = makeQuery('ase_subscriptions')
    ->where(['status' => 'active'])
    ->whereRaw('trial_ends_at > NOW()', [], 'OR')
    ->select('*');

$sql = $q->toSql();

test(
    'whereRaw() con boolean OR genera cláusula OR',
    str_contains($sql, 'OR trial_ends_at > NOW()'),
    "SQL: {$sql}"
);

// whereRaw combinado con JOIN — el caso real de ASE
$q = makeQuery('ase_orders')
    ->join('ase_plan_prices', 'ase_orders.plan_price_id = ase_plan_prices.id')
    ->select(['ase_orders.id', 'ase_plan_prices.amount'])
    ->where(['ase_orders.status' => 'pending'])
    ->whereRaw('ase_orders.expires_at < NOW()');

$sql = $q->toSql();

test(
    'JOIN + where() + whereRaw() combinados (caso real: expirar órdenes)',
    str_contains($sql, 'INNER JOIN ase_plan_prices')
    && str_contains($sql, "`ase_orders`.`status` = ?")
    && str_contains($sql, 'AND ase_orders.expires_at < NOW()'),
    "SQL: {$sql}"
);


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: transaction() — commit exitoso');
// ─────────────────────────────────────────────────────────────────────────────

$mock = new MockPDO();
$db   = makeLemurDBWithMock($mock);

$result = $db->transaction(function (LemurDB $db) {
    // Simula trabajo sin ejecutar SQL real
    return 'result-ok';
});

test(
    'transaction() llama beginTransaction()',
    in_array('beginTransaction', $mock->calls),
    'Calls: ' . implode(', ', $mock->calls)
);

test(
    'transaction() llama commit() en éxito',
    in_array('commit', $mock->calls),
    'Calls: ' . implode(', ', $mock->calls)
);

test(
    'transaction() NO llama rollBack() en éxito',
    !in_array('rollBack', $mock->calls),
    'Calls: ' . implode(', ', $mock->calls)
);

test(
    'transaction() retorna el valor del callable',
    $result === 'result-ok',
    "Retornó: " . var_export($result, true)
);

resetSingleton();


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: transaction() — rollback en excepción');
// ─────────────────────────────────────────────────────────────────────────────

$mock = new MockPDO();
$db   = makeLemurDBWithMock($mock);

$exceptionCaught = false;
try {
    $db->transaction(function (LemurDB $db) {
        throw new \RuntimeException('Error simulado en webhook');
    });
} catch (\RuntimeException $e) {
    $exceptionCaught = true;
}

test(
    'transaction() llama beginTransaction() antes de ejecutar',
    in_array('beginTransaction', $mock->calls),
    'Calls: ' . implode(', ', $mock->calls)
);

test(
    'transaction() llama rollBack() cuando el callable lanza excepción',
    in_array('rollBack', $mock->calls),
    'Calls: ' . implode(', ', $mock->calls)
);

test(
    'transaction() NO llama commit() cuando hay excepción',
    !in_array('commit', $mock->calls),
    'Calls: ' . implode(', ', $mock->calls)
);

test(
    'transaction() re-lanza la excepción original',
    $exceptionCaught === true,
    'La excepción no fue propagada'
);

resetSingleton();


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: transaction() — retorna valor del callable');
// ─────────────────────────────────────────────────────────────────────────────

$mock = new MockPDO();
$db   = makeLemurDBWithMock($mock);

$returned = $db->transaction(fn(LemurDB $db) => ['rows_affected' => 3]);

test(
    'transaction() puede retornar arrays del callable',
    $returned === ['rows_affected' => 3],
    'Retornó: ' . json_encode($returned)
);

resetSingleton();


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: transaction() — caso real ASE (orquestación webhook)');
// ─────────────────────────────────────────────────────────────────────────────

$mock = new MockPDO();
$db   = makeLemurDBWithMock($mock);

// Simula lo que hará ProcessWebhook en ASE:
// Actualizar orden + crear subscription + crear invoice + crear log — todo atómico.
$simulatedOperations = [];

try {
    $db->transaction(function (LemurDB $db) use (&$simulatedOperations) {
        $simulatedOperations[] = 'update ase_orders status=paid';
        $simulatedOperations[] = 'insert ase_subscriptions';
        $simulatedOperations[] = 'insert ase_invoices';
        $simulatedOperations[] = 'insert ase_transactions_log';
        // La 5ta operación falla (ej: constraint violation)
        throw new \RuntimeException('Duplicate entry en transactions_log');
    });
} catch (\RuntimeException) {
    // esperado
}

test(
    'Rollback revierte las 4 operaciones del webhook al fallar la 5ta',
    in_array('rollBack', $mock->calls) && !in_array('commit', $mock->calls),
    'Calls: ' . implode(', ', $mock->calls)
);

test(
    'Las operaciones previas fueron simuladas pero el commit nunca ocurrió',
    count($simulatedOperations) === 4 && !in_array('commit', $mock->calls),
    'Operaciones: ' . implode(', ', $simulatedOperations)
);

resetSingleton();


// ─────────────────────────────────────────────────────────────────────────────
section('FEATURE: pdo() — acceso al PDO subyacente');
// ─────────────────────────────────────────────────────────────────────────────

$mock = new MockPDO();
$db   = makeLemurDBWithMock($mock);

test(
    'pdo() retorna la instancia PDO subyacente',
    $db->pdo() instanceof PDO,
    get_class($db->pdo())
);

test(
    'pdo() retorna el mismo mock inyectado',
    $db->pdo() === $mock,
    'No es la misma instancia'
);

resetSingleton();


// ─────────────────────────────────────────────────────────────────────────────
// RESUMEN FINAL
// ─────────────────────────────────────────────────────────────────────────────

$sep = str_repeat('═', 60);
echo "\n{$sep}\n";
echo "  RESULTADOS: {$passed}/{$total} tests pasaron";
if ($failed > 0) {
    echo "  ({$failed} fallaron)";
}
echo "\n{$sep}\n\n";

exit($failed > 0 ? 1 : 0);
