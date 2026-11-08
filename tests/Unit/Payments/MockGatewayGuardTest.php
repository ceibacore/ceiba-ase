<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\Payments;

use LemurAse\Infrastructure\Payments\GatewayAdapterRegistry;
use LemurAse\Infrastructure\Payments\MockAdapter;
use PHPUnit\Framework\TestCase;

final class MockGatewayGuardTest extends TestCase
{
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (['ASE_ENV', 'APP_ENV', 'ASE_ALLOW_MOCK_GATEWAY'] as $key) {
            $this->previous[$key] = getenv($key);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
    }

    public function testMockIsAvailableOutsideProduction(): void
    {
        putenv('APP_ENV=local');
        $this->assertInstanceOf(MockAdapter::class, GatewayAdapterRegistry::make('mock', []));
    }

    public function testMockIsRefusedInProductionUnlessExplicitlyAllowed(): void
    {
        putenv('APP_ENV=production');
        try {
            GatewayAdapterRegistry::make('mock', []);
            $this->fail('Mock gateway created in production');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('disabled in production', $e->getMessage());
        }
        putenv('ASE_ALLOW_MOCK_GATEWAY=true');
        $this->assertInstanceOf(MockAdapter::class, GatewayAdapterRegistry::make('mock', []));
    }
}
