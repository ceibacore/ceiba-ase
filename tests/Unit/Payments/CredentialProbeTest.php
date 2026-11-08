<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Payments;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LemurAse\AseManager;
use LemurAse\Infrastructure\Http\HttpClientFactory;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/** Credential checks run before an administrator can enable a gateway. */
final class CredentialProbeTest extends TestCase
{
    private MockHandler $http;
    private array $sent = [];

    protected function setUp(): void
    {
        $this->http = new MockHandler();
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::history($this->sent));
        HttpClientFactory::useHandler($stack);
    }

    protected function tearDown(): void
    {
        HttpClientFactory::useHandler(null);
        ApiRequestor::setHttpClient(null);
    }

    private function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function testPaystackChecksTheBalanceWithTheSecretKey(): void
    {
        $this->http->append($this->json(['status' => true, 'data' => [['currency' => 'NGN', 'balance' => 0]]]));
        $result = AseManager::testGatewayCredentials('paystack', ['secret_key' => 'sk_live_abc0123456789', 'public_key' => 'pk_live_x']);
        $this->assertSame([true, true], [$result['ok'], $result['livemode']]);
        $this->assertSame('/balance', $this->sent[0]['request']->getUri()->getPath());

        $this->http->append($this->json(['status' => false, 'message' => 'Invalid key'], 401));
        $failed = AseManager::testGatewayCredentials('paystack', ['secret_key' => 'sk_test_wrong', 'public_key' => 'pk']);
        $this->assertFalse($failed['ok']);
        $this->assertStringContainsString('Invalid key', $failed['message']);
    }

    public function testRazorpayChecksThePaymentsListWithTheKeyPair(): void
    {
        $this->http->append($this->json(['entity' => 'collection', 'count' => 0, 'items' => []]));
        $result = AseManager::testGatewayCredentials('razorpay', ['key_id' => 'rzp_test_k', 'key_secret' => 's', 'webhook_secret' => 'w']);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['livemode']);
        $this->assertSame('Basic ' . base64_encode('rzp_test_k:s'), $this->sent[0]['request']->getHeaderLine('Authorization'));
    }

    public function testMercadoPagoChecksTheAccount(): void
    {
        $this->http->append($this->json(['id' => 1]), $this->json(['message' => 'invalid token'], 401));
        $creds = ['access_token' => 'TEST-123', 'public_key' => 'TEST-pk', 'webhook_secret' => 'w'];

        $this->assertSame([true, false], array_values(array_intersect_key(AseManager::testGatewayCredentials('mercadopago', $creds), ['ok' => 0, 'livemode' => 0])));
        $this->assertFalse(AseManager::testGatewayCredentials('mercadopago', $creds)['ok']);
        $this->assertSame('/users/me', $this->sent[0]['request']->getUri()->getPath());
    }

    public function testPayPalRequestsAnAccessToken(): void
    {
        $this->http->append($this->json(['access_token' => 'A21', 'expires_in' => 3600]));
        $result = AseManager::testGatewayCredentials('paypal', ['client_id' => 'id', 'client_secret' => 's', 'webhook_id' => 'w', 'sandbox' => true]);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['livemode']);
    }

    public function testStripeRetrievesTheBalance(): void
    {
        ApiRequestor::setHttpClient(new class implements ClientInterface {
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return str_ends_with((string) $absUrl, '/v1/balance')
                    ? [json_encode(['object' => 'balance', 'livemode' => false]), 200, []]
                    : [json_encode(['error' => ['message' => 'unexpected']]), 400, []];
            }
        });
        $result = AseManager::testGatewayCredentials('stripe', ['publishable_key' => 'pk_test_x', 'secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x']);
        $this->assertSame([true, false], [$result['ok'], $result['livemode']]);
    }

    public function testAlipayKeysAreValidatedOffline(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        $public = openssl_pkey_get_details($key)['key'];
        $creds = ['client_id' => 'c', 'private_key' => $private, 'alipay_public_key' => $public, 'merchant_id' => 'm', 'sandbox' => true];

        $this->assertTrue(AseManager::testGatewayCredentials('alipay', $creds)['ok']);
        $this->assertFalse(AseManager::testGatewayCredentials('alipay', ['private_key' => 'not-a-key'] + $creds)['ok']);
        $this->assertSame([], $this->sent, 'No network call');
    }

    public function testIncompleteCredentialsFailWithoutCallingTheProvider(): void
    {
        $result = AseManager::testGatewayCredentials('razorpay', ['key_id' => 'rzp_test_k']);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('key_secret', $result['message']);
        $this->assertSame([], $this->sent);
    }
}
