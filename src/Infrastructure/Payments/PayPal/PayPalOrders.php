<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\PayPal;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use LemurAse\Infrastructure\Http\HttpClientFactory;

/**
 * PayPal Orders v2 operations shared by the payment and webhook adapters.
 *
 * Orders are created with intent CAPTURE: buyer approval does not move money.
 * capture() completes the payment; it is idempotent (PayPal-Request-Id) and
 * reads the existing capture when the order was already captured.
 */
final class PayPalOrders
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $sandbox,
    ) {}

    public function client(): Client
    {
        return HttpClientFactory::create([
            'base_uri' => $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com',
        ]);
    }

    public function accessToken(): string
    {
        $response = $this->client()->post('/v1/oauth2/token', [
            'auth'        => [$this->clientId, $this->clientSecret],
            'form_params' => ['grant_type' => 'client_credentials'],
            'headers'     => ['Accept' => 'application/json'],
        ]);
        $token = json_decode((string) $response->getBody(), true)['access_token'] ?? '';
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('PayPal: empty access token');
        }
        return $token;
    }

    /**
     * Captures an approved order. Returns the normalized capture when the payment
     * is COMPLETED, or null when PayPal did not complete it.
     *
     * @return array{order_id: string, capture_id: ?string, amount: float, currency: string, custom_id: ?string}|null
     */
    public function capture(string $orderId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D', $orderId)) {
            throw new \InvalidArgumentException('PayPal: invalid order id');
        }
        $token = $this->accessToken();
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json',
            'PayPal-Request-Id' => 'capture-' . $orderId, 'Prefer' => 'return=representation'];
        try {
            $response = $this->client()->post("/v2/checkout/orders/{$orderId}/capture", ['headers' => $headers]);
            $order = json_decode((string) $response->getBody(), true);
        } catch (ClientException $e) {
            // Already captured (for example by a previous webhook): read the order instead.
            if (!str_contains((string) $e->getResponse()->getBody(), 'ORDER_ALREADY_CAPTURED')) {
                throw $e;
            }
            $response = $this->client()->get("/v2/checkout/orders/{$orderId}", ['headers' => $headers]);
            $order = json_decode((string) $response->getBody(), true);
        }
        return self::completedCapture(is_array($order) ? $order : []);
    }

    /** Normalizes a COMPLETED order (or returns null). */
    public static function completedCapture(array $order): ?array
    {
        $unit = $order['purchase_units'][0] ?? [];
        $capture = $unit['payments']['captures'][0] ?? null;
        if (($order['status'] ?? '') !== 'COMPLETED' || !is_array($capture) || ($capture['status'] ?? '') !== 'COMPLETED') {
            return null;
        }
        return [
            'order_id'   => (string) ($order['id'] ?? ''),
            'capture_id' => isset($capture['id']) ? (string) $capture['id'] : null,
            'amount'     => (float) ($capture['amount']['value'] ?? 0),
            'currency'   => strtoupper((string) ($capture['amount']['currency_code'] ?? '')),
            'custom_id'  => $capture['custom_id'] ?? $unit['custom_id'] ?? null,
        ];
    }

    /** Currency of an existing capture (needed to format refunds). */
    public function captureCurrency(string $captureId, string $token): string
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D', $captureId)) {
            throw new \InvalidArgumentException('PayPal: invalid capture id');
        }
        $response = $this->client()->get("/v2/payments/captures/{$captureId}", [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        $currency = json_decode((string) $response->getBody(), true)['amount']['currency_code'] ?? '';
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException('PayPal: capture currency unavailable');
        }
        return $currency;
    }
}
