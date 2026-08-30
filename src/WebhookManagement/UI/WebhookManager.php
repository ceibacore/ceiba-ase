<?php

namespace LemurAse\WebhookManagement\UI;

use LemurAse\AseManager;
use LemurAse\WebhookManagement\Application\ProcessWebhookUseCase;
use LemurAse\WebhookManagement\Infrastructure\Adapters\StripeWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\PayPalWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\MercadoPagoWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\AlipayWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\WebhookAdapterInterface;

final class WebhookManager
{
    private static array $adapters = [
        'stripe'      => StripeWebhookAdapter::class,
        'paypal'      => PayPalWebhookAdapter::class,
        'mercadopago' => MercadoPagoWebhookAdapter::class,
        'alipay'      => AlipayWebhookAdapter::class,
    ];

    /**
     * Entry point to process any incoming webhook.
     * 
     * @param string $payload Raw body from request
     * @param array  $headers Headers from request
     * @param string $provider 'stripe', 'paypal', 'mercadopago', 'alipay', etc.
     */
    public static function handle(string $payload, array $headers, string $provider): bool
    {
        $providerKey = strtolower(trim($provider));
        $adapterClass = self::$adapters[$providerKey] ?? null;
        if (!$adapterClass) {
            throw new \InvalidArgumentException("No webhook adapter registered for provider: {$provider}");
        }

        /** @var WebhookAdapterInterface $adapter */
        $adapter = new $adapterClass();

        // 1. Get gateway config for signature verification
        $config = AseManager::getGatewayCredentialsByProvider($providerKey);

        // 2. Parse and normalize
        $event = $adapter->parse($payload, $headers, $config);

        // 3. Inject gateway_id if available in config
        if (isset($config['gateway_id'])) {
            $event->data['gateway_id'] = $config['gateway_id'];
        }

        // 4. Execute core logic
        $useCase = self::createUseCase();
        return $useCase->execute($event);
    }

    private static function createUseCase(): ProcessWebhookUseCase
    {
        $ase = AseManager::getInstance();
        return new ProcessWebhookUseCase(
            $ase->orderRepo,
            $ase->subscriptionRepo,
            $ase->invoiceRepo,
            $ase->logRepo,
            $ase->planPriceRepo,
            $ase->planRepo,
            $ase->gatewayRepo
        );
    }

    public static function registerAdapter(string $provider, string $adapterClass): void
    {
        self::$adapters[strtolower(trim($provider))] = $adapterClass;
    }
}