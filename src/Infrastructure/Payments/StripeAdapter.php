<?php

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Order;

final class StripeAdapter implements PaymentGatewayInterface
{
    public function createCheckoutSession(Order $order): array
    {
        // In real world: Stripe::checkoutSessions->create(...)
        return [
            'checkout_url' => "https://checkout.stripe.com/pay/" . bin2hex(random_bytes(16)),
            'external_id' => "cs_test_" . bin2hex(random_bytes(16))
        ];
    }

    public function validateWebhook(array $payload, array $headers): bool
    {
        // In real world: Stripe::Webhook->constructEvent(...)
        return true; 
    }

    public function parseWebhookEvent(array $payload): array
    {
        // Normalize Stripe event to ASE internal format
        return [
            'external_order_id' => $payload['data']['object']['id'] ?? null,
            'external_transaction_id' => $payload['id'] ?? null,
            'amount' => ($payload['data']['object']['amount_total'] ?? 0) / 100,
            'currency' => strtoupper($payload['data']['object']['currency'] ?? 'USD'),
            'status' => 'paid',
            'external_client_id' => $payload['data']['object']['client_reference_id'] ?? null,
            'raw_payload' => $payload
        ];
    }
}
