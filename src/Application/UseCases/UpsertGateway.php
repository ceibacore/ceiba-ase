<?php

declare(strict_types=1);

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\Gateway;
use LemurAse\Domain\Repositories\GatewayRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Infrastructure\Form\GatewayFormRegistry;

/**
 * Create or update a gateway by provider slug.
 *
 * Unlike CreateGateway (which always inserts), UpsertGateway:
 *   - Finds an existing gateway for the provider (any state)
 *   - Updates it in-place if found
 *   - Creates a new one if not found
 *
 * Provider validation is delegated to GatewayFormRegistry, which is the
 * single source of truth for supported providers.
 */
final class UpsertGateway
{
    public function __construct(
        private readonly GatewayRepositoryInterface $gatewayRepo,
    ) {}

    /**
     * @param  string  $provider     Provider slug, must be registered in GatewayFormRegistry
     * @param  array   $credentials  Provider-specific credential map
     * @param  bool    $isActive     Whether to activate the gateway
     * @return Gateway               The persisted gateway entity
     *
     * @throws \InvalidArgumentException On unknown provider
     */
    public function execute(
        string $provider,
        array  $credentials,
        bool   $isActive = true,
    ): Gateway {
        if (!GatewayFormRegistry::has($provider)) {
            throw new \InvalidArgumentException(
                "Unsupported provider '{$provider}'. " .
                "Registered: " . implode(', ', GatewayFormRegistry::providers())
            );
        }

        // Find any existing gateway (active or inactive) for this provider
        $existing = $this->findByProviderAny($provider);

        if ($existing !== null) {
            // Rebuild entity with same ID but new credentials / active state
            $gateway = new Gateway(
                $existing->id(),
                $provider,
                $credentials,
                $isActive,
            );
        } else {
            $gateway = new Gateway(
                EntityId::generate(),
                $provider,
                $credentials,
                $isActive,
            );
        }

        $this->gatewayRepo->save($gateway);

        return $gateway;
    }

    /**
     * Find a gateway by provider regardless of active state.
     * GatewayRepositoryInterface::findByProvider() only returns active gateways,
     * so we use findAll() to cover inactive ones.
     */
    private function findByProviderAny(string $provider): ?Gateway
    {
        foreach ($this->gatewayRepo->findAll() as $gateway) {
            if ($gateway->provider() === $provider) {
                return $gateway;
            }
        }
        return null;
    }
}
