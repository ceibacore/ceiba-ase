<?php

namespace LemurAse\Tests\Domain\Services;

use PHPUnit\Framework\TestCase;
use LemurAse\Domain\Services\SecurityService;
use LemurAse\Shared\EnvironmentGuard;

class SecurityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['ASE_SECRET_KEY'] = 'test_secret_key_123';
    }

    public function testGenerateHashCreatesConsistentValidSignature()
    {
        $service = new SecurityService();
        
        $data = [
            'client_id' => 'client_1',
            'plan_price_id' => 'plan_price_123',
            'amount' => 19.99
        ];

        $hash1 = $service->generateHash($data);
        $hash2 = $service->generateHash($data);

        $this->assertEquals($hash1, $hash2);
        $this->assertNotEmpty($hash1);
    }

    public function testValidateHashReturnsTrueForValidData()
    {
        $service = new SecurityService();
        
        $data = [
            'client_id' => 'client_1',
            'plan_price_id' => 'plan_price_123',
            'amount' => 19.99
        ];

        $hash = $service->generateHash($data);

        $this->assertTrue($service->validateHash($data, $hash));
    }

    public function testValidateHashReturnsFalseForTamperedData()
    {
        $service = new SecurityService();
        
        $data = [
            'client_id' => 'client_1',
            'plan_price_id' => 'plan_price_123',
            'amount' => 19.99
        ];

        $hash = $service->generateHash($data);

        // Tamper with the amount
        $data['amount'] = 1.99;

        $this->assertFalse($service->validateHash($data, $hash));
    }
}
