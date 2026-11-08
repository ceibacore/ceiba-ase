<?php

namespace LemurAse\WebhookManagement\Infrastructure\Adapters;

use LemurAse\WebhookManagement\Domain\WebhookEvent;
use LemurAse\Domain\ValueObjects\MinorUnits;

final class StripeWebhookAdapter implements WebhookAdapterInterface
{
    public function parse(string $payload, array $headers, array $config): WebhookEvent
    {
        // Hosts pass headers either as strings or as arrays of values (Symfony/Laravel).
        $sigHeader = null;
        foreach (['stripe-signature', 'Stripe-Signature', 'STRIPE_SIGNATURE'] as $name) {
            $value = $headers[$name] ?? null;
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }
            if (is_string($value) && $value !== '') {
                $sigHeader = $value;
                break;
            }
        }
        
        if (!$sigHeader) {
            throw new \Exception("Missing stripe-signature header");
        }
        
        if (empty($config['webhook_secret'])) {
            throw new \Exception("Missing Stripe webhook_secret in configuration");
        }

        try {
            $stripeEvent = \Stripe\Webhook::constructEvent($payload, $sigHeader, $config['webhook_secret']);
        } catch (\UnexpectedValueException $e) {
            throw new \Exception("Invalid payload");
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new \Exception("Invalid signature");
        }
        
        $event = $stripeEvent->toArray();

        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? [];

        $action = $this->mapTypeToAction($type, $data);
        $normalizedData = $this->normalizeData($action, $data);

        return new WebhookEvent(
            action: $action,
            data: $normalizedData,
            externalId: $event['id'] ?? 'evt_unknown',
            provider: 'stripe',
            rawPayload: $event
        );
    }

    private function mapTypeToAction(string $type, array $data): string
    {
        return match ($type) {
            // A completed session may still be unpaid (asynchronous methods such as bank
            // debits): only paid or no-payment-required (trial) sessions activate now.
            'checkout.session.completed' => in_array($data['payment_status'] ?? '', ['paid', 'no_payment_required'], true)
                ? 'INITIAL_PAYMENT' : 'UNKNOWN',
            'checkout.session.async_payment_succeeded' => 'INITIAL_PAYMENT',
            'checkout.session.async_payment_failed' => 'PAYMENT_FAILED',
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
            // Checkout sessions report amount_total, invoices amount_paid, charges amount;
            // all in the currency's minor unit.
            'amount'                   => MinorUnits::fromMinor($data['amount_total'] ?? $data['amount_paid'] ?? $data['amount'] ?? 0, (string) ($data['currency'] ?? 'usd')),
            'currency'                 => strtoupper((string) ($data['currency'] ?? 'usd')),
            'raw_payload'              => $data
        ];

        // Specific mappings for INITIAL_PAYMENT (from checkout session metadata)
        if ($action === 'INITIAL_PAYMENT') {
            // Async payment events carry the session too, so metadata is still available.
            $normalized['internal_order_id'] = $data['metadata']['order_id'] ?? null;
            $normalized['external_client_id'] = $data['client_reference_id'] ?? null;
            // Refunds are issued against the payment intent, not the checkout session.
            $normalized['refund_reference'] = $data['payment_intent'] ?? null;
        }

        // charge.refunded: the charge carries the payment intent metadata (order_id).
        if ($action === 'REFUND_PROCESSED') {
            $normalized['internal_order_id'] = $data['metadata']['order_id'] ?? null;
            $normalized['refund_reference'] = $data['payment_intent'] ?? null;
            $normalized['full_refund'] = (bool) ($data['refunded'] ?? false);
            $normalized['refunded_total'] = MinorUnits::fromMinor($data['amount_refunded'] ?? 0, (string) ($data['currency'] ?? 'usd'));
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