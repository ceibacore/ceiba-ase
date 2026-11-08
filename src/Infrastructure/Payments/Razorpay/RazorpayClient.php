<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\Razorpay;

use LemurAse\Infrastructure\Http\HttpClientFactory;

/** Razorpay REST API (https://api.razorpay.com/v1), HTTP basic auth with key id and secret. */
final class RazorpayClient
{
    public const BASE_URL = 'https://api.razorpay.com';

    public function __construct(private readonly string $keyId, private readonly string $keySecret)
    {
        if (trim($keyId) === '' || trim($keySecret) === '') {
            throw new \RuntimeException("Razorpay credentials 'key_id' and 'key_secret' are required.");
        }
    }

    public function createPaymentLink(array $body): array
    {
        return $this->request('POST', '/v1/payment_links', $body);
    }

    /** GET /v1/payments (credential check). */
    public function listPayments(int $count = 1): array
    {
        return $this->request('GET', '/v1/payments?count=' . max(1, min(100, $count)));
    }

    public function paymentLink(string $id): array
    {
        return $this->request('GET', '/v1/payment_links/' . self::safe($id));
    }

    public function refundPayment(string $paymentId, array $body): array
    {
        return $this->request('POST', '/v1/payments/' . self::safe($paymentId) . '/refund', $body);
    }

    private static function safe(string $id): string
    {
        if (!RazorpaySignature::isSafeId($id)) {
            throw new \InvalidArgumentException('Razorpay: invalid id');
        }
        return $id;
    }

    private function request(string $method, string $path, ?array $json = null): array
    {
        $options = ['auth' => [$this->keyId, $this->keySecret], 'headers' => ['Accept' => 'application/json']];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $response = HttpClientFactory::create(['base_uri' => self::BASE_URL, 'http_errors' => false])
            ->request($method, $path, $options);
        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 300 || !is_array($body)) {
            $message = is_array($body) ? (string) ($body['error']['description'] ?? '') : '';
            throw new \RuntimeException(sprintf('Razorpay %s %s failed (HTTP %d)%s', $method, $path,
                $response->getStatusCode(), $message !== '' ? ": {$message}" : ''));
        }
        return $body;
    }
}
