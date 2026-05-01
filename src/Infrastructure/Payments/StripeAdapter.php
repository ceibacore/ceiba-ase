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
        $type = $payload['type'] ?? 'unknown';
        $data = $payload['data']['object'] ?? [];

        $action = match ($type) {
            'checkout.session.completed' => 'INITIAL_PAYMENT',
            'invoice.paid' => 'RENEWAL_PAYMENT',
            'invoice.payment_failed' => 'PAYMENT_FAILED',
            'customer.subscription.deleted', 'customer.subscription.canceled' => 'SUBSCRIPTION_CANCELED',
            'customer.subscription.updated' => 'SUBSCRIPTION_UPDATED',
            default => 'IGNORED'
        };

        return [
            'action' => $action,
            'external_order_id' => $data['id'] ?? null,
            'external_subscription_id' => $data['subscription'] ?? $data['id'] ?? null,
            'external_transaction_id' => $payload['id'] ?? null,
            'internal_order_id' => $data['metadata']['order_id'] ?? $data['client_reference_id'] ?? null,
            'amount' => ($data['amount_total'] ?? $data['amount_paid'] ?? 0) / 100,
            'currency' => strtoupper($data['currency'] ?? 'USD'),
            'status' => 'paid',
            'external_client_id' => $data['client_reference_id'] ?? $data['customer'] ?? null,
            'raw_payload' => $payload
        ];
    }
}
