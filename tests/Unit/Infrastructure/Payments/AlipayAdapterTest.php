<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Infrastructure\Payments;

use PHPUnit\Framework\TestCase;
use LemurAse\Infrastructure\Payments\AlipayAdapter;

class AlipayAdapterTest extends TestCase
{
    private AlipayAdapter $adapter;
    private string $privateKey = '';
    private string $publicKey = '';

    protected function setUp(): void
    {
        $res = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $priv = '';
        openssl_pkey_export($res, $priv);
        $this->privateKey = $priv;
        $pubDetails = openssl_pkey_get_details($res);
        $this->publicKey = $pubDetails['key'];

        $this->adapter = new AlipayAdapter(
            clientId: 'SANDBOX_2188000123456789',
            privateKey: $this->privateKey,
            alipayPublicKey: $this->publicKey,
            merchantId: '2188000123456789',
            sandbox: true
        );
    }

    public function testCancelSubscriptionReturnsUnsupportedError(): void
    {
        $result = $this->adapter->cancelSubscription('sub_123');
        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('not support', $result['error']);
    }

    public function testPauseSubscriptionReturnsUnsupportedError(): void
    {
        $result = $this->adapter->pauseSubscription('sub_123');
        $this->assertEquals('failed', $result['status']);
        $this->assertStringContainsString('not support', $result['error']);
    }

    public function testParseWebhookEventSuccessNotification(): void
    {
        $payload = [
            'notifyType'        => 'notifyPayment',
            'paymentId'         => '2026081012345678',
            'paymentRequestId'  => 'order-uuid-9999',
            'result'            => ['resultStatus' => 'S'],
            'paymentAmount'     => ['value' => '5000', 'currency' => 'USD'],
        ];

        $result = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('INITIAL_PAYMENT', $result['action']);
        $this->assertEquals('2026081012345678', $result['external_order_id']);
        $this->assertEquals('order-uuid-9999', $result['internal_order_id']);
        $this->assertEquals(50.00, $result['amount']);
        $this->assertEquals('USD', $result['currency']);
    }

    public function testParseWebhookEventFailureNotification(): void
    {
        $payload = [
            'notifyType'        => 'notifyPayment',
            'paymentId'         => '2026081012345678',
            'paymentRequestId'  => 'order-uuid-9999',
            'result'            => ['resultStatus' => 'F'],
            'paymentAmount'     => ['value' => '5000', 'currency' => 'USD'],
        ];

        $result = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('PAYMENT_FAILED', $result['action']);
    }

    public function testParseWebhookEventRefundNotification(): void
    {
        $payload = [
            'notifyType'        => 'notifyRefund',
            'paymentId'         => '2026081012345678',
            'paymentRequestId'  => 'order-uuid-9999',
            'result'            => ['resultStatus' => 'S'],
            'refundAmount'      => ['value' => '5000', 'currency' => 'USD'],
        ];

        $result = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('REFUND_PROCESSED', $result['action']);
        $this->assertTrue($result['full_refund']);
    }
}
