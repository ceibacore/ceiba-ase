<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Payments;

use LemurAse\Domain\ValueObjects\MinorUnits;
use LemurAse\Infrastructure\Payments\Alipay\AlipaySignature;
use LemurAse\Infrastructure\Payments\MercadoPago\MercadoPagoSignature;
use LemurAse\Infrastructure\Payments\PayPal\PayPalWebhookGuard;
use LemurAse\WebhookManagement\Infrastructure\Adapters\StripeWebhookAdapter;
use PHPUnit\Framework\TestCase;

final class PaymentSecurityPrimitivesTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testMinorUnitsFollowIso4217Exponents(): void
    {
        $this->assertSame(1050, MinorUnits::toMinor(10.50, 'usd'));
        $this->assertSame(1000, MinorUnits::toMinor(1000, 'JPY'), 'JPY has no decimals: never multiply by 100');
        $this->assertSame(5000, MinorUnits::toMinor(5000, 'CLP'));
        $this->assertSame(1234, MinorUnits::toMinor(1.234, 'KWD'));
        $this->assertSame(19.99, MinorUnits::fromMinor(1999, 'EUR'));
        $this->assertSame(1000.0, MinorUnits::fromMinor(1000, 'JPY'));
        $this->assertSame('10.50', MinorUnits::format(10.5, 'USD'));
        $this->assertSame('1000', MinorUnits::format(1000, 'JPY'));
        $this->assertTrue(MinorUnits::equals(0.1 + 0.2, 0.3, 'USD'));
    }

    public function testPayPalGuardAcceptsGenuineHeaders(): void
    {
        PayPalWebhookGuard::assertPlausible('8b7f0a10-1d11-11ef-9b8c-0b3b8a8f1d2e', gmdate('Y-m-d\TH:i:s\Z', self::NOW - 60),
            'https://api.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-1d93a270', 'SHA256withRSA', false, 900, self::NOW);
        PayPalWebhookGuard::assertPlausible('8b7f0a10-1d11-11ef-9b8c-0b3b8a8f1d2e', gmdate('Y-m-d\TH:i:s\Z', self::NOW),
            'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-abc', 'SHA256withRSA', true, 900, self::NOW);
        $this->addToAssertionCount(2);
    }

    public function testPayPalGuardRejectsForgedOrReplayedTransmissions(): void
    {
        $id = '8b7f0a10-1d11-11ef-9b8c-0b3b8a8f1d2e';
        $time = gmdate('Y-m-d\TH:i:s\Z', self::NOW);
        $cert = 'https://api.paypal.com/v1/notifications/certs/CERT-1';
        $cases = [
            'attacker cert host'      => [$id, $time, 'https://evil.example/v1/notifications/certs/CERT-1', 'SHA256withRSA', false],
            'lookalike host'          => [$id, $time, 'https://api.paypal.com.evil.example/v1/notifications/certs/C', 'SHA256withRSA', false],
            'plain http'              => [$id, $time, 'http://api.paypal.com/v1/notifications/certs/CERT-1', 'SHA256withRSA', false],
            'credentials in url'      => [$id, $time, 'https://x@api.paypal.com/v1/notifications/certs/CERT-1', 'SHA256withRSA', false],
            'non standard port'       => [$id, $time, 'https://api.paypal.com:8443/v1/notifications/certs/CERT-1', 'SHA256withRSA', false],
            'not a cert path'         => [$id, $time, 'https://api.paypal.com/v1/oauth2/token', 'SHA256withRSA', false],
            'sandbox cert on live'    => [$id, $time, 'https://api.sandbox.paypal.com/v1/notifications/certs/C', 'SHA256withRSA', false],
            'weak algorithm'          => [$id, $time, $cert, 'SHA1withRSA', false],
            'stale transmission'      => [$id, gmdate('Y-m-d\TH:i:s\Z', self::NOW - 901), $cert, 'SHA256withRSA', false],
            'future transmission'     => [$id, gmdate('Y-m-d\TH:i:s\Z', self::NOW + 901), $cert, 'SHA256withRSA', false],
            'unparseable time'        => [$id, 'yesterday', $cert, 'SHA256withRSA', false],
            'malformed id'            => ['../../x', $time, $cert, 'SHA256withRSA', false],
        ];
        foreach ($cases as $name => [$tid, $ttime, $turl, $algo, $sandbox]) {
            try {
                PayPalWebhookGuard::assertPlausible($tid, $ttime, $turl, $algo, $sandbox, 900, self::NOW);
                $this->fail("Accepted: {$name}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMercadoPagoSignatureWindowAndInputs(): void
    {
        $secret = 'mp-secret';
        $sign = fn (string $id, string $req, string $ts) => [
            'x-signature' => "ts={$ts},v1=" . hash_hmac('sha256', "id:{$id};request-id:{$req};ts:{$ts};", $secret),
            'x-request-id' => $req,
        ];
        MercadoPagoSignature::verify($sign('123', 'req-1', (string) self::NOW), '123', $secret, 900, self::NOW);
        MercadoPagoSignature::verify($sign('123', 'req-1', (string) (self::NOW * 1000)), '123', $secret, 900, self::NOW);
        $this->addToAssertionCount(2);

        $cases = [
            'wrong secret'   => fn () => MercadoPagoSignature::verify($sign('123', 'req-1', (string) self::NOW), '123', 'other', 900, self::NOW),
            'tampered id'    => fn () => MercadoPagoSignature::verify($sign('123', 'req-1', (string) self::NOW), '124', $secret, 900, self::NOW),
            'replayed'       => fn () => MercadoPagoSignature::verify($sign('123', 'req-1', (string) (self::NOW - 901)), '123', $secret, 900, self::NOW),
            'empty secret'   => fn () => MercadoPagoSignature::verify($sign('123', 'req-1', (string) self::NOW), '123', '', 900, self::NOW),
            'path injection' => fn () => MercadoPagoSignature::verify($sign('1/../../x', 'r', (string) self::NOW), '1/../../x', $secret, 900, self::NOW),
            'no headers'     => fn () => MercadoPagoSignature::verify([], '123', $secret, 900, self::NOW),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("Accepted: {$name}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAlipaySignatureVerifiesExactBodyPathAndTime(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $public = openssl_pkey_get_details($key)['key'];
        $body = '{"paymentId":"pay_1","paymentRequestId":"order-1","result":{"resultStatus":"S"}}';
        $headers = function (string $time, string $path = '/webhooks/alipay', string $signedBody = '') use ($key, $body) {
            openssl_sign("POST {$path}\nclient-1.{$time}." . ($signedBody ?: $body), $sig, $key, OPENSSL_ALGO_SHA256);
            return ['signature' => 'algorithm=RSA256,keyVersion=1,signature=' . urlencode(base64_encode($sig)),
                'client-id' => 'client-1', 'request-time' => $time];
        };
        $now = gmdate('Y-m-d\TH:i:s\Z', self::NOW);
        AlipaySignature::verify($body, $headers($now), $public, 'client-1', '/webhooks/alipay', 900, self::NOW);
        AlipaySignature::verify($body, $headers((string) (self::NOW * 1000), '/pay/notify'), $public, 'client-1', '/pay/notify', 900, self::NOW);
        $this->addToAssertionCount(2);

        $cases = [
            'tampered body' => fn () => AlipaySignature::verify(str_replace('"S"', '"F"', $body), $headers($now), $public, 'c', '/webhooks/alipay', 900, self::NOW),
            'other path'    => fn () => AlipaySignature::verify($body, $headers($now, '/other'), $public, 'c', '/webhooks/alipay', 900, self::NOW),
            'replayed'      => fn () => AlipaySignature::verify($body, $headers(gmdate('Y-m-d\TH:i:s\Z', self::NOW - 901)), $public, 'c', '/webhooks/alipay', 900, self::NOW),
            'no signature'  => fn () => AlipaySignature::verify($body, ['request-time' => $now], $public, 'c', '/webhooks/alipay', 900, self::NOW),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                $this->fail("Accepted: {$name}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStripeWebhookActivatesOnlyPaidSessionsAndUsesMinorUnits(): void
    {
        $secret = 'whsec_test';
        $event = function (string $type, array $object) use ($secret): array {
            $payload = json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'object' => 'event', 'type' => $type,
                'data' => ['object' => $object]]);
            $t = time();
            return [$payload, ['stripe-signature' => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$payload}", $secret)]];
        };
        $adapter = new StripeWebhookAdapter();
        $session = ['object' => 'checkout.session', 'id' => 'cs_1', 'amount_total' => 1500, 'currency' => 'jpy',
            'payment_status' => 'paid', 'metadata' => ['order_id' => 'order-1'], 'client_reference_id' => 'client-1'];

        [$payload, $headers] = $event('checkout.session.completed', $session);
        $paid = $adapter->parse($payload, $headers, ['webhook_secret' => $secret]);
        $this->assertSame('INITIAL_PAYMENT', $paid->action);
        $this->assertSame(1500.0, $paid->data['amount'], 'JPY amount_total is already in yen');
        $this->assertSame('JPY', $paid->data['currency']);
        $this->assertSame('order-1', $paid->data['internal_order_id']);

        [$payload, $headers] = $event('checkout.session.completed', ['payment_status' => 'unpaid'] + $session);
        $this->assertSame('UNKNOWN', $adapter->parse($payload, $headers, ['webhook_secret' => $secret])->action);

        [$payload, $headers] = $event('checkout.session.async_payment_succeeded', ['currency' => 'usd', 'amount_total' => 1999] + $session);
        $async = $adapter->parse($payload, $headers, ['webhook_secret' => $secret]);
        $this->assertSame('INITIAL_PAYMENT', $async->action);
        $this->assertSame(19.99, $async->data['amount']);

        [$payload] = $event('checkout.session.completed', $session);
        $this->expectExceptionMessage('Invalid signature');
        $adapter->parse($payload, ['stripe-signature' => 't=1,v1=deadbeef'], ['webhook_secret' => $secret]);
    }
}
