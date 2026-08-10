<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\Gateways\PaymentGatewayInterface;

final class MockAdapter implements PaymentGatewayInterface
{
    public function __construct(
        private readonly bool $autoApprove = true
    ) {}

    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        $mockId = 'mock_sess_' . bin2hex(random_bytes(8));
        $redirectUrl = $this->autoApprove ? $successUrl : $cancelUrl;

        return [
            'checkout_url' => $redirectUrl,
            'external_id'  => $mockId,
        ];
    }

    public function validateWebhook(array $payload, array $headers): bool
    {
        return true;
    }

    public function parseWebhookEvent(array $payload): array
    {
        $type = $payload['type'] ?? $payload['event'] ?? 'checkout.completed';

        $action = match ($type) {
            'checkout.completed', 'INITIAL_PAYMENT' => 'INITIAL_PAYMENT',
            'charge.refunded', 'REFUND_PROCESSED'   => 'REFUND_PROCESSED',
            'subscription.canceled', 'SUBSCRIPTION_CANCELED' => 'SUBSCRIPTION_CANCELED',
            default => 'INITIAL_PAYMENT',
        };

        return [
            'action'                  => $action,
            'external_order_id'       => $payload['external_order_id'] ?? 'mock_order_' . rand(1000, 9999),
            'external_subscription_id'=> $payload['external_subscription_id'] ?? 'mock_sub_' . rand(1000, 9999),
            'external_transaction_id' => $payload['gateway_reference'] ?? $payload['external_transaction_id'] ?? 'mock_tx_' . rand(1000, 9999),
            'internal_order_id'       => $payload['order_id'] ?? $payload['internal_order_id'] ?? null,
            'amount'                  => (float) ($payload['amount'] ?? 99.00),
            'currency'                => strtoupper($payload['currency'] ?? 'USD'),
            'status'                  => 'paid',
            'external_client_id'      => $payload['client_id'] ?? 'mock_client',
            'trial_end'               => null,
            'full_refund'             => ($action === 'REFUND_PROCESSED'),
            'raw_payload'             => $payload,
        ];
    }

    public function refund(string $externalTransactionId, float $amount, string $reason): array
    {
        return [
            'status'             => 'success',
            'external_refund_id' => 'mock_refund_' . bin2hex(random_bytes(4)),
        ];
    }

    public function verifyTransaction(string $externalId): array
    {
        return [
            'action'                  => 'INITIAL_PAYMENT',
            'external_id'             => $externalId,
            'external_subscription_id'=> 'mock_sub_' . $externalId,
            'external_transaction_id' => 'mock_tx_' . $externalId,
            'amount'                  => 99.00,
            'currency'                => 'USD',
            'external_client_id'      => 'mock_client',
            'internal_order_id'       => null,
            'raw_payload'             => ['mock' => true],
        ];
    }

    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): array
    {
        return ['status' => 'success'];
    }

    public function pauseSubscription(string $externalId): array
    {
        return ['status' => 'success'];
    }
}
