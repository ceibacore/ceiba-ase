<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Infrastructure\Payments;

use PHPUnit\Framework\TestCase;
use LemurAse\Infrastructure\Payments\GatewayAdapterRegistry;
use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Infrastructure\Payments\StripeAdapter;
use LemurAse\Infrastructure\Payments\PayPalAdapter;
use LemurAse\Domain\Entities\Order;

class GatewayAdapterRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset registry before each test to ensure isolation
        GatewayAdapterRegistry::reset();
    }

    public function testBootRegistersDefaultAdapters(): void
    {
        // has() will automatically call boot()
        $this->assertTrue(GatewayAdapterRegistry::has('stripe'));
        $this->assertTrue(GatewayAdapterRegistry::has('paypal'));
    }

    public function testMakeDefaultStripeAdapter(): void
    {
        $credentials = [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123',
            'webhook_secret' => 'whsec_123',
            'test_mode' => true,
        ];

        $adapter = GatewayAdapterRegistry::make('stripe', $credentials);
        $this->assertInstanceOf(StripeAdapter::class, $adapter);
    }

    public function testMakeDefaultPayPalAdapter(): void
    {
        $credentials = [
            'client_id' => 'client_123',
            'client_secret' => 'secret_123',
            'webhook_id' => 'webhook_123',
            'sandbox' => true,
        ];

        $adapter = GatewayAdapterRegistry::make('paypal', $credentials);
        $this->assertInstanceOf(PayPalAdapter::class, $adapter);
    }

    public function testCanRegisterCustomAdapter(): void
    {
        $this->markTestSkipped('Skipped due to PHPUnit 12 process serialization crash with anonymous classes');
        $customAdapter = new class implements PaymentGatewayInterface {
            public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array { return []; }
            public function validateWebhook(array $payload, array $headers): bool { return true; }
            public function parseWebhookEvent(array $payload): array { return []; }
            public function refund(string $externalTransactionId, float $amount, string $reason): array { return []; }
        };

        GatewayAdapterRegistry::register('custom_gateway', function(array $credentials) use ($customAdapter) {
            return $customAdapter;
        });

        $this->assertTrue(GatewayAdapterRegistry::has('custom_gateway'));
        
        $resolvedAdapter = GatewayAdapterRegistry::make('custom_gateway', ['secret' => 'custom_secret']);
        $this->assertSame($customAdapter, $resolvedAdapter);
    }

    public function testCanOverrideDefaultAdapter(): void
    {
        $mockStripe = $this->createMock(PaymentGatewayInterface::class);

        GatewayAdapterRegistry::register('stripe', function(array $credentials) use ($mockStripe) {
            return $mockStripe;
        });

        // Even after boot, the overridden adapter should remain or take precedence if registered after boot
        $resolvedAdapter = GatewayAdapterRegistry::make('stripe', []);
        $this->assertSame($mockStripe, $resolvedAdapter);
    }

    public function testThrowsExceptionForUnknownProvider(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No adapter factory registered for provider: unknown_provider");

        GatewayAdapterRegistry::make('unknown_provider', []);
    }

    public function testThrowsExceptionIfFactoryReturnsInvalidType(): void
    {
        GatewayAdapterRegistry::register('invalid_gateway', function() {
            return new \stdClass(); // Not a PaymentGatewayInterface
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Factory for 'invalid_gateway' must return an instance of PaymentGatewayInterface.");

        GatewayAdapterRegistry::make('invalid_gateway', []);
    }
}
