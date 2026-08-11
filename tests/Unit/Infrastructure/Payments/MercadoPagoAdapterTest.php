<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Infrastructure\Payments;

use PHPUnit\Framework\TestCase;
use LemurAse\Infrastructure\Payments\MercadoPagoAdapter;
use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;

class MercadoPagoAdapterTest extends TestCase
{
    private MercadoPagoAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new MercadoPagoAdapter(
            accessToken: 'APP_USR-test-access-token-1234',
            publicKey: 'APP_USR-test-public-key-1234',
            webhookSecret: 'test-webhook-secret-1234',
            sandbox: true
        );
    }

    public function testValidateWebhookWithValidHmacSignature(): void
    {
        $payload = [
            'action' => 'payment.created',
            'data'   => ['id' => '123456789'],
        ];

        $ts = (string) time();
        $requestId = 'req_test_abc123';
        $signedString = sprintf('id:%s;request-id:%s;ts:%s;', '123456789', $requestId, $ts);
        $v1 = hash_hmac('sha256', $signedString, 'test-webhook-secret-1234');

        $headers = [
            'x-signature'  => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ];

        $this->assertTrue($this->adapter->validateWebhook($payload, $headers));
    }

    public function testValidateWebhookRejectsInvalidSignature(): void
    {
        $payload = [
            'action' => 'payment.created',
            'data'   => ['id' => '123456789'],
        ];

        $headers = [
            'x-signature'  => 'ts=12345,v1=invalid_hmac_hash',
            'x-request-id' => 'req_test_abc123',
        ];

        $this->assertFalse($this->adapter->validateWebhook($payload, $headers));
    }

    public function testValidateWebhookRejectsMissingHeaders(): void
    {
        $payload = ['data' => ['id' => '123']];
        $headers = [];

        $this->assertFalse($this->adapter->validateWebhook($payload, $headers));
    }

    public function testParseWebhookEventReturnsIgnoredIfDataIdMissing(): void
    {
        $payload = ['action' => 'payment.created']; // missing data.id
        $result = $this->adapter->parseWebhookEvent($payload);

        $this->assertEquals('IGNORED', $result['action']);
    }
}
