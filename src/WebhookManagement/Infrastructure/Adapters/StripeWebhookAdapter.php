<?php

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\WebhookManagement\Domain\WebhookEvent;

final class StripeWebhookAdapter implements WebhookAdapterInterface
{
    public function parse(string $payload, array $headers, array $config): WebhookEvent
    {
        $event = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON payload");
        }

        // In a real production app, we would use Stripe\Webhook::constructEvent
        // with the signature header and $config['webhook_secret'].
        // For this implementation, we focus on the mapping logic.

        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? [];

        $action = $this->mapTypeToAction($type);
        $normalizedData = $this->normalizeData($action, $data);

        return new WebhookEvent(
            action: $action,
            data: $normalizedData,
            externalId: $event['id'] ?? 'evt_unknown',
            provider: 'stripe',
            rawPayload: $event
        );
    }

    private function mapTypeToAction(string $type): string
    {
        return match ($type) {
            'checkout.session.completed' => 'INITIAL_PAYMENT',
            'invoice.payment_succeeded'  => 'RENEWAL_PAYMENT',
            'invoice.payment_failed'     => 'PAYMENT_FAILED',
            'customer.subscription.deleted' => 'SUBSCRIPTION_CANCELED',
            'customer.subscription.updated' => 'SUBSCRIPTION_UPDATED',
            'charge.refunded'            => 'REFUND_PROCESSED',
            default => 'UNKNOWN'
        };
    }

    private function normalizeData(string $action, array $data): array
    {
        // Extract common fields needed by ProcessWebhookUseCase
        $normalized = [
            'external_subscription_id' => $data['subscription'] ?? ($data['id'] ?? null),
            'external_transaction_id'  => $data['id'] ?? null,
            'amount'                   => ($data['amount_paid'] ?? ($data['amount'] ?? 0)) / 100,
            'currency'                 => $data['currency'] ?? 'usd',
            'raw_payload'              => $data
        ];

        // Specific mappings for INITIAL_PAYMENT (from checkout session metadata)
        if ($action === 'INITIAL_PAYMENT') {
            $normalized['internal_order_id'] = $data['metadata']['order_id'] ?? null;
            $normalized['external_client_id'] = $data['client_reference_id'] ?? null;
        }

        // Specific mappings for SUBSCRIPTION_UPDATED
        if ($action === 'SUBSCRIPTION_UPDATED') {
            $normalized['new_status'] = $data['status'] ?? null;
            $normalized['current_period_start'] = $data['current_period_start'] ?? null;
            $normalized['current_period_end'] = $data['current_period_end'] ?? null;
        }

        return $normalized;
    }
}
