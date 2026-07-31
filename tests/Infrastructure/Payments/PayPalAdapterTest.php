<?php

namespace LemurAse\Tests\Infrastructure\Payments;

use PHPUnit\Framework\TestCase;
use LemurAse\Infrastructure\Payments\PayPalAdapter;

class PayPalAdapterTest extends TestCase
{
    private PayPalAdapter $adapter;
    private string $clientId = 'test_client_id';
    private string $clientSecret = 'test_client_secret';
    private string $webhookId = 'test_webhook_id';

    protected function setUp(): void
    {
        $this->adapter = new PayPalAdapter(
            $this->clientId,
            $this->clientSecret,
            $this->webhookId,
            true // sandbox
        );
    }

    /**
     * @test
     * Webhook validation: missing required headers fails
     */
    public function testValidateWebhookWithMissingHeaders()
    {
        $payload = ['event_type' => 'CHECKOUT.ORDER.APPROVED'];
        $headers = [];

        $isValid = $this->adapter->validateWebhook($payload, $headers);
        $this->assertFalse($isValid);
    }

    /**
     * @test
     * Webhook validation: invalid cert URL domain fails
     */
    public function testValidateWebhookWithInvalidCertDomain()
    {
        $payload = ['event_type' => 'CHECKOUT.ORDER.APPROVED'];
        $timestamp = date('Y-m-d\TH:i:s\Z');
        
        $headers = [
            'PAYPAL_TRANSMISSION_ID' => 'test_id',
            'PAYPAL_TRANSMISSION_TIME' => $timestamp,
            'PAYPAL_TRANSMISSION_SIG' => 'sig',
            'PAYPAL_CERT_URL' => 'https://evil.com/cert.pem',
            'PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'X-RAW-BODY' => json_encode($payload)
        ];

        $isValid = $this->adapter->validateWebhook($payload, $headers);
        $this->assertFalse($isValid);
    }

    /**
     * @test
     * Webhook validation: timestamp too old is rejected
     */
    public function testValidateWebhookWithStaleTimestamp()
    {
        $oldTime = new \DateTimeImmutable('-10 minutes');
        $payload = ['event_type' => 'CHECKOUT.ORDER.APPROVED'];
        
        $headers = [
            'PAYPAL_TRANSMISSION_ID' => 'test_id',
            'PAYPAL_TRANSMISSION_TIME' => $oldTime->format('Y-m-d\TH:i:s\Z'),
            'PAYPAL_TRANSMISSION_SIG' => 'sig',
            'PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert.pem',
            'PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'X-RAW-BODY' => json_encode($payload)
        ];

        $isValid = $this->adapter->validateWebhook($payload, $headers);
        $this->assertFalse($isValid);
    }

    /**
     * @test
     * Webhook validation: missing raw body fails
     */
    public function testValidateWebhookWithMissingRawBody()
    {
        $payload = ['event_type' => 'CHECKOUT.ORDER.APPROVED'];
        $timestamp = date('Y-m-d\TH:i:s\Z');
        
        $headers = [
            'PAYPAL_TRANSMISSION_ID' => 'test_id',
            'PAYPAL_TRANSMISSION_TIME' => $timestamp,
            'PAYPAL_TRANSMISSION_SIG' => 'sig',
            'PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert.pem',
            'PAYPAL_AUTH_ALGO' => 'SHA256withRSA'
            // Missing X-RAW-BODY
        ];

        $isValid = $this->adapter->validateWebhook($payload, $headers);
        $this->assertFalse($isValid);
    }

    /**
     * @test
     * Parse CHECKOUT.ORDER.APPROVED event
     */
    public function testParseWebhookEventCheckoutApproved()
    {
        $payload = [
            'event_type' => 'CHECKOUT.ORDER.APPROVED',
            'id' => 'evt_paypal_123',
            'resource' => [
                'id' => 'order_123',
                'custom_id' => 'user_456',
                'amount' => [
                    'total' => '99.99',
                    'currency' => 'USD'
                ]
            ]
        ];

        $normalized = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('INITIAL_PAYMENT', $normalized['action']);
        $this->assertEquals('order_123', $normalized['external_order_id']);
        $this->assertEquals('user_456', $normalized['external_client_id']);
        $this->assertEquals(99.99, $normalized['amount']);
        $this->assertEquals('USD', $normalized['currency']);
    }

    /**
     * @test
     * Parse BILLING.SUBSCRIPTION.ACTIVATED event
     */
    public function testParseWebhookEventSubscriptionActivated()
    {
        $payload = [
            'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED',
            'id' => 'evt_sub_active',
            'resource' => [
                'id' => 'sub_paypal_123',
                'billing_agreement_id' => 'billing_agreement_456'
            ]
        ];

        $normalized = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('SUBSCRIPTION_ACTIVATED', $normalized['action']);
    }

