<?php

namespace LemurAse\Domain\Gateways;

use LemurAse\Domain\Entities\Order;

interface PaymentGatewayInterface
{
    /**
     * Create a checkout session/link for the order.
     * Returns an array with ['checkout_url' => string, 'external_id' => string]
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array;

    /**
     * Validate if the webhook request is authentic.
     */
    public function validateWebhook(array $payload, array $headers): bool;

    /**
     * Process the webhook payload and return normalized transaction data.
     */
    public function parseWebhookEvent(array $payload): array;

    /**
     * Issue a refund against a transaction.
     *
     * @param string $externalTransactionId Charge/transaction ID from payment provider
     * @param float  $amount                Amount to refund in the original currency
     * @param string $reason                Human-readable reason for refund
     *
     * @return array [
     *                  'status' => 'success'|'failed',
     *                  'external_refund_id' => string|null,
     *                  'error' => string|null
     *               ]
     */
    public function refund(string $externalTransactionId, float $amount, string $reason): array;
}
