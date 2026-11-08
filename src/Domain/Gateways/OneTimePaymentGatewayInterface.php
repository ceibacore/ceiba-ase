<?php

declare(strict_types=1);

namespace LemurAse\Domain\Gateways;

/** Gateways that can charge an arbitrary one-time amount (ceiba-ase payments). */
interface OneTimePaymentGatewayInterface
{
    /**
     * @return array{checkout_url: string, external_id: string}
     */
    public function createPaymentSession(Chargeable $payment, string $successUrl, string $cancelUrl): array;
}
