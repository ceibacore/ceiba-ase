<?php

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Order;

final class PayPalAdapter implements PaymentGatewayInterface
{
    public function createCheckoutSession(Order $order): array
    {
        return [
            'checkout_url' => "https://www.paypal.com/checkoutnow?token=" . bin2hex(random_bytes(10)),
            'external_id' => "PAYID-" . bin2hex(random_bytes(10))
        ];
    }

    public function validateWebhook(array $payload, array $headers): bool
    {
        return true; 
    }

    public function parseWebhookEvent(array $payload): array
    {
        return [
            'external_order_id' => $payload['resource']['id'] ?? null,
            'external_transaction_id' => $payload['id'] ?? null,
            'amount' => (float)($payload['resource']['amount']['total'] ?? 0),
            'currency' => strtoupper($payload['resource']['amount']['currency'] ?? 'USD'),
            'status' => 'paid',
            'external_client_id' => $payload['resource']['custom_id'] ?? null,
            'raw_payload' => $payload
        ];
    }
}
