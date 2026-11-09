<?php

declare(strict_types=1);

namespace LemurAse\Tests\Integration\Payments;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LemurAse\AseManager;
use LemurAse\Domain\ValueObjects\PaymentRequest;
use LemurAse\Infrastructure\Customers\CustomerContacts;
use LemurAse\Infrastructure\Events\AseEventDispatcher;
use LemurAse\Infrastructure\Http\HttpClientFactory;
use LemurAse\Tests\Support\AseSqliteSchema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Disputes (chargebacks) and expiry of one-time payments, on the real schema: each one
 * reaches the host through the outbox exactly once.
 */
final class DisputesAndExpiryTest extends TestCase
{
    private const ACADEMY = 'tenant:7';
    private const SECRET = 'sk_test_academy7_0123456789abcdef0123456789';

    private PDO $pdo;
    private MockHandler $http;
    private ?string $previousLog = null;

    protected function setUp(): void
    {
        $this->pdo = AseSqliteSchema::create();
        $this->http = new MockHandler();
        HttpClientFactory::useHandler(HandlerStack::create($this->http));
        CustomerContacts::resolveUsing(fn (string $id) => ['email' => "{$id}@students.test", 'name' => 'Student']);
        $this->previousLog = ini_set('error_log', '/dev/null') ?: null;
        AseManager::upsertGateway('paystack', ['secret_key' => self::SECRET, 'public_key' => 'pk_test_academy7'], true, self::ACADEMY);
    }

    protected function tearDown(): void
    {
        HttpClientFactory::useHandler(null);
        CustomerContacts::resolveUsing(null);
        AseEventDispatcher::forget();
        AseSqliteSchema::reset();
        ini_set('error_log', (string) $this->previousLog);
    }

