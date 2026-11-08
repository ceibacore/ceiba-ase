<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments\Paystack;

use LemurAse\Infrastructure\Http\HttpClientFactory;

/** Paystack REST API (https://api.paystack.co), authenticated with the secret key. */
final class PaystackClient
{
    public const BASE_URL = 'https://api.paystack.co';

    public function __construct(private readonly string $secretKey)
    {
        if (trim($secretKey) === '') {
            throw new \RuntimeException("Paystack credential 'secret_key' missing.");
        }
    }

    /** POST /transaction/initialize. @return array the `data` object */
    public function initialize(array $body): array
    {
        return $this->request('POST', '/transaction/initialize', $body);
    }

    /** GET /transaction/verify/{reference}. @return array the transaction */
    public function verify(string $reference): array
    {
        if (!PaystackSignature::isSafeReference($reference)) {
            throw new \InvalidArgumentException('Paystack: invalid transaction reference');
        }
        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    /** POST /refund. @return array the refund */
    public function refund(array $body): array
    {
        return $this->request('POST', '/refund', $body);
    }

    private function request(string $method, string $path, ?array $json = null): array
    {
        $options = ['headers' => [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept'        => 'application/json',
        ]];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $response = HttpClientFactory::create(['base_uri' => self::BASE_URL, 'http_errors' => false])
            ->request($method, $path, $options);
        $body = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 300 || !is_array($body) || ($body['status'] ?? false) !== true) {
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
            throw new \RuntimeException(sprintf('Paystack %s %s failed (HTTP %d)%s', $method, strtok($path, '?'),
                $response->getStatusCode(), $message !== '' ? ": {$message}" : ''));
        }
        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }
}
