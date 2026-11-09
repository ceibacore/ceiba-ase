<?php

declare(strict_types=1);

namespace LemurAse\Tests\Integration\Payments;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LemurAse\AseManager;
use LemurAse\Domain\ValueObjects\PaymentRequest;
use LemurAse\Infrastructure\Customers\CustomerContacts;
use LemurAse\Infrastructure\Events\AseEventDispatcher;
use LemurAse\Infrastructure\Http\HttpClientFactory;
use LemurAse\Infrastructure\Http\WebhookUrls;
use LemurAse\Tests\Support\AseSqliteSchema;
use LemurAse\WebhookManagement\Domain\PaymentVerificationException;
use LemurAse\WebhookManagement\Domain\WebhookSignatureException;
use PDO;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * One-time payments with a context, charged through account-scoped gateways (platform or
 * an academy's own credentials), on the real schema (every migration, SQLite).
 */
final class PaymentsWithContextTest extends TestCase
{
    private const ACADEMY = 'tenant:7';
    private const OTHER_ACADEMY = 'tenant:8';
    private const ACADEMY_SECRET = 'sk_test_academy7_0123456789abcdef0123456789';
    private const OTHER_SECRET = 'sk_test_academy8_0123456789abcdef0123456789';

    private PDO $pdo;
    private MockHandler $http;
    private array $sent = [];
    private ?string $previousLog = null;

    protected function setUp(): void
    {
        $this->pdo = AseSqliteSchema::create();
        $this->http = new MockHandler();
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->sent));
        HttpClientFactory::useHandler($stack);
        CustomerContacts::resolveUsing(fn (string $id) => ['email' => "{$id}@students.test", 'name' => 'Student']);
        $this->previousLog = ini_set('error_log', '/dev/null') ?: null;

        AseManager::upsertGateway('paystack', ['secret_key' => self::ACADEMY_SECRET, 'public_key' => 'pk_test_academy7'], true, self::ACADEMY);
        AseManager::upsertGateway('paystack', ['secret_key' => self::OTHER_SECRET, 'public_key' => 'pk_test_academy8'], true, self::OTHER_ACADEMY);
    }

    protected function tearDown(): void
    {
        HttpClientFactory::useHandler(null);
        CustomerContacts::resolveUsing(null);
        WebhookUrls::resolveUsing(null);
        AseEventDispatcher::forget();
        AseSqliteSchema::reset();
        ini_set('error_log', (string) $this->previousLog);
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function startPayment(float $amount = 15000.00, string $account = self::ACADEMY, string $reference = 'order-41'): array
    {
        $this->http->append($this->json(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/pay-' . $reference, 'reference' => 'ase-ref-' . $reference,
        ]]));

        return AseManager::createPayment(new PaymentRequest(
            context: 'marketplace', reference: $reference, externalClientId: 'student-5', amount: $amount, currency: 'NGN',
            provider: 'paystack', description: 'Curso de Laravel', successUrl: 'https://campus.test/ok',
            cancelUrl: 'https://campus.test/cancel', accountRef: $account, metadata: ['tenant_id' => 7],
        ));
    }

    private function charge(string $paymentId, int $kobo, int $eventId, string $secret = self::ACADEMY_SECRET, ?string $account = self::ACADEMY): bool
    {
        $raw = json_encode(['event' => 'charge.success', 'data' => [
            'id' => $eventId, 'status' => 'success', 'reference' => 'ase-ref-order-41', 'amount' => $kobo, 'currency' => 'NGN',
            'metadata' => ['order_id' => $paymentId],
        ]]);

        return AseManager::handleWebhook('paystack', $raw, ['x-paystack-signature' => [hash_hmac('sha512', $raw, $secret)]], $account);
    }

    private function outbox(): array
    {
        return array_map(fn (array $r) => [$r['event_name'], json_decode($r['payload'], true)],
            $this->pdo->query('SELECT event_name, payload FROM ase_outbox_events ORDER BY created_at, rowid')->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── Account-scoped gateways ─────────────────────────────────────────────

    public function testEachAccountKeepsItsOwnGateways(): void
    {
        AseManager::upsertGateway('paystack', ['secret_key' => 'sk_test_platform_0123456789abcdef', 'public_key' => 'pk_test_platform'], true);

        $this->assertSame(self::ACADEMY_SECRET, AseManager::getGatewayCredentialsByProvider('paystack', self::ACADEMY)['secret_key']);
        $this->assertSame(self::OTHER_SECRET, AseManager::getGatewayCredentialsByProvider('paystack', self::OTHER_ACADEMY)['secret_key']);
        $this->assertSame('sk_test_platform_0123456789abcdef', AseManager::getGatewayCredentialsByProvider('paystack')['secret_key']);
        $this->assertSame(['paystack'], array_column(AseManager::getAccountGateways(self::ACADEMY), 'provider'));

        // The platform admin never lists or edits an academy's gateways.
        foreach (AseManager::getAllGateways() as $gateway) {
            $this->assertNotSame(self::ACADEMY_SECRET, $gateway['credentials']['secret_key'] ?? null);
        }

        // Updating an academy gateway does not create a second row.
        AseManager::upsertGateway('paystack', ['secret_key' => self::ACADEMY_SECRET, 'public_key' => 'pk_test_rotated'], false, self::ACADEMY);
        $this->assertCount(1, AseManager::getAccountGateways(self::ACADEMY));
        $this->assertFalse(AseManager::getAccountGateways(self::ACADEMY)[0]['is_active']);
    }

    public function testAccountsCannotUseTheMockGatewayOrInvalidReferences(): void
    {
        try {
            AseManager::upsertGateway('mock', [], true, self::ACADEMY);
            $this->fail('An academy enabled the mock gateway');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        AseManager::upsertGateway('paystack', [], true, 'tenant 7; DROP');
    }

    // ── Payments ────────────────────────────────────────────────────────────

    public function testPaymentIsStoredPendingAndSentWithItsIdAsReference(): void
    {
        $session = $this->startPayment();

        $this->assertSame('https://checkout.paystack.com/pay-order-41', $session['checkout_url']);
        $payment = AseManager::getPayment($session['payment_id']);
        $this->assertSame(['marketplace', 'order-41', self::ACADEMY, 'pending', 1500000, 'NGN', 'ase-ref-order-41'],
            [$payment['context'], $payment['reference'], $payment['account_ref'], $payment['status'], $payment['amount_minor'], $payment['currency'], $payment['external_id']]);
        $sent = json_decode((string) $this->sent[0]['request']->getBody(), true);
        $this->assertSame($session['payment_id'], $sent['metadata']['order_id']);
        $this->assertSame('Bearer ' . self::ACADEMY_SECRET, $this->sent[0]['request']->getHeaderLine('Authorization'), "Charged with the academy's own key");
        $this->assertSame([$payment], AseManager::getPaymentsByReference('marketplace', 'order-41'));
    }

    public function testInactiveOrForeignGatewaysCannotCharge(): void
    {
        AseManager::upsertGateway('paystack', ['secret_key' => self::ACADEMY_SECRET, 'public_key' => 'pk'], false, self::ACADEMY);
        try {
            $this->startPayment();
            $this->fail('Charged through an inactive gateway');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not active for this account', $e->getMessage());
        }
        $this->expectExceptionMessage('not active for this account');
        $this->startPayment(account: 'tenant:99');
    }

    public function testSignedChargeSettlesThePaymentOnceAndEmitsAnOutboxEvent(): void
    {
        $paymentId = $this->startPayment()['payment_id'];

        $this->assertTrue($this->charge($paymentId, 1500000, 9001));
        $this->assertTrue($this->charge($paymentId, 1500000, 9001), 'Provider retry');

        $payment = AseManager::getPayment($paymentId);
        $this->assertSame('paid', $payment['status']);
        $this->assertSame('ase-ref-order-41', $payment['external_payment_id']);
        $events = $this->outbox();
        $this->assertCount(1, $events);
        [$name, $data] = $events[0];
        $this->assertSame('payment.succeeded', $name);
        $this->assertSame(['marketplace', 'order-41', self::ACADEMY, 1500000, 'NGN'],
            [$data['context'], $data['reference'], $data['account_ref'], $data['amount_minor'], $data['currency']]);
    }

    public function testUnderpaymentIsRejectedAndNothingIsRecorded(): void
    {
        $paymentId = $this->startPayment()['payment_id'];

        try {
            $this->charge($paymentId, 1499999, 9002);
            $this->fail('Settled with one kobo less');
        } catch (PaymentVerificationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('pending', AseManager::getPayment($paymentId)['status']);
        $this->assertSame([], $this->outbox());
    }

    public function testAnotherAccountCannotSettleThePayment(): void
    {
        $paymentId = $this->startPayment()['payment_id'];

        // Signed by academy 8 on academy 8's URL, pointing at academy 7's payment.
        try {
            $this->charge($paymentId, 1500000, 9003, self::OTHER_SECRET, self::OTHER_ACADEMY);
            $this->fail("Academy 8 settled academy 7's payment");
        } catch (PaymentVerificationException) {
            $this->addToAssertionCount(1);
        }
        // Academy 8's signature on academy 7's URL is not authentic.
        try {
            $this->charge($paymentId, 1500000, 9004, self::OTHER_SECRET, self::ACADEMY);
            $this->fail('Accepted a signature made with another account key');
        } catch (WebhookSignatureException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('pending', AseManager::getPayment($paymentId)['status']);
    }

    public function testThePlatformUrlCannotSettleAnAcademyPayment(): void
    {
        AseManager::upsertGateway('paystack', ['secret_key' => 'sk_test_platform_0123456789abcdef', 'public_key' => 'pk'], true);
        $paymentId = $this->startPayment()['payment_id'];

        $this->expectException(PaymentVerificationException::class);
        $this->charge($paymentId, 1500000, 9005, 'sk_test_platform_0123456789abcdef', null);
    }

    public function testAccountEventsWithoutPaymentAreRecordedAndUnknownAccountsRejected(): void
    {
        $raw = json_encode(['event' => 'charge.success', 'data' => ['id' => 1, 'status' => 'success', 'reference' => 'external-sale',
            'amount' => 100, 'currency' => 'NGN', 'metadata' => []]]);
        $headers = ['x-paystack-signature' => hash_hmac('sha512', $raw, self::ACADEMY_SECRET)];

        $this->assertTrue(AseManager::handleWebhook('paystack', $raw, $headers, self::ACADEMY), 'Sale made outside ceiba: acknowledged');
        $outbox = $this->outbox();
        $this->assertSame(['webhook.unmatched'], array_column($outbox, 0), 'Kept for reconciliation');
        $this->assertEquals(['paystack', self::ACADEMY, 'INITIAL_PAYMENT', 1.0, 'NGN', 'external-sale'], [$outbox[0][1]['provider'],
            $outbox[0][1]['account_ref'], $outbox[0][1]['action'], $outbox[0][1]['amount'], $outbox[0][1]['currency'], $outbox[0][1]['reference']]);

        $this->expectException(\InvalidArgumentException::class);
        AseManager::handleWebhook('paystack', $raw, $headers, 'tenant:404');
    }

    public function testRefundsAreAppliedOnceFromTheApiAndTheWebhook(): void
    {
        $paymentId = $this->startPayment()['payment_id'];
        $this->charge($paymentId, 1500000, 9006);

        // Partial refund through the API: verify transaction + refund.
        $this->http->append(
            $this->json(['status' => true, 'data' => ['status' => 'success', 'currency' => 'NGN', 'amount' => 1500000]]),
            $this->json(['status' => true, 'data' => ['id' => 555]]),
        );
        $partial = AseManager::refundPayment($paymentId, 5000.00, 'Solicitud del estudiante');
        $this->assertSame('success', $partial['status']);
        $this->assertSame('partially_refunded', $partial['payment']['status']);
        $this->assertSame(500000, $partial['payment']['refunded_minor']);

        // The provider then reports the same refund (id 555): not applied twice.
        $raw = json_encode(['event' => 'refund.processed', 'data' => ['id' => 555, 'transaction_reference' => 'ase-ref-order-41',
            'amount' => 500000, 'currency' => 'NGN', 'status' => 'processed']]);
        $this->http->append($this->json(['status' => true, 'data' => ['amount' => 1500000, 'metadata' => ['order_id' => $paymentId]]]));
        AseManager::handleWebhook('paystack', $raw, ['x-paystack-signature' => hash_hmac('sha512', $raw, self::ACADEMY_SECRET)], self::ACADEMY);
        $this->assertSame(500000, AseManager::getPayment($paymentId)['refunded_minor']);

        // Refunding the rest (no amount) completes it.
        $this->http->append(
            $this->json(['status' => true, 'data' => ['status' => 'success', 'currency' => 'NGN', 'amount' => 1500000]]),
            $this->json(['status' => true, 'data' => ['id' => 556]]),
        );
        $rest = AseManager::refundPayment($paymentId, null, 'Cierre');
        $this->assertSame('refunded', $rest['payment']['status']);
        $this->assertSame(1000000, json_decode((string) end($this->sent)['request']->getBody(), true)['amount']);
        $this->assertSame(['payment.succeeded', 'payment.refunded', 'payment.refunded'], array_column($this->outbox(), 0));
        $this->assertSame('failed', AseManager::refundPayment($paymentId, 1.00, 'Again')['status'], 'Nothing left to refund');
    }

    public function testVerifyPaymentSettlesWhenTheWebhookDidNotArrive(): void
    {
        $paymentId = $this->startPayment()['payment_id'];
        $this->http->append($this->json(['status' => true, 'data' => ['status' => 'success', 'reference' => 'ase-ref-order-41',
            'amount' => 1500000, 'currency' => 'NGN', 'metadata' => ['order_id' => $paymentId]]]));

        $this->assertSame('paid', AseManager::verifyPayment($paymentId)['status']);
        $this->assertSame('/transaction/verify/ase-ref-order-41', end($this->sent)['request']->getUri()->getPath());
    }

    public function testListenersReceiveTheOutboxEventId(): void
    {
        $received = [];
        AseEventDispatcher::listen('payment.succeeded', function (array $event) use (&$received) { $received[] = $event; });
        $paymentId = $this->startPayment()['payment_id'];

        $this->charge($paymentId, 1500000, 9007);

        $this->assertCount(1, $received);
        $this->assertSame($paymentId, $received[0]['payment_id']);
        $this->assertNotEmpty($received[0]['outbox_event_id']);
    }

    // ── Stripe one-time sessions and plan orders ────────────────────────────

    public function testStripePaymentsUsePaymentModeWithThePaymentIdInMetadata(): void
    {
        AseManager::upsertGateway('stripe', ['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_academy7_stripe', 'webhook_secret' => 'whsec_x'], true, self::ACADEMY);
        $stripe = new class implements ClientInterface {
            public array $requests = [];
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                $this->requests[] = [strtolower((string) $method), (string) $absUrl, $params, $headers];
                return [json_encode(['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/cs_test_1']), 200, []];
            }
        };
        ApiRequestor::setHttpClient($stripe);
        try {
            $session = AseManager::createPayment(new PaymentRequest('marketplace', 'order-77', 'student-5', 49.99, 'USD', 'stripe',
                'Webinar React', 'https://campus.test/ok', 'https://campus.test/cancel', self::ACADEMY));
        } finally {
            ApiRequestor::setHttpClient(null);
        }

        [, $url, $params, $headers] = $stripe->requests[0];
        $this->assertStringEndsWith('/v1/checkout/sessions', $url);
        $this->assertSame('payment', $params['mode']);
        $this->assertSame(4999, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('Webinar React', $params['line_items'][0]['price_data']['product_data']['name']);
        $this->assertSame($session['payment_id'], $params['metadata']['order_id']);
        $this->assertSame($session['payment_id'], $params['payment_intent_data']['metadata']['order_id']);
        $this->assertSame('student-5@students.test', $params['customer_email']);
        $this->assertContains('Authorization: Bearer sk_test_academy7_stripe', $headers);
    }

    public function testPlatformPlansNeverUseAnAcademyGateway(): void
    {
        $plan = AseManager::createPlan('pro', 'Pro');
        $price = AseManager::createPlanPrice($plan['id'], 'one_time', 10.00, 'NGN');
        $academyGatewayId = AseManager::getGatewayCredentialsByProvider('paystack', self::ACADEMY)['gateway_id'];

        $this->expectExceptionMessage('belongs to another account');
        AseManager::createCheckoutSession('owner-1', $price['id'], $academyGatewayId, 'https://x.test/ok', 'https://x.test/no');
    }

    public function testWebhookUrlsReceiveContextAndAccount(): void
    {
        WebhookUrls::resolveUsing(fn (string $provider, string $context, ?string $account) =>
            $account === null ? "https://campus.test/webhooks/{$provider}" : "https://campus.test/webhooks/marketplace/" . substr($account, 7) . "/{$provider}");

        $this->assertSame('/webhooks/alipay', WebhookUrls::pathFor('alipay'));
        $this->assertSame('/webhooks/marketplace/7/alipay', WebhookUrls::pathFor('alipay', 'marketplace', self::ACADEMY));
    }
}
