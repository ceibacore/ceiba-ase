<?php

namespace LemurAse\Tests\Unit\Domain\Entities;

use PHPUnit\Framework\TestCase;
use LemurAse\Domain\Entities\Gateway;
use LemurAse\Domain\ValueObjects\EntityId;

class GatewayTest extends TestCase
{
    /**
     * Test Gateway constructor with valid credentials array
     */
    public function testConstructorWithValidCredentialsArray()
    {
        $id = EntityId::generate();
        $credentials = [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123'
        ];

        $gateway = new Gateway($id, 'stripe', $credentials, true);

        $this->assertNotNull($gateway);
        $this->assertEquals('stripe', $gateway->provider());
        $this->assertEquals($credentials, $gateway->credentials());
        $this->assertTrue($gateway->isActive());
    }

    /**
     * Test Gateway provider() method returns correct value
     */
    public function testProviderMethodReturnsCorrectValue()
    {
        $id = EntityId::generate();
        $credentials = [
            'client_id' => 'client_id_test',
            'client_secret' => 'client_secret_test'
        ];

        $gateway = new Gateway($id, 'paypal', $credentials);

        $this->assertEquals('paypal', $gateway->provider());
    }

    /**
     * Test Gateway credentials() method returns credentials array
     */
    public function testCredentialsMethodReturnsCredentialsArray()
    {
        $id = EntityId::generate();
        $credentials = [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123',
            'webhook_secret' => 'whsec_test_123'
        ];

        $gateway = new Gateway($id, 'stripe', $credentials);

        $this->assertEquals($credentials, $gateway->credentials());
        $this->assertArrayHasKey('publishable_key', $gateway->credentials());
        $this->assertArrayHasKey('secret_key', $gateway->credentials());
        $this->assertArrayHasKey('webhook_secret', $gateway->credentials());
    }

    /**
     * Test Gateway isActive() method returns boolean
     */
    public function testIsActiveMethodReturnsBooleanTrue()
    {
        $id = EntityId::generate();
        $credentials = [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123'
        ];

        $gateway = new Gateway($id, 'stripe', $credentials, true);

        $this->assertTrue($gateway->isActive());
    }

    /**
     * Test Gateway isActive() method returns false when inactive
     */
    public function testIsActiveMethodReturnsFalse()
    {
        $id = EntityId::generate();
        $credentials = [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123'
        ];

        $gateway = new Gateway($id, 'stripe', $credentials, false);

        $this->assertFalse($gateway->isActive());
    }

    /**
     * Test Gateway with Stripe credentials (publishable_key + secret_key)
     */
    public function testStripeGatewayHasCorrectCredentials()
    {
        $id = EntityId::generate();
        $credentials = [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123',
            'webhook_secret' => 'whsec_test_123'
        ];

        $gateway = new Gateway($id, 'stripe', $credentials);

        $creds = $gateway->credentials();
        $this->assertArrayHasKey('publishable_key', $creds);
        $this->assertArrayHasKey('secret_key', $creds);
        $this->assertEquals('pk_test_123', $creds['publishable_key']);
        $this->assertEquals('sk_test_123', $creds['secret_key']);
    }

    /**
     * Test Gateway with PayPal credentials (client_id + client_secret)
     */
    public function testPayPalGatewayHasCorrectCredentials()
    {
        $id = EntityId::generate();
        $credentials = [
            'client_id' => 'AVxxx',
            'client_secret' => 'EGxxx',
            'webhook_id' => 'webhook_id_123'
        ];

        $gateway = new Gateway($id, 'paypal', $credentials);

        $creds = $gateway->credentials();
        $this->assertArrayHasKey('client_id', $creds);
        $this->assertArrayHasKey('client_secret', $creds);
        $this->assertEquals('AVxxx', $creds['client_id']);
        $this->assertEquals('EGxxx', $creds['client_secret']);
    }

    /**
     * Test Gateway default isActive is true
     */
    public function testDefaultIsActiveBehavior()
    {
        $id = EntityId::generate();
        $credentials = [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123'
        ];

        // Not providing isActive parameter, should default to true
        $gateway = new Gateway($id, 'stripe', $credentials);

        $this->assertTrue($gateway->isActive());
    }

    /**
     * Test Gateway id is accessible via parent AggregateRoot
     */
    public function testGatewayIdAccessible()
    {
        $id = EntityId::generate();
        $credentials = [
            'publishable_key' => 'pk_test_123',
            'secret_key' => 'sk_test_123'
        ];

        $gateway = new Gateway($id, 'stripe', $credentials);

        // Should be able to access id() method from AggregateRoot
        $this->assertEquals($id->uuid(), $gateway->id()->uuid());
    }

    /**
     * Test Gateway with empty credentials array
     */
    public function testGatewayWithEmptyCredentialsArray()
    {
        $id = EntityId::generate();
        $credentials = [];

        $gateway = new Gateway($id, 'stripe', $credentials);

        $this->assertIsArray($gateway->credentials());
        $this->assertEmpty($gateway->credentials());
    }
}
