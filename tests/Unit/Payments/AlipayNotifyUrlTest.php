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
use LemurAse\Infrastructure\Http\HttpClientFactory;
use LemurAse\Infrastructure\Http\WebhookUrls;
use LemurAse\Infrastructure\Payments\AlipayAdapter;
use LemurAse\WebhookManagement\Domain\WebhookSignatureException;
use LemurAse\WebhookManagement\Infrastructure\Adapters\AlipayWebhookAdapter;
use PHPUnit\Framework\TestCase;

/**
 * Alipay is told where to post the payment result at checkout and signs that URL's path:
 * both must come from the host's webhook URL.
 */
final class AlipayNotifyUrlTest extends TestCase
{
    private const WEBHOOK = 'https://campus.example.com/webhooks/ase/alipay';

    private \OpenSSLAsymmetricKey $key;
    private string $publicKey;
    private array $history = [];
    private ?string $previousLog = null;

    protected function setUp(): void
    {
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->publicKey = openssl_pkey_get_details($this->key)['key'];
        WebhookUrls::resolveUsing(fn (string $provider) => $provider === 'alipay' ? self::WEBHOOK : null);
        $this->previousLog = ini_set('error_log', '/dev/null') ?: null;
    }

    protected function tearDown(): void
    {
        WebhookUrls::resolveUsing(null);
        HttpClientFactory::useHandler(null);
        ini_set('error_log', (string) $this->previousLog);
    }

    private function adapter(): AlipayAdapter
    {
        openssl_pkey_export($this->key, $private);
        return new AlipayAdapter('client-1', $private, $this->publicKey, 'merchant-1', true);
    }

    private function signedHeaders(string $body, string $path): array
    {
        $time = gmdate('Y-m-d\TH:i:s\Z');
        openssl_sign("POST {$path}\nclient-1.{$time}.{$body}", $signature, $this->key, OPENSSL_ALGO_SHA256);
        return [
            'signature'    => ['algorithm=RSA256,keyVersion=1,signature=' . urlencode(base64_encode($signature))],
            'client-id'    => ['client-1'],
            'request-time' => [$time],
        ];
    }

    private function notification(): string
    {
        return json_encode(['notifyType' => 'notifyPayment', 'paymentId' => 'pay_1', 'paymentRequestId' => 'order-1',
            'result' => ['resultStatus' => 'S'], 'paymentAmount' => ['value' => '5000', 'currency' => 'USD']]);
    }

    public function testCheckoutSendsTheHostWebhookAsNotifyUrl(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['normalUrl' => 'https://alipay.test/pay', 'paymentId' => 'pay_1']))]));
        $stack->push(Middleware::history($this->history));
        HttpClientFactory::useHandler($stack);
        $order = new Order(EntityId::generate(), 'client-9', EntityId::generate(), EntityId::generate(),
            Money::create(50, Currency::fromString('USD')), 'hash');

        $session = $this->adapter()->createCheckoutSession($order, 'https://campus.example.com/ok', 'https://campus.example.com/cancel');

        $this->assertSame('https://alipay.test/pay', $session['checkout_url']);
        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(self::WEBHOOK, $sent['paymentNotifyUrl'], 'Not the success page');
        $this->assertSame('https://campus.example.com/ok', $sent['paymentRedirectUrl']);
    }

    public function testCheckoutFailsWithoutAWebhookUrl(): void
    {
        WebhookUrls::resolveUsing(null);
        $order = new Order(EntityId::generate(), 'client-9', EntityId::generate(), EntityId::generate(),
            Money::create(50, Currency::fromString('USD')), 'hash');

        $this->expectExceptionMessage('WebhookUrls::resolveUsing');
        $this->adapter()->createCheckoutSession($order, 'https://campus.example.com/ok', 'https://campus.example.com/cancel');
    }

    public function testNotificationsSignedForTheWebhookPathAreAccepted(): void
    {
        $body = $this->notification();
        $config = ['alipay_public_key' => $this->publicKey, 'client_id' => 'client-1'];

        $event = (new AlipayWebhookAdapter())->parse($body, $this->signedHeaders($body, '/webhooks/ase/alipay'), $config);

        $this->assertSame('INITIAL_PAYMENT', $event->action);
        $this->assertSame(50.0, $event->data['amount']);
        $this->assertTrue($this->adapter()->validateWebhook([], ['X-RAW-BODY' => $body] + $this->signedHeaders($body, '/webhooks/ase/alipay')));
    }

    public function testASignatureForAnotherPathIsRejected(): void
    {
        $body = $this->notification();

        $this->expectException(WebhookSignatureException::class);
        (new AlipayWebhookAdapter())->parse($body, $this->signedHeaders($body, '/webhooks/alipay'),
            ['alipay_public_key' => $this->publicKey, 'client_id' => 'client-1']);
    }

    public function testAnExplicitNotifyPathStillWins(): void
    {
        $body = $this->notification();
        $event = (new AlipayWebhookAdapter())->parse($body, $this->signedHeaders($body, '/legacy/notify'),
            ['alipay_public_key' => $this->publicKey, 'client_id' => 'client-1', 'notify_path' => '/legacy/notify']);

        $this->assertSame('INITIAL_PAYMENT', $event->action);
    }

    public function testUnusableWebhookUrlsAreRejected(): void
    {
        foreach (['/webhooks/ase/alipay', 'ftp://x.test/a', 'https://x.test/a?token=1', 'https://x.test'] as $url) {
            WebhookUrls::resolveUsing(fn () => $url);
            try {
                WebhookUrls::for('alipay');
                $this->fail("Accepted: {$url}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        WebhookUrls::resolveUsing(fn () => null);
        $this->assertNull(WebhookUrls::pathFor('alipay'));
    }
}
