<?php

declare(strict_types=1);

namespace LemurAse\Tests\Integration\Payments;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LemurAse\AseManager;
use LemurAse\Infrastructure\Customers\CustomerContacts;
use LemurAse\Infrastructure\Events\AseEventDispatcher;
use LemurAse\Infrastructure\Http\HttpClientFactory;
use LemurAse\Tests\Support\AseSqliteSchema;
use LemurAse\WebhookManagement\Domain\PaymentVerificationException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Plans owned by an academy (memberships for its students): charged only through the
 * academy's own gateway, activated only by the academy's webhook, reported with the account.
 */
final class AcademyPlansTest extends TestCase
{
    private const ACADEMY = 'tenant:7';
    private const OTHER = 'tenant:8';
    private const SECRET = 'sk_test_academy7_0123456789abcdef0123456789';
    private const OTHER_SECRET = 'sk_test_academy8_0123456789abcdef0123456789';

    private PDO $pdo;
    private MockHandler $http;
    private ?string $previousLog = null;

    protected function setUp(): void
    {
        $this->pdo = AseSqliteSchema::create();
        $this->http = new MockHandler();
        HttpClientFactory::useHandler(HandlerStack::create($this->http));
        CustomerContacts::resolveUsing(fn (string $id) => ['email' => 'student@academy.test', 'name' => 'Student']);
        $this->previousLog = ini_set('error_log', '/dev/null') ?: null;
        AseManager::upsertGateway('paystack', ['secret_key' => self::SECRET, 'public_key' => 'pk7'], true, self::ACADEMY);
        AseManager::upsertGateway('paystack', ['secret_key' => self::OTHER_SECRET, 'public_key' => 'pk8'], true, self::OTHER);
        AseManager::upsertGateway('paystack', ['secret_key' => 'sk_test_platform_0123456789abcdef012345', 'public_key' => 'pkp'], true);
    }

    protected function tearDown(): void
    {
        HttpClientFactory::useHandler(null);
        CustomerContacts::resolveUsing(null);
        AseEventDispatcher::forget();
        AseSqliteSchema::reset();
        ini_set('error_log', (string) $this->previousLog);
    }

    private function academyPlan(): array
    {
        $plan = AseManager::createPlan('tenant-7-membership-3', 'Membresía Pro', null, true, ['product_id' => 3], self::ACADEMY);
        $price = AseManager::createPlanPrice($plan['id'], 'recurring', 15000.00, 'NGN', 'month');

        return [$plan, $price];
    }

    /** @return string the ase order id the checkout created */
    private function checkout(string $priceId): string
    {
        $this->http->append(new Response(200, [], json_encode(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/m', 'reference' => 'ref-membership']])));
        AseManager::createCheckoutSession('academy:7:user:5:product:3', $priceId, 'paystack', 'https://campus.test/ok', 'https://campus.test/no');

        return (string) $this->pdo->query("SELECT id FROM ase_orders ORDER BY created_at DESC LIMIT 1")->fetchColumn();
    }

    private function charge(string $orderId, ?string $account, string $secret): bool
    {
        $raw = json_encode(['event' => 'charge.success', 'data' => ['id' => 501, 'status' => 'success', 'reference' => 'ref-membership',
            'amount' => 1500000, 'currency' => 'NGN', 'metadata' => ['order_id' => $orderId]]]);

        return AseManager::handleWebhook('paystack', $raw, ['x-paystack-signature' => hash_hmac('sha512', $raw, $secret)], $account);
    }

    public function testAcademyPlansAreListedForTheirAccountOnly(): void
    {
        [$plan] = $this->academyPlan();
        AseManager::createPlan('pro', 'Pro (plataforma)');

        $this->assertSame(['Pro (plataforma)'], array_column(AseManager::listPlans(), 'name'), 'Platform pricing never shows academy plans');
        $academy = AseManager::getAccountPlans(self::ACADEMY);
        $this->assertSame([$plan['id'], 'month', 15000.0], [$academy[0]['id'], $academy[0]['prices'][0]['interval'], $academy[0]['prices'][0]['amount']]);
        $this->assertSame([], AseManager::getAccountPlans(self::OTHER));
    }

    public function testTheMembershipIsChargedWithTheAcademyGatewayAndActivatedByItsWebhook(): void
    {
        [, $price] = $this->academyPlan();
        $orderId = $this->checkout($price['id']);

        $gateway = $this->pdo->query("SELECT g.account_ref FROM ase_orders o JOIN ase_gateways g ON g.id = o.gateway_id WHERE o.id = '{$orderId}'")->fetchColumn();
        $this->assertSame(self::ACADEMY, $gateway, "The academy's own gateway");

        $this->assertTrue($this->charge($orderId, self::ACADEMY, self::SECRET));
        $events = $this->pdo->query("SELECT event_name, payload FROM ase_outbox_events WHERE event_name = 'subscription.activated'")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $events);
        $payload = json_decode($events[0]['payload'], true);
        $this->assertEquals([self::ACADEMY, $orderId, 'academy:7:user:5:product:3', 15000.0, 'NGN'],
            [$payload['account_ref'], $payload['order_id'], $payload['external_client_id'], $payload['amount'], $payload['currency']]);
        $this->assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T/', $payload['expires_at']);
    }

    public function testOtherAccountsCannotActivateTheMembership(): void
    {
        [, $price] = $this->academyPlan();
        $orderId = $this->checkout($price['id']);

        try {
            $this->charge($orderId, self::OTHER, self::OTHER_SECRET);
            $this->fail('Another academy activated the membership');
        } catch (PaymentVerificationException) {
        }
        try {
            $this->charge($orderId, null, 'sk_test_platform_0123456789abcdef012345');
            $this->fail('The platform URL activated an academy membership');
        } catch (PaymentVerificationException) {
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM ase_subscriptions")->fetchColumn());
    }

    public function testAcademyPlansAreNeverChargedWithThePlatformGateway(): void
    {
        [, $price] = $this->academyPlan();
        $platformGateway = AseManager::getGatewayCredentialsByProvider('paystack')['gateway_id'];

        $this->expectExceptionMessage('belongs to another account');
        AseManager::createCheckoutSession('academy:7:user:5:product:3', $price['id'], $platformGateway, 'https://x.test/ok', 'https://x.test/no');
    }

    public function testPlansWithAOneTimePriceActivateWithoutExpiry(): void
    {
        $plan = AseManager::createPlan('tenant-7-lifetime', 'Acceso de por vida', null, true, null, self::ACADEMY);
        $price = AseManager::createPlanPrice($plan['id'], 'one_time', 15000.00, 'NGN');
        $orderId = $this->checkout($price['id']);

        $this->assertTrue($this->charge($orderId, self::ACADEMY, self::SECRET));
        $payload = json_decode((string) $this->pdo->query("SELECT payload FROM ase_outbox_events WHERE event_name = 'subscription.activated'")->fetchColumn(), true);
        $this->assertNull($payload['expires_at']);
    }
}
