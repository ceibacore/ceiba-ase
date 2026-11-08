<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Payments;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ClientException;
use LemurAse\Infrastructure\Http\HttpClientFactory;
use LemurAse\WebhookManagement\Infrastructure\Adapters\PayPalWebhookAdapter;
use PHPUnit\Framework\TestCase;

final class PayPalWebhookFlowTest extends TestCase
{
    private const CONFIG = ['client_id' => 'cid', 'secret' => 'sec', 'webhook_id' => 'WH-1', 'sandbox' => false];
    private array $history = [];
    private MockHandler $mock;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        HttpClientFactory::useHandler($stack);
    }

    protected function tearDown(): void
    {
        HttpClientFactory::useHandler(null);
    }

    private function headers(array $override = []): array
    {
        // Laravel-style: lowercase names with array values.
        return array_merge([
            'paypal-transmission-id'   => ['8b7f0a10-1d11-11ef-9b8c-0b3b8a8f1d2e'],
            'paypal-transmission-time' => [gmdate('Y-m-d\TH:i:s\Z')],
            'paypal-cert-url'          => ['https://api.paypal.com/v1/notifications/certs/CERT-1'],
            'paypal-transmission-sig'  => ['c2lnbmF0dXJl'],
            'paypal-auth-algo'         => ['SHA256withRSA'],
        ], $override);
    }

    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private static function completedOrder(string $value = '49.90', string $currency = 'EUR'): array
    {
        return ['id' => 'ORDER-1', 'status' => 'COMPLETED', 'purchase_units' => [[
            'custom_id' => 'order-uuid-1',
            'payments' => ['captures' => [['id' => 'CAP-1', 'status' => 'COMPLETED', 'custom_id' => 'order-uuid-1',
                'amount' => ['value' => $value, 'currency_code' => $currency]]]],
        ]]];
    }

    private function approvedEvent(): string
    {
        return json_encode(['id' => 'WH-EVT-1', 'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'resource' => ['id' => 'ORDER-1', 'status' => 'APPROVED']]);
    }

    public function testApprovedOrderIsCapturedBeforeCountingAsPayment(): void
    {
        $this->mock->append(
            self::json(['access_token' => 'tok']),
            self::json(['verification_status' => 'SUCCESS']),
            self::json(['access_token' => 'tok']),
            self::json(self::completedOrder()),
        );

        $event = (new PayPalWebhookAdapter())->parse($this->approvedEvent(), $this->headers(), self::CONFIG);

        $this->assertSame('INITIAL_PAYMENT', $event->action);
        $this->assertSame('order-uuid-1', $event->data['internal_order_id']);
        $this->assertSame(49.90, $event->data['amount'], 'Amount comes from the capture, not the event');
        $this->assertSame('EUR', $event->data['currency']);
        $this->assertSame('WH-EVT-1', $event->externalId);
        $capture = $this->history[3]['request'];
        $this->assertSame('/v2/checkout/orders/ORDER-1/capture', $capture->getUri()->getPath());
        $this->assertSame('capture-ORDER-1', $capture->getHeaderLine('PayPal-Request-Id'), 'Capture is idempotent');
        $verify = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('8b7f0a10-1d11-11ef-9b8c-0b3b8a8f1d2e', $verify['transmission_id'], 'Array headers are read');
        $this->assertSame('WH-1', $verify['webhook_id']);
    }

    public function testAlreadyCapturedOrderIsReadInsteadOfFailing(): void
    {
        $this->mock->append(
            self::json(['access_token' => 'tok']),
            self::json(['verification_status' => 'SUCCESS']),
            self::json(['access_token' => 'tok']),
            new ClientException('Unprocessable', new Request('POST', '/'),
                self::json(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'ORDER_ALREADY_CAPTURED']]], 422)),
            self::json(self::completedOrder('10.00', 'USD')),
        );
        $event = (new PayPalWebhookAdapter())->parse($this->approvedEvent(), $this->headers(), self::CONFIG);
        $this->assertSame('INITIAL_PAYMENT', $event->action);
        $this->assertSame('GET', $this->history[4]['request']->getMethod());
    }

    public function testUncompletedCaptureDoesNotActivate(): void
    {
        $this->mock->append(
            self::json(['access_token' => 'tok']),
            self::json(['verification_status' => 'SUCCESS']),
            self::json(['access_token' => 'tok']),
            self::json(['id' => 'ORDER-1', 'status' => 'PAYER_ACTION_REQUIRED']),
        );
        $event = (new PayPalWebhookAdapter())->parse($this->approvedEvent(), $this->headers(), self::CONFIG);
        $this->assertSame('UNKNOWN', $event->action);
    }

    public function testFailedRemoteVerificationIsRejected(): void
    {
        $this->mock->append(self::json(['access_token' => 'tok']), self::json(['verification_status' => 'FAILURE']));
        $this->expectExceptionMessage('signature verification failed');
        (new PayPalWebhookAdapter())->parse($this->approvedEvent(), $this->headers(), self::CONFIG);
    }

    public function testForgedHeadersAreRejectedWithoutAnyNetworkCall(): void
    {
        foreach ([
            ['paypal-cert-url' => ['https://evil.example/v1/notifications/certs/X']],
            ['paypal-transmission-time' => [gmdate('Y-m-d\TH:i:s\Z', time() - 3600)]],
            ['paypal-auth-algo' => ['none']],
        ] as $override) {
            try {
                (new PayPalWebhookAdapter())->parse($this->approvedEvent(), $this->headers($override), self::CONFIG);
                $this->fail('Forged headers accepted: ' . json_encode($override));
            } catch (\Exception) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->history, 'No request reached PayPal');
    }
}
