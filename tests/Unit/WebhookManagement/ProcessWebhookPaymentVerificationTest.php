<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\WebhookManagement;

use CeibaDB;
use LemurAse\Domain\Entities\Gateway;
use LemurAse\Domain\Entities\Invoice;
use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\Entities\Plan;
use LemurAse\Domain\Entities\PlanPrice;
use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\Repositories\GatewayRepositoryInterface;
use LemurAse\Domain\Repositories\InvoiceRepositoryInterface;
use LemurAse\Domain\Repositories\OrderRepositoryInterface;
use LemurAse\Domain\Repositories\PlanPriceRepositoryInterface;
use LemurAse\Domain\Repositories\PlanRepositoryInterface;
use LemurAse\Domain\Repositories\SubscriptionRepositoryInterface;
use LemurAse\Domain\Repositories\TransactionLogRepositoryInterface;
use LemurAse\Domain\ValueObjects\Currency;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\WebhookManagement\Application\ProcessWebhookUseCase;
use LemurAse\WebhookManagement\Domain\PaymentVerificationException;
use LemurAse\WebhookManagement\Domain\WebhookEvent;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProcessWebhookPaymentVerificationTest extends TestCase
{
    private array $previousEnv = [];
    private MemoryStore $store;
    private ?string $previousLog = null;
    private PDO $pdo;

    protected function setUp(): void
    {
        $env = ['ASE_DB_HOST' => '127.0.0.1', 'ASE_DB_PORT' => '3306', 'ASE_DB_DATABASE' => 'ase_test', 'ASE_DB_PREFIX' => 'ase_',
            'ASE_DB_USERNAME' => 'test', 'ASE_DB_PASSWORD' => 'test', 'GATEWAY_SERVICE_SECRET' => 'test-secret'];
        foreach ($env as $key => $value) {
            $this->previousEnv[$key] = getenv($key);
            putenv("{$key}={$value}");
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE ase_outbox_events (id TEXT PRIMARY KEY, event_name TEXT, aggregate_type TEXT, aggregate_id TEXT,
            payload TEXT, status TEXT, attempts INTEGER, error_message TEXT, published_at TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE ase_transactions_log (id TEXT PRIMARY KEY, short_id TEXT, external_client_id TEXT, gateway_id TEXT,
            external_transaction_id TEXT, type TEXT, amount REAL, status TEXT, security_hash TEXT, raw_payload TEXT, created_at TEXT)');
        $this->pdo = $pdo;
        CeibaDB::setInstance($pdo, ['prefix' => 'ase_']);
        $this->previousLog = ini_set('error_log', '/dev/null') ?: null;
        $this->store = new MemoryStore();
    }

    protected function tearDown(): void
    {
        CeibaDB::resetInstances();
        ini_set('error_log', (string) $this->previousLog);
        foreach ($this->previousEnv as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
    }

    private function order(float $amount, string $currency, string $status = 'pending', int $trialDays = 0): Order
    {
        $plan = new Plan(EntityId::generate(), 'pro', 'Pro');
        $price = new PlanPrice(EntityId::generate(), $plan->id(), 'recurring', Money::create($amount, Currency::fromString($currency)), 'month', 1, $trialDays);
        $this->store->plans[$plan->id()->uuid()] = $plan;
        $this->store->prices[$price->id()->uuid()] = $price;
        $order = new Order(EntityId::generate(), 'client-1', $price->id(), EntityId::generate(),
            Money::create($amount, Currency::fromString($currency)), 'hash', $status);
        $this->store->orders[$order->id()->uuid()] = $order;
        return $order;
    }

    private function pay(Order $order, mixed $amount, string $currency, string $eventId): bool
    {
        $useCase = new ProcessWebhookUseCase(...$this->store->repositories());
        return $useCase->execute(new WebhookEvent('INITIAL_PAYMENT', [
            'internal_order_id' => $order->id()->uuid(), 'amount' => $amount, 'currency' => $currency,
            'external_subscription_id' => 'ext-sub', 'external_client_id' => 'client-1', 'gateway_id' => 'gw',
        ], $eventId, 'mercadopago', []));
    }

    private function assertRejected(callable $operation, string $message): void
    {
        try {
            $operation();
            $this->fail('Payment was applied: ' . $message);
        } catch (PaymentVerificationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testExactPaymentActivatesOnce(): void
    {
        $order = $this->order(49.90, 'EUR');
        $this->assertTrue($this->pay($order, 49.90, 'eur', 'evt-1'));
        $this->assertSame('paid', $order->status());
        $this->assertCount(1, $this->store->subscriptions);
        $this->assertCount(1, $this->store->invoices);

        // A second, distinct event for the same order (webhook plus manual verify) is a no-op.
        $this->assertTrue($this->pay($order, 49.90, 'EUR', 'evt-2'));
        $this->assertCount(1, $this->store->subscriptions, 'No duplicate subscription');
        $this->assertCount(1, $this->store->invoices);
        $this->assertSame(['subscription.activated'],
            $this->pdo->query('SELECT event_name FROM ase_outbox_events')->fetchAll(PDO::FETCH_COLUMN), 'One activation event');
    }

    public function testUnderpaymentWrongCurrencyAndMissingAmountAreRejected(): void
    {
        $order = $this->order(500.00, 'USD');
        $this->assertRejected(fn () => $this->pay($order, 1.00, 'USD', 'evt-a'), 'underpaid');
        $this->assertRejected(fn () => $this->pay($order, 499.99, 'USD', 'evt-b'), 'one cent short');
        $this->assertRejected(fn () => $this->pay($order, 500.00, 'ARS', 'evt-c'), 'other currency');
        $this->assertRejected(fn () => $this->pay($order, null, 'USD', 'evt-d'), 'no amount');
        $this->assertSame('pending', $order->status());
        $this->assertSame([], $this->store->subscriptions);
        $this->assertSame([], $this->store->logs, 'Rejected events are not logged as processed');

        $this->assertTrue($this->pay($order, 500.00, 'USD', 'evt-e'));
        $this->assertSame('paid', $order->status());
    }

    public function testZeroDecimalCurrencyAndTrialStart(): void
    {
        $yen = $this->order(5000, 'JPY');
        $this->assertTrue($this->pay($yen, 5000, 'JPY', 'evt-jpy'));
        $this->assertSame('paid', $yen->status());

        $trial = $this->order(20.00, 'USD', 'pending', 14);
        $this->assertTrue($this->pay($trial, 0, 'USD', 'evt-trial'));
        $this->assertSame('trialing', array_values($this->store->subscriptions)[1]->status());
    }

    public function testSignedPaystackChargeActivatesTheOrderOnlyWhenFullyPaid(): void
    {
        $secret = 'sk_test_paystack_e2e_secret';
        $event = function (Order $order, int $kobo, string $id) use ($secret) {
            $raw = json_encode(['event' => 'charge.success', 'data' => [
                'id' => $id, 'status' => 'success', 'reference' => "ase-{$id}", 'amount' => $kobo, 'currency' => 'NGN',
                'metadata' => ['order_id' => $order->id()->uuid(), 'external_client_id' => 'client-1'],
            ]]);
            return (new \LemurAse\WebhookManagement\Infrastructure\Adapters\PaystackWebhookAdapter())
                ->parse($raw, ['x-paystack-signature' => [hash_hmac('sha512', $raw, $secret)]], ['secret_key' => $secret]);
        };
        $useCase = new ProcessWebhookUseCase(...$this->store->repositories());

        $order = $this->order(15000.00, 'NGN');
        $this->assertRejected(fn () => $useCase->execute($event($order, 1499999, 'short')), 'one kobo short');
        $this->assertTrue($useCase->execute($event($order, 1500000, 'full')));
        $this->assertSame('paid', $order->status());
        $this->assertCount(1, $this->store->subscriptions);
    }

    public function testRazorpayRefundFindsTheOrderThroughItsReference(): void
    {
        $order = $this->order(1499.00, 'INR', 'paid');
        $secret = 'razorpay-webhook-e2e-secret';
        $raw = json_encode(['event' => 'refund.processed', 'payload' => [
            'refund'  => ['entity' => ['id' => 'rfnd_9', 'payment_id' => 'pay_9', 'amount' => 149900, 'currency' => 'INR',
                'notes' => ['order_id' => $order->id()->uuid()]]],
            'payment' => ['entity' => ['id' => 'pay_9', 'amount' => 149900, 'amount_refunded' => 149900, 'currency' => 'INR']],
        ]]);
        $event = (new \LemurAse\WebhookManagement\Infrastructure\Adapters\RazorpayWebhookAdapter())->parse($raw,
            ['x-razorpay-signature' => hash_hmac('sha256', $raw, $secret), 'x-razorpay-event-id' => 'evt_r9'], ['webhook_secret' => $secret]);

        // The order's external id is the payment link, not pay_9: the refund is matched by order id.
        $this->assertTrue((new ProcessWebhookUseCase(...$this->store->repositories()))->execute($event));
        $this->assertSame('refunded', $order->status());
        $this->assertSame(['razorpay:refund:rfnd_9'],
            $this->pdo->query("SELECT external_transaction_id FROM ase_transactions_log WHERE type = 'refund'")->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testFinalOrdersAreNotReactivatedButLatePaymentsActivate(): void
    {
        $refunded = $this->order(10.00, 'USD', 'refunded');
        $this->assertRejected(fn () => $this->pay($refunded, 10.00, 'USD', 'evt-r'), 'refunded order');

        $expired = $this->order(10.00, 'USD', 'expired');
        $this->assertTrue($this->pay($expired, 10.00, 'USD', 'evt-x'));
        $this->assertSame('paid', $expired->status());
    }
}

/** Shared in-memory state for the repository fakes below. */
final class MemoryStore
{
    public array $orders = [];
    public array $subscriptions = [];
    public array $invoices = [];
    public array $logs = [];
    public array $prices = [];
    public array $plans = [];

    /** @return array{0: OrderRepositoryInterface, 1: SubscriptionRepositoryInterface, 2: InvoiceRepositoryInterface, 3: TransactionLogRepositoryInterface, 4: PlanPriceRepositoryInterface, 5: PlanRepositoryInterface, 6: GatewayRepositoryInterface} */
    public function repositories(): array
    {
        $s = $this;
        return [
            new class ($s) implements OrderRepositoryInterface {
                public function __construct(private MemoryStore $s) {}
                public function save(Order $order): void { $this->s->orders[$order->id()->uuid()] = $order; }
                public function findById(EntityId $id): ?Order { return $this->s->orders[$id->uuid()] ?? null; }
                public function findExpired(\DateTimeImmutable $now): array { return []; }
                public function findByExternalTransactionId(string $externalTxId): ?Order { return null; }
            },
            new class ($s) implements SubscriptionRepositoryInterface {
                public function __construct(private MemoryStore $s) {}
                public function save(Subscription $subscription): void { $this->s->subscriptions[$subscription->id()->uuid()] = $subscription; }
                public function findById(EntityId $id): ?Subscription { return $this->s->subscriptions[$id->uuid()] ?? null; }
                public function findByExternalId(string $externalId): ?Subscription { return null; }
                public function findByOrderId(EntityId $orderId): ?Subscription { return null; }
                public function findByClientAndStatus(string $clientId, string $status): array { return []; }
            },
            new class ($s) implements InvoiceRepositoryInterface {
                public function __construct(private MemoryStore $s) {}
                public function save(Invoice $invoice): void { $this->s->invoices[$invoice->id()->uuid()] = $invoice; }
                public function findById(EntityId $id): ?Invoice { return $this->s->invoices[$id->uuid()] ?? null; }
                public function getNextInvoiceNumber(string $externalClientId): string { return 'INV-' . (count($this->s->invoices) + 1); }
                public function findByClient(string $clientId): array { return []; }
            },
            new class ($s) implements TransactionLogRepositoryInterface {
                public function __construct(private MemoryStore $s) {}
                public function log(array $data): void { $this->s->logs[$data['external_transaction_id']] = $data; }
                public function exists(string $externalTransactionId): bool { return isset($this->s->logs[$externalTransactionId]); }
            },
            new class ($s) implements PlanPriceRepositoryInterface {
                public function __construct(private MemoryStore $s) {}
                public function save(PlanPrice $planPrice): void { $this->s->prices[$planPrice->id()->uuid()] = $planPrice; }
                public function findById(EntityId $id): ?PlanPrice { return $this->s->prices[$id->uuid()] ?? null; }
            },
            new class ($s) implements PlanRepositoryInterface {
                public function __construct(private MemoryStore $s) {}
                public function save(Plan $plan): void { $this->s->plans[$plan->id()->uuid()] = $plan; }
                public function findById(EntityId $id): ?Plan { return $this->s->plans[$id->uuid()] ?? null; }
                public function findAll(bool $onlyActive = false): array { return []; }
                public function findBySlug(string $slug): ?Plan { return null; }
                public function existsBySlug(string $slug): bool { return false; }
            },
            new class implements GatewayRepositoryInterface {
                public function save(Gateway $gateway): void {}
                public function findById(EntityId $id): ?Gateway { return null; }
                public function findAll(): array { return []; }
                public function findAllEnabled(): array { return []; }
                public function findByProvider(string $provider): ?Gateway { return null; }
                public function findForAccount(?string $accountRef): array { return []; }
                public function findByProviderForAccount(string $provider, ?string $accountRef, bool $activeOnly = true): ?Gateway { return null; }
            },
        ];
    }
}