    private function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function start(string $reference): string
    {
        $this->http->append($this->json(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/' . $reference, 'reference' => 'ref-' . $reference,
        ]]));

        return AseManager::createPayment(new PaymentRequest(
            context: 'marketplace', reference: $reference, externalClientId: 'student-5', amount: 15000.00, currency: 'NGN',
            provider: 'paystack', description: 'Curso', successUrl: 'https://campus.test/ok', cancelUrl: 'https://campus.test/no',
            accountRef: self::ACADEMY,
        ))['payment_id'];
    }

    private function send(string $event, array $data): bool
    {
        $raw = json_encode(['event' => $event, 'data' => $data]);

        return AseManager::handleWebhook('paystack', $raw, ['x-paystack-signature' => hash_hmac('sha512', $raw, self::SECRET)], self::ACADEMY);
    }

    private function pay(string $paymentId, string $reference, int $eventId): void
    {
        $this->send('charge.success', ['id' => $eventId, 'status' => 'success', 'reference' => 'ref-' . $reference,
            'amount' => 1500000, 'currency' => 'NGN', 'metadata' => ['order_id' => $paymentId]]);
    }

    private function dispute(string $event, string $paymentId, string $reference, int $disputeId, array $extra = []): bool
    {
        return $this->send($event, $extra + ['id' => $disputeId, 'currency' => 'NGN', 'refund_amount' => 1500000, 'category' => 'fraud',
            'transaction' => ['reference' => 'ref-' . $reference, 'amount' => 1500000, 'currency' => 'NGN', 'metadata' => ['order_id' => $paymentId]]]);
    }

    /** @return array<int, array{0: string, 1: array}> */
    private function outbox(): array
    {
        return array_map(fn (array $r) => [$r['event_name'], json_decode($r['payload'], true)],
            $this->pdo->query('SELECT event_name, payload FROM ase_outbox_events ORDER BY created_at, rowid')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testADisputeIsReportedOnceAndAWonDisputeKeepsThePayment(): void
    {
        $id = $this->start('order-1');
        $this->pay($id, 'order-1', 1);

        $this->dispute('charge.dispute.create', $id, 'order-1', 77);
        $this->dispute('charge.dispute.create', $id, 'order-1', 77); // provider retry
        $this->dispute('charge.dispute.resolve', $id, 'order-1', 77, ['resolution' => 'declined']);

        $events = $this->outbox();
        $this->assertSame(['payment.succeeded', 'payment.disputed', 'payment.dispute_closed'], array_column($events, 0));
        $this->assertSame(['paystack:77', 1500000, 'fraud'],
            [$events[1][1]['dispute_id'], $events[1][1]['dispute_minor'], $events[1][1]['reason']]);
        $this->assertSame(['won', 'paid'], [$events[2][1]['outcome'], AseManager::getPayment($id)['status']]);
    }

    public function testALostDisputeMarksThePaymentChargedBack(): void
    {
        $id = $this->start('order-2');
        $this->pay($id, 'order-2', 2);

        // The opening event never arrived: the closing one is enough.
        $this->dispute('charge.dispute.resolve', $id, 'order-2', 78, ['resolution' => 'merchant-accepted']);
        $this->dispute('charge.dispute.resolve', $id, 'order-2', 78, ['resolution' => 'merchant-accepted']);

        $closed = array_values(array_filter($this->outbox(), fn ($e) => $e[0] === 'payment.dispute_closed'));
        $this->assertCount(1, $closed);
        $this->assertSame(['lost', 1500000, 'charged_back'], [$closed[0][1]['outcome'], $closed[0][1]['dispute_minor'], $closed[0][1]['status']]);
        $this->assertSame('charged_back', AseManager::getPayment($id)['status']);
        $this->assertSame('lost', AseManager::getPayment($id)['metadata']['disputes']['paystack:78']['status']);
    }

    public function testDisputesOfUnpaidPaymentsAreIgnored(): void
    {
        $id = $this->start('order-3');
        $this->dispute('charge.dispute.create', $id, 'order-3', 79);

        $this->assertSame([], array_values(array_filter($this->outbox(), fn ($e) => $e[0] === 'payment.disputed')));
    }

    public function testStalePaymentsAreVerifiedThenExpiredAndALateChargeStillSettles(): void
    {
        $abandoned = $this->start('order-4');
        $paidLate = $this->start('order-5');
        $fresh = $this->start('order-6');
        $this->pdo->exec("UPDATE ase_payments SET created_at = '2026-01-01 00:00:00' WHERE id IN ('{$abandoned}', '{$paidLate}')");

        // Verification with the provider: order-4 abandoned; order-5 was paid (webhook lost).
        $this->http->append(
            $this->json(['status' => true, 'data' => ['status' => 'abandoned', 'reference' => 'ref-order-4']]),
            $this->json(['status' => true, 'data' => ['status' => 'success', 'reference' => 'ref-order-5', 'amount' => 1500000,
                'currency' => 'NGN', 'metadata' => ['order_id' => $paidLate]]]),
        );
        $this->assertSame(['verified_paid' => 1, 'expired' => 1], AseManager::expireStalePayments(3600));

        $this->assertSame(['expired', 'paid', 'pending'], [AseManager::getPayment($abandoned)['status'],
            AseManager::getPayment($paidLate)['status'], AseManager::getPayment($fresh)['status']]);
        $this->assertEqualsCanonicalizing(['payment.succeeded', 'payment.expired'], array_column($this->outbox(), 0));
        $this->assertSame(['verified_paid' => 0, 'expired' => 0], AseManager::expireStalePayments(3600), 'Nothing left');

        // The student completes the abandoned checkout after all: the charge is honoured.
        $this->pay($abandoned, 'order-4', 40);
        $this->assertSame('paid', AseManager::getPayment($abandoned)['status']);
    }

    public function testMockPaymentsExpireWithoutAskingTheMock(): void
    {
        AseManager::upsertGateway('mock', [], true);
        $id = AseManager::createPayment(new PaymentRequest(
            context: 'marketplace', reference: 'order-9', externalClientId: 'student-5', amount: 10.00, currency: 'USD',
            provider: 'mock', description: 'Curso', successUrl: 'https://campus.test/ok', cancelUrl: 'https://campus.test/no',
        ))['payment_id'];
        $this->pdo->exec("UPDATE ase_payments SET created_at = '2026-01-01 00:00:00' WHERE id = '{$id}'");

        $this->assertSame(['verified_paid' => 0, 'expired' => 1], AseManager::expireStalePayments(3600));
        $this->assertSame('expired', AseManager::getPayment($id)['status']);
    }
}
