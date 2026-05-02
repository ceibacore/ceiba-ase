<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\Gateway;
use LemurAse\Domain\Repositories\GatewayRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;

final class CreateGateway
{
    private const SUPPORTED_PROVIDERS = ['stripe', 'paypal'];

    public function __construct(
        private readonly GatewayRepositoryInterface $gatewayRepo
    ) {}

    /**
     * @param  string  $provider     'stripe' | 'paypal'
     * @param  array   $credentials  Provider-specific credentials (see Gap #2 JSON schema)
     * @param  bool    $isActive     Whether the gateway is immediately usable
     * @return Gateway               The newly persisted gateway
     * @throws \InvalidArgumentException On unknown provider
     */
    public function execute(
        string $provider,
        array  $credentials,
        bool   $isActive = true
    ): Gateway {
        if (!in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            throw new \InvalidArgumentException(
                "Unsupported provider '{$provider}'. Supported: " . implode(', ', self::SUPPORTED_PROVIDERS)
            );
        }

        $gateway = new Gateway(
            EntityId::generate(),
            $provider,
            $credentials,
            $isActive
        );

        $this->gatewayRepo->save($gateway);
        return $gateway;
    }
}
