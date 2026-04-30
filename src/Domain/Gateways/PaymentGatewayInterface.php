<?php

namespace LemurAse\Domain\Gateways;

use LemurAse\Domain\Entities\Order;

interface PaymentGatewayInterface
{
    /**
     * Create a checkout session/link for the order.
     * Returns an array with ['checkout_url' => string, 'external_id' => string]
     */
    public function createCheckoutSession(Order $order): array;

    /**
     * Validate if the webhook request is authentic.
     */
    public function validateWebhook(array $payload, array $headers): bool;

    /**
     * Process the webhook payload and return normalized transaction data.
     */
    public function parseWebhookEvent(array $payload): array;
}