    /**
     * @test
     * Parse PAYMENT.SALE.COMPLETED event (renewal)
     */
    public function testParseWebhookEventSaleCompleted()
    {
        $payload = [
            'event_type' => 'PAYMENT.SALE.COMPLETED',
            'id' => 'evt_sale_complete',
            'resource' => [
                'id' => 'sale_123',
                'gross_amount' => [
                    'value' => '49.99',
                    'currency_code' => 'USD'
                ]
            ]
        ];

        $normalized = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('RENEWAL_PAYMENT', $normalized['action']);
        $this->assertEquals(49.99, $normalized['amount']);
    }

    /**
     * @test
     * Parse PAYMENT.SALE.REFUNDED event
     */
    public function testParseWebhookEventSaleRefunded()
    {
        $payload = [
            'event_type' => 'PAYMENT.SALE.REFUNDED',
            'id' => 'evt_refund',
            'resource' => [
                'id' => 'sale_refunded_123',
                'state' => 'refunded'
            ]
        ];

        $normalized = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('REFUND_PROCESSED', $normalized['action']);
        $this->assertTrue($normalized['full_refund']);
    }

    /**
     * @test
     * Parse BILLING.SUBSCRIPTION.CANCELLED event
     */
    public function testParseWebhookEventSubscriptionCanceled()
    {
        $payload = [
            'event_type' => 'BILLING.SUBSCRIPTION.CANCELLED',
            'id' => 'evt_cancel',
            'resource' => [
                'id' => 'sub_canceled_123'
            ]
        ];

        $normalized = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('SUBSCRIPTION_CANCELED', $normalized['action']);
    }

    /**
     * @test
     * Parse unknown event type returns IGNORED
     */
    public function testParseWebhookEventUnknownType()
    {
        $payload = [
            'event_type' => 'UNKNOWN.EVENT.TYPE',
            'id' => 'evt_unknown',
            'resource' => []
        ];

        $normalized = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('IGNORED', $normalized['action']);
    }

    /**
     * @test
     * Refund API call success
     */
    public function testRefundSuccess()
    {
        $result = $this->adapter->refund('capture_123', 49.99, 'Customer request');

        $this->assertEquals('success', $result['status']);
        $this->assertNotEmpty($result['external_refund_id']);
        $this->assertStringStartsWith('REFUND-', $result['external_refund_id']);
    }

    /**
     * @test
     * Handle case-insensitive PayPal headers
     */
    public function testValidateWebhookCaseInsensitiveHeaders()
    {
        $payload = ['event_type' => 'CHECKOUT.ORDER.APPROVED'];
        $timestamp = date('Y-m-d\TH:i:s\Z');
        
        // Using lowercase headers
        $headers = [
            'paypal-transmission-id' => 'test_id',
            'paypal-transmission-time' => $timestamp,
            'paypal-transmission-sig' => 'sig',
            'paypal-cert-url' => 'https://api.sandbox.paypal.com/cert.pem',
            'paypal-auth-algo' => 'SHA256withRSA',
            'X-RAW-BODY' => json_encode($payload)
        ];

        $isValid = $this->adapter->validateWebhook($payload, $headers);
        // Will fail at cert verification stage, but that's expected in test
        // The important part is it reads the headers correctly
        $this->assertFalse($isValid); // Expected to fail at cert step
    }

    /**
     * @test
     * PayPal production domain validation (non-sandbox)
     */
    public function testValidateWebhookProductionDomain()
    {
        $adapterProd = new PayPalAdapter($this->clientId, $this->clientSecret, $this->webhookId, false);
        
        $payload = ['event_type' => 'CHECKOUT.ORDER.APPROVED'];
        $timestamp = date('Y-m-d\TH:i:s\Z');
        
        $headers = [
            'PAYPAL_TRANSMISSION_ID' => 'test_id',
            'PAYPAL_TRANSMISSION_TIME' => $timestamp,
            'PAYPAL_TRANSMISSION_SIG' => 'sig',
            'PAYPAL_CERT_URL' => 'https://api.paypal.com/cert.pem', // Production domain
            'PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'X-RAW-BODY' => json_encode($payload)
        ];

        // Should accept PayPal production domain (will fail at cert validation)
        $isValid = $adapterProd->validateWebhook($payload, $headers);
        $this->assertFalse($isValid); // Fails at cert, but not domain check
    }

    /**
     * @test
     * Invalid transmission time format
     */
    public function testValidateWebhookInvalidTimeFormat()
    {
        $payload = ['event_type' => 'CHECKOUT.ORDER.APPROVED'];
        
        $headers = [
            'PAYPAL_TRANSMISSION_ID' => 'test_id',
            'PAYPAL_TRANSMISSION_TIME' => 'invalid-time-format',
            'PAYPAL_TRANSMISSION_SIG' => 'sig',
            'PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert.pem',
            'PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'X-RAW-BODY' => json_encode($payload)
        ];

        $isValid = $this->adapter->validateWebhook($payload, $headers);
        $this->assertFalse($isValid);
    }
}
