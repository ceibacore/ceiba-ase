<?php

namespace LemurAse\Http\Controllers;

use LemurAse\AseManager;
use Throwable;

class WebhookController
{
    /**
     * Handle incoming webhooks agnostically.
     * Expects a raw JSON payload body and the HTTP headers.
     *
     * @param string $gatewayProvider (e.g., 'stripe', 'paypal')
     * @param string $rawBody The raw php://input string
     * @param array $headers The HTTP headers array
     * @return array [http_status_code, response_body_string]
     */
    public function handle(string $gatewayProvider, string $rawBody, array $headers): array
    {
        try {
            $payload = json_decode($rawBody, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [400, json_encode(['error' => 'Invalid JSON payload'])];
            }

            // AseManager will throw an exception if the signature is invalid
            $success = AseManager::handleWebhook($gatewayProvider, $payload, $headers);

            if ($success) {
                return [200, json_encode(['status' => 'success'])];
            }

            return [400, json_encode(['error' => 'Failed to process webhook'])];

        } catch (\RuntimeException $e) {
            // Signature verification failed
            return [401, json_encode(['error' => $e->getMessage()])];
        } catch (Throwable $e) {
            // Internal server error or business logic failure
            // In a real env, log this error
            return [500, json_encode(['error' => 'Internal Server Error'])];
        }
    }
}
