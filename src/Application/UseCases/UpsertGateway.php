<?php

declare(strict_types=1);

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\Gateway;
use LemurAse\Domain\Repositories\GatewayRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\FormManagement\Infrastructure\GatewayFormRegistry;

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
        ?string $accountRef = null,
    ): Gateway {
        $accountRef = \LemurAse\Domain\ValueObjects\AccountRef::normalize($accountRef);
        if ($accountRef !== null && $provider === 'mock') {
            throw new \InvalidArgumentException('The mock gateway is reserved for the platform.');
        }
        if (!GatewayFormRegistry::has($provider)) {
            throw new \InvalidArgumentException(
                "Unsupported provider '{$provider}'. " .
                "Registered: " . implode(', ', GatewayFormRegistry::providers())
            );
        }

        // Find any existing gateway (active or inactive) for this provider
        $existing = $this->gatewayRepo->findByProviderForAccount($provider, $accountRef, false);

        if ($existing !== null) {
            // Rebuild entity with same ID but new credentials / active state
            $gateway = new Gateway(
                $existing->id(),
                $provider,
                $credentials,
                $isActive,
                $accountRef,
            );
        } else {
            $gateway = new Gateway(
                EntityId::generate(),
                $provider,
                $credentials,
                $isActive,
                $accountRef,
            );
        }

        $this->gatewayRepo->save($gateway);

        return $gateway;
    }
}
