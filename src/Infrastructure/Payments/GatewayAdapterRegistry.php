<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Gateways\PaymentGatewayInterface;

/**
 * Registry of payment gateway adapters.
 *
 * This enables the Open-Closed Principle (OCP) by allowing the host application
 * to register custom adapters for existing or new gateways without modifying
 * the core AseManager or adapter builder methods.
 */
final class GatewayAdapterRegistry
{
    /** @var array<string, \Closure> */
    private static array $factories = [];
    private static bool $booted = false;

    private function __construct() {}

    /**
     * Boot the registry with default providers.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        // Default Stripe adapter
        if (!isset(self::$factories['stripe'])) {
            self::register('stripe', function (array $credentials): PaymentGatewayInterface {
                $publishableKey = $credentials['publishable_key'] ?? throw new \RuntimeException("Stripe credential 'publishable_key' missing.");
                $secretKey     = $credentials['secret_key']     ?? throw new \RuntimeException("Stripe credential 'secret_key' missing.");
                $webhookSecret = $credentials['webhook_secret'] ?? throw new \RuntimeException("Stripe credential 'webhook_secret' missing.");
                $testMode      = (bool) ($credentials['test_mode'] ?? false);

                return new StripeAdapter($publishableKey, $secretKey, $webhookSecret, $testMode);
            });
        }

        // Default PayPal adapter
        if (!isset(self::$factories['paypal'])) {
            self::register('paypal', function (array $credentials): PaymentGatewayInterface {
                $clientId     = $credentials['client_id']     ?? throw new \RuntimeException("PayPal credential 'client_id' missing.");
                $clientSecret = $credentials['client_secret'] ?? throw new \RuntimeException("PayPal credential 'client_secret' missing.");
                $webhookId    = $credentials['webhook_id']    ?? throw new \RuntimeException("PayPal credential 'webhook_id' missing.");
                $sandbox      = (bool) ($credentials['sandbox'] ?? false);

                return new PayPalAdapter($clientId, $clientSecret, $webhookId, $sandbox);
            });
        }

        // Default Mock adapter
        if (!isset(self::$factories['mock'])) {
            self::register('mock', function (array $credentials = []): PaymentGatewayInterface {
                $autoApprove = (bool) ($credentials['auto_approve'] ?? true);
                return new MockAdapter($autoApprove);
            });
        }


        // Default Mercado Pago adapter
        if (!isset(self::$factories['mercadopago'])) {
            self::register('mercadopago', function (array $credentials): PaymentGatewayInterface {
                $accessToken   = $credentials['access_token']   ?? throw new \RuntimeException("MercadoPago credential 'access_token' missing.");
                $publicKey     = $credentials['public_key']     ?? throw new \RuntimeException("MercadoPago credential 'public_key' missing.");
                $webhookSecret = $credentials['webhook_secret'] ?? throw new \RuntimeException("MercadoPago credential 'webhook_secret' missing.");
                $sandbox       = (bool) ($credentials['sandbox'] ?? false);

                return new MercadoPagoAdapter($accessToken, $publicKey, $webhookSecret, $sandbox);
            });
        }


        // Default Alipay Global adapter
        if (!isset(self::$factories['alipay'])) {
            self::register('alipay', function (array $credentials): PaymentGatewayInterface {
                $clientId       = $credentials['client_id']         ?? throw new \RuntimeException("Alipay credential 'client_id' missing.");
                $privateKey     = $credentials['private_key']       ?? throw new \RuntimeException("Alipay credential 'private_key' missing.");
                $alipayPubKey   = $credentials['alipay_public_key'] ?? throw new \RuntimeException("Alipay credential 'alipay_public_key' missing.");
                $merchantId     = $credentials['merchant_id']       ?? throw new \RuntimeException("Alipay credential 'merchant_id' missing.");
                $sandbox        = (bool) ($credentials['sandbox'] ?? false);

                return new AlipayAdapter($clientId, $privateKey, $alipayPubKey, $merchantId, $sandbox);
            });
        }
        self::$booted = true;
    }

    /**
     * Register a factory closure for a specific provider.
     * The closure must accept an array of credentials and return a PaymentGatewayInterface.
     */
    public static function register(string $provider, \Closure $factory): void
    {
        self::$factories[$provider] = $factory;
    }

    /**
     * Check if a factory is registered for the provider.
     */
    public static function has(string $provider): bool
    {
        self::boot();
        return isset(self::$factories[$provider]);
    }

    /**
     * Instantiate the adapter using the registered factory.
     */
    public static function make(string $provider, array $credentials): PaymentGatewayInterface
    {
        self::boot();

        if (!isset(self::$factories[$provider])) {
            throw new \RuntimeException("No adapter factory registered for provider: {$provider}");
        }

        $adapter = (self::$factories[$provider])($credentials);

        if (!$adapter instanceof PaymentGatewayInterface) {
            throw new \RuntimeException("Factory for '{$provider}' must return an instance of PaymentGatewayInterface.");
        }

        return $adapter;
    }

    /**
     * Reset the registry (useful for tests).
     */
    public static function reset(): void
    {
        self::$factories = [];
        self::$booted = false;
    }
}
