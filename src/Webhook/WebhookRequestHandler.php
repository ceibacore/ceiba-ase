<?php

namespace LemurAse\Webhook;

use LemurAse\AseManager;
use Throwable;

class WebhookRequestHandler
{
    /**
     * Handle incoming webhooks agnostically.
     * Expects a raw JSON payload body and the HTTP headers.
     *
     * SECURITY CRITICAL: The raw body is passed directly to the adapter
     * for signature verification, before any JSON decoding occurs.
     *
     * @param string $gatewayId UUID of the gateway from prefix_gateways.id
     * @param string $rawBody   The raw php://input string (for signature verification)
     * @param array  $headers   The HTTP headers array
     * @return array [http_status_code, response_body_string]
     */
    public function handle(string $gatewayId, string $rawBody, array $headers): array
    {
        try {
            // Pass raw body directly to AseManager for secure signature verification
            // DO NOT JSON-decode here; the adapter needs the raw bytes for HMAC
            $success = AseManager::handleWebhook($gatewayId, $rawBody, $headers);

            if ($success) {
                return [200, json_encode(['status' => 'success'])];
            }

            return [400, json_encode(['error' => 'Failed to process webhook'])];

        } catch (\RuntimeException $e) {
            // Signature verification failed (SECURITY event)
            error_log("Webhook signature verification failed: " . $e->getMessage());
            return [401, json_encode(['error' => $e->getMessage()])];
        } catch (Throwable $e) {
            // Internal server error or business logic failure
            error_log("Webhook processing error: " . $e->getMessage());
            return [500, json_encode(['error' => 'Internal Server Error'])];
        }
    }
}
