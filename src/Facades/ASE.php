<?php

namespace LemurAse\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string createCheckoutSession(string $clientId, string $planPriceId, string $gatewayId)
 * @method static int expireStaleOrders()
 * @method static bool handleWebhook(string $gatewayProvider, array $payload, array $headers)
 * @method static bool processValidatedEvent(string $action, array $normalizedData)
 * @method static array|null getClientActiveSubscription(string $clientId)
 * @method static array getClientInvoices(string $clientId, int $limit = 10)
 * @method static bool hasActiveAccess(string $clientId, string $planSlug)
 * @method static void assignCustomPrice(string $clientId, string $planPriceId, float $amount, ?string $validUntil)
 * @method static void cancelSubscription(string $clientId, string $subscriptionId, bool $atPeriodEnd = true)
 * 
 * @see \LemurAse\AseManager
 */
class ASE extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ase';
    }
}
