<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Payments;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\ValueObjects\Currency;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\FormManagement\Infrastructure\GatewayFormRegistry;
use LemurAse\Infrastructure\Customers\CustomerContacts;
use LemurAse\Infrastructure\Http\HttpClientFactory;
use LemurAse\Infrastructure\Payments\GatewayAdapterRegistry;
use LemurAse\Infrastructure\Payments\Paystack\PaystackSignature;
use LemurAse\Infrastructure\Payments\PaystackAdapter;
use LemurAse\Infrastructure\Payments\Razorpay\RazorpaySignature;
use LemurAse\Infrastructure\Payments\RazorpayAdapter;
use LemurAse\WebhookManagement\Domain\WebhookSignatureException;
use LemurAse\WebhookManagement\Infrastructure\Adapters\PaystackWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\RazorpayWebhookAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PaystackRazorpayTest extends TestCase
{
    private const PAYSTACK_SECRET = 'sk_test_0123456789abcdef0123456789abcdef';
    private const RAZORPAY_WEBHOOK_SECRET = 'whsec-razorpay-test-0123456789';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];
    private MockHandler $responses;
    private ?string $previousLog = null;

    protected function setUp(): void
    {
        $this->responses = new MockHandler();
        $stack = HandlerStack::create($this->responses);
        $stack->push(Middleware::history($this->history));
        HttpClientFactory::useHandler($stack);
        CustomerContacts::resolveUsing(fn (string $id) => ['email' => "{$id}@example.com", 'name' => 'Ada Lovelace']);
        $this->previousLog = ini_set('error_log', '/dev/null') ?: null;
    }

    protected function tearDown(): void
    {
        HttpClientFactory::useHandler(null);
        CustomerContacts::resolveUsing(null);
        ini_set('error_log', (string) $this->previousLog);
    }

    private function order(float $amount, string $currency): Order
    {
        return new Order(EntityId::generate(), 'client-42', EntityId::generate(), EntityId::generate(),
            Money::create($amount, Currency::fromString($currency)), 'hash');
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function sentBody(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    // ── Signatures ──────────────────────────────────────────────────────────

    public function testPaystackSignatureCoversTheRawBody(): void
    {
        $body = '{"event":"charge.success","data":{"amount":500000}}';
        $signature = hash_hmac('sha512', $body, self::PAYSTACK_SECRET);

        PaystackSignature::verify($body, ['X-Paystack-Signature' => [$signature]], self::PAYSTACK_SECRET);
        $this->addToAssertionCount(1);

        $reencoded = json_encode(json_decode($body, true), JSON_PRETTY_PRINT);
        foreach ([
            'tampered body' => [str_replace('500000', '100', $body), ['x-paystack-signature' => $signature]],
            're-encoded'    => [$reencoded, ['x-paystack-signature' => $signature]],
            'no header'     => [$body, []],
            'other secret'  => [$body, ['x-paystack-signature' => hash_hmac('sha512', $body, 'sk_test_other')]],
        ] as $case => [$raw, $headers]) {
            try {
                PaystackSignature::verify($raw, $headers, self::PAYSTACK_SECRET);
                $this->fail("Accepted: {$case}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        PaystackSignature::verify($body, ['x-paystack-signature' => $signature], '');
    }

    public function testRazorpaySignatureUsesTheWebhookSecret(): void
    {
        $body = '{"event":"payment_link.paid"}';
        RazorpaySignature::verify($body, ['x-razorpay-signature' => hash_hmac('sha256', $body, self::RAZORPAY_WEBHOOK_SECRET)], self::RAZORPAY_WEBHOOK_SECRET);
        $this->addToAssertionCount(1);

        $this->expectException(\InvalidArgumentException::class);
        RazorpaySignature::verify($body, ['x-razorpay-signature' => hash_hmac('sha256', $body, 'rzp_key_secret')], self::RAZORPAY_WEBHOOK_SECRET);
    }

    // ── Paystack checkout, verification and refund ────────────────────────────

    public function testPaystackCheckoutSendsMinorUnitsEmailAndOrderMetadata(): void
    {
        $order = $this->order(4999.50, 'NGN');
        $this->responses->append($this->json(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'ase-ref-1',
        ]]));

        $session = (new PaystackAdapter(self::PAYSTACK_SECRET, 'pk_test_x'))
            ->createCheckoutSession($order, 'https://app.test/ok', 'https://app.test/cancel');

        $this->assertSame(['checkout_url' => 'https://checkout.paystack.com/abc', 'external_id' => 'ase-ref-1'], $session);
        $request = $this->history[0]['request'];
        $this->assertSame('https://api.paystack.co/transaction/initialize', (string) $request->getUri());
        $this->assertSame('Bearer ' . self::PAYSTACK_SECRET, $request->getHeaderLine('Authorization'));
        $body = $this->sentBody(0);
        $this->assertSame(499950, $body['amount']);
        $this->assertSame('NGN', $body['currency']);
        $this->assertSame('client-42@example.com', $body['email']);
        $this->assertSame($order->id()->uuid(), $body['metadata']['order_id']);
        $this->assertSame('https://app.test/cancel', $body['metadata']['cancel_action']);
        $this->assertStringStartsWith('ase-' . $order->id()->uuid() . '-', $body['reference']);
    }

    public function testPaystackCheckoutNeedsTheCustomerEmail(): void
    {
        CustomerContacts::resolveUsing(fn () => ['email' => 'not-an-email']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CustomerContacts::resolveUsing');
        (new PaystackAdapter(self::PAYSTACK_SECRET, 'pk'))->createCheckoutSession($this->order(10, 'GHS'), 'a', 'b');
    }

    public function testPaystackApiErrorsFailTheCheckout(): void
    {
        $this->responses->append($this->json(['status' => false, 'message' => 'Currency not supported by merchant'], 400));
        $this->expectExceptionMessage('Currency not supported by merchant');
        (new PaystackAdapter(self::PAYSTACK_SECRET, 'pk'))->createCheckoutSession($this->order(10, 'USD'), 'a', 'b');
    }

    public function testPaystackVerifyReportsTheChargedAmount(): void
    {
        $this->responses->append($this->json(['status' => true, 'data' => [
            'status' => 'success', 'reference' => 'ase-ref-1', 'amount' => 250000, 'currency' => 'ZAR',
            'metadata' => json_encode(['order_id' => 'order-uuid']),
        ]]));

        $result = (new PaystackAdapter(self::PAYSTACK_SECRET, 'pk'))->verifyTransaction('ase-ref-1');

        $this->assertSame('INITIAL_PAYMENT', $result['action']);
        $this->assertSame(2500.0, $result['amount']);
        $this->assertSame('ZAR', $result['currency']);
        $this->assertSame('order-uuid', $result['internal_order_id'], 'metadata sent back as a JSON string');
        $this->assertSame('/transaction/verify/ase-ref-1', $this->history[0]['request']->getUri()->getPath());
    }

    public function testPaystackRefundUsesTheTransactionCurrency(): void
    {
        $this->responses->append(
            $this->json(['status' => true, 'data' => ['status' => 'success', 'currency' => 'KES', 'amount' => 100000]]),
            $this->json(['status' => true, 'data' => ['id' => 777]]),
        );

        $result = (new PaystackAdapter(self::PAYSTACK_SECRET, 'pk'))->refund('ase-ref-1', 250.0, 'customer request');

        $this->assertSame(['status' => 'success', 'external_refund_id' => '777'], $result);
        $this->assertSame(['transaction' => 'ase-ref-1', 'currency' => 'KES', 'merchant_note' => 'customer request', 'amount' => 25000], $this->sentBody(1));
    }

    public function testPaystackRejectsUnsafeReferencesBeforeCallingTheApi(): void
    {
        $result = (new PaystackAdapter(self::PAYSTACK_SECRET, 'pk'))->verifyTransaction('../../balance');
        $this->assertSame('FAILED', $result['action']);
        $this->assertSame([], $this->history);
    }

    // ── Paystack webhooks ────────────────────────────────────────────────────

    private function paystackWebhook(array $event): \LemurAse\WebhookManagement\Domain\WebhookEvent
    {
        $raw = json_encode($event);
        return (new PaystackWebhookAdapter())->parse($raw,
            ['x-paystack-signature' => [hash_hmac('sha512', $raw, self::PAYSTACK_SECRET)]], ['secret_key' => self::PAYSTACK_SECRET]);
    }

    public function testPaystackChargeSuccessBecomesInitialPayment(): void
    {
        $event = $this->paystackWebhook(['event' => 'charge.success', 'data' => [
            'id' => 991, 'status' => 'success', 'reference' => 'ase-ref-1', 'amount' => 499950, 'currency' => 'NGN',
            'metadata' => ['order_id' => 'order-uuid', 'external_client_id' => 'client-42'],
        ]]);

        $this->assertSame('INITIAL_PAYMENT', $event->action);
        $this->assertSame('paystack:charge.success:991', $event->externalId);
        $this->assertSame('order-uuid', $event->data['internal_order_id']);
        $this->assertSame(4999.5, $event->data['amount']);
        $this->assertSame('NGN', $event->data['currency']);
        $this->assertSame('ase-ref-1', $event->data['external_transaction_id']);
        $this->assertSame([], $this->history, 'A signed charge needs no API call');
    }

    public function testPaystackRefundIsFullOnlyWhenItCoversTheCharge(): void
    {
        $this->responses->append(
            $this->json(['status' => true, 'data' => ['amount' => 100000, 'metadata' => ['order_id' => 'order-uuid']]]),
            $this->json(['status' => true, 'data' => ['amount' => 100000, 'metadata' => ['order_id' => 'order-uuid']]]),
        );

        $partial = $this->paystackWebhook(['event' => 'refund.processed', 'data' => [
            'id' => 5, 'transaction_reference' => 'ase-ref-1', 'amount' => 40000, 'currency' => 'NGN', 'status' => 'processed',
        ]]);
        $full = $this->paystackWebhook(['event' => 'refund.processed', 'data' => [
            'id' => 6, 'transaction_reference' => 'ase-ref-1', 'amount' => 100000, 'currency' => 'NGN', 'status' => 'processed',
        ]]);

        $this->assertSame('REFUND_PROCESSED', $partial->action);
        $this->assertFalse($partial->data['full_refund']);
        $this->assertSame(400.0, $partial->data['amount']);
        $this->assertTrue($full->data['full_refund']);
        $this->assertSame('ase-ref-1', $full->data['external_transaction_id']);
        $this->assertSame('paystack:refund:6', $full->data['external_refund_id']);
    }

    public function testPaystackUnsignedOrFailedChargesAreNotPayments(): void
    {
        $failed = $this->paystackWebhook(['event' => 'charge.success', 'data' => ['id' => 1, 'status' => 'failed', 'amount' => 1, 'currency' => 'NGN']]);
        $this->assertSame('UNKNOWN', $failed->action);
        $this->assertSame('UNKNOWN', $this->paystackWebhook(['event' => 'transfer.success', 'data' => ['id' => 2]])->action);

        $this->expectException(WebhookSignatureException::class);
        (new PaystackWebhookAdapter())->parse('{"event":"charge.success"}', ['x-paystack-signature' => str_repeat('a', 128)],
            ['secret_key' => self::PAYSTACK_SECRET]);
    }

    // ── Razorpay ─────────────────────────────────────────────────────────────

    private function razorpay(): RazorpayAdapter
    {
        return new RazorpayAdapter('rzp_test_key', 'rzp_test_secret', self::RAZORPAY_WEBHOOK_SECRET);
    }

    public function testRazorpayCheckoutCreatesAPaymentLinkForTheOrder(): void
    {
        $order = $this->order(1499.00, 'INR');
        $this->responses->append($this->json(['id' => 'plink_123', 'short_url' => 'https://rzp.io/i/abc', 'status' => 'created']));

        $session = $this->razorpay()->createCheckoutSession($order, 'https://app.test/ok', 'https://app.test/cancel');

        $this->assertSame(['checkout_url' => 'https://rzp.io/i/abc', 'external_id' => 'plink_123'], $session);
        $request = $this->history[0]['request'];
        $this->assertSame('https://api.razorpay.com/v1/payment_links', (string) $request->getUri());
        $this->assertSame('Basic ' . base64_encode('rzp_test_key:rzp_test_secret'), $request->getHeaderLine('Authorization'));
        $body = $this->sentBody(0);
        $this->assertSame(149900, $body['amount']);
        $this->assertSame('INR', $body['currency']);
        $this->assertFalse($body['accept_partial']);
        $this->assertSame($order->id()->uuid(), $body['reference_id']);
        $this->assertSame('client-42@example.com', $body['customer']['email']);
    }

    public function testRazorpayVerifyAndRefundUseTheCapturedPayment(): void
    {
        $link = ['id' => 'plink_123', 'status' => 'paid', 'amount_paid' => 149900, 'currency' => 'INR', 'reference_id' => 'order-uuid',
            'payments' => [['payment_id' => 'pay_failed', 'status' => 'failed'], ['payment_id' => 'pay_ok', 'status' => 'captured', 'amount' => 149900]]];
        $this->responses->append($this->json($link), $this->json($link), $this->json(['id' => 'rfnd_1']));

        $verified = $this->razorpay()->verifyTransaction('plink_123');
        $this->assertSame('INITIAL_PAYMENT', $verified['action']);
        $this->assertSame(1499.0, $verified['amount']);
        $this->assertSame('order-uuid', $verified['internal_order_id']);
        $this->assertSame('pay_ok', $verified['external_transaction_id']);

        $refund = $this->razorpay()->refund('plink_123', 0, 'duplicate');
        $this->assertSame(['status' => 'success', 'external_refund_id' => 'rfnd_1'], $refund);
        $this->assertSame('/v1/payments/pay_ok/refund', $this->history[2]['request']->getUri()->getPath());
        $this->assertSame(['notes' => ['order_id' => 'order-uuid', 'reason' => 'duplicate']], $this->sentBody(2), 'Full refund omits the amount');
    }

    private function razorpayWebhook(array $event, string $eventId = 'evt_abc'): \LemurAse\WebhookManagement\Domain\WebhookEvent
    {
        $raw = json_encode($event);
        return (new RazorpayWebhookAdapter())->parse($raw, [
            'x-razorpay-signature' => [hash_hmac('sha256', $raw, self::RAZORPAY_WEBHOOK_SECRET)],
            'x-razorpay-event-id'  => [$eventId],
        ], ['webhook_secret' => self::RAZORPAY_WEBHOOK_SECRET]);
    }

    public function testRazorpayPaymentLinkPaidBecomesInitialPayment(): void
    {
        $event = $this->razorpayWebhook(['event' => 'payment_link.paid', 'payload' => [
            'payment_link' => ['entity' => ['id' => 'plink_123', 'status' => 'paid', 'amount_paid' => 149900, 'currency' => 'INR',
                'reference_id' => 'order-uuid', 'notes' => ['external_client_id' => 'client-42']]],
            'payment' => ['entity' => ['id' => 'pay_ok', 'status' => 'captured', 'amount' => 149900, 'currency' => 'INR']],
        ]]);

        $this->assertSame('INITIAL_PAYMENT', $event->action);
        $this->assertSame('razorpay:evt_abc', $event->externalId);
        $this->assertSame('order-uuid', $event->data['internal_order_id']);
        $this->assertSame(1499.0, $event->data['amount']);
        $this->assertSame('plink_123', $event->data['external_subscription_id']);
    }

    public function testRazorpayRefundCarriesTheOrderForLookup(): void
    {
        $event = $this->razorpayWebhook(['event' => 'refund.processed', 'payload' => [
            'refund'  => ['entity' => ['id' => 'rfnd_1', 'payment_id' => 'pay_ok', 'amount' => 149900, 'currency' => 'INR', 'notes' => ['order_id' => 'order-uuid']]],
            'payment' => ['entity' => ['id' => 'pay_ok', 'amount' => 149900, 'amount_refunded' => 149900, 'currency' => 'INR']],
        ]], 'evt_refund');

        $this->assertSame('REFUND_PROCESSED', $event->action);
        $this->assertSame('pay_ok', $event->data['external_transaction_id']);
        $this->assertSame('order-uuid', $event->data['internal_order_id']);
        $this->assertTrue($event->data['full_refund']);
        $this->assertSame('razorpay:refund:rfnd_1', $event->data['external_refund_id']);
    }

    public function testRazorpayRejectsSignaturesMadeWithTheApiSecret(): void
    {
        $raw = '{"event":"payment_link.paid"}';
        $this->expectException(WebhookSignatureException::class);
        (new RazorpayWebhookAdapter())->parse($raw, ['x-razorpay-signature' => hash_hmac('sha256', $raw, 'rzp_test_secret')],
            ['webhook_secret' => self::RAZORPAY_WEBHOOK_SECRET]);
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function testGatewaysAreRegisteredWithTheirCredentialForms(): void
    {
        GatewayAdapterRegistry::reset();
        $this->assertInstanceOf(PaystackAdapter::class, GatewayAdapterRegistry::make('paystack', ['secret_key' => self::PAYSTACK_SECRET, 'public_key' => 'pk']));
        $this->assertInstanceOf(RazorpayAdapter::class, GatewayAdapterRegistry::make('razorpay',
            ['key_id' => 'rzp_test_key', 'key_secret' => 's', 'webhook_secret' => 'w']));

        GatewayFormRegistry::reset();
        $this->assertSame(['secret_key', 'public_key'],
            array_map(fn ($f) => $f->name(), GatewayFormRegistry::get('paystack')->fields()));
        $this->assertSame(['key_id', 'key_secret', 'webhook_secret'],
            array_map(fn ($f) => $f->name(), GatewayFormRegistry::get('razorpay')->fields()));

        $this->expectExceptionMessage("Razorpay credential 'webhook_secret' missing.");
        GatewayAdapterRegistry::make('razorpay', ['key_id' => 'k', 'key_secret' => 's']);
    }
}
