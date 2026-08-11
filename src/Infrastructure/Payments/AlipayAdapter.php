<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\Entities\Order;

/**
 * Alipay Global (Antom) payment gateway adapter.
 *
 * Uses the Antom Online Payment API (Cashier Payment / redirect-based).
 * Authentication: RSA256 (SHA256withRSA) asymmetric digital signatures.
 * Requests are signed with the merchant private key.
 * Webhooks are verified with the Alipay public key.
 *
 * Supported regions: China, Hong Kong, and international Alipay+ partners.
 */
final class AlipayAdapter implements PaymentGatewayInterface
{
    private const BASE_URL = 'https://open-sea-global.alipay.com';

    public function __construct(
        private readonly string $clientId,
        private readonly string $privateKey,
        private readonly string $alipayPublicKey,
        private readonly string $merchantId,
        private readonly bool $sandbox = false
    ) {}

    /**
     * Helper to sign request payload using RSA-SHA256 with the merchant private key.
     */
    private function signRequest(string $method, string $path, string $body, string $requestTime): string
    {
        $content = sprintf("%s %s\n%s.%s.%s", $method, $path, $this->clientId, $requestTime, $body);
        
        $key = $this->privateKey;
        if (!str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
        }

        $privateKeyResource = openssl_pkey_get_private($key);
        if (!$privateKeyResource) {
            throw new \RuntimeException('Invalid RSA private key provided for Alipay.');
        }

        openssl_sign($content, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }

    /**
     * Helper to verify webhook signature using RSA-SHA256 with the Alipay public key.
     */
    private function verifySignature(string $content, string $signature): bool
    {
        $key = $this->alipayPublicKey;
        if (!str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($key, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        }

        $publicKeyResource = openssl_pkey_get_public($key);
        if (!$publicKeyResource) {
            error_log('Alipay: invalid public key provided for signature verification');
            return false;
        }

        $decodedSig = base64_decode($signature, true);
        if ($decodedSig === false) {
            return false;
        }

        return openssl_verify($content, $decodedSig, $publicKeyResource, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Create an Alipay Cashier Payment checkout session.
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        $path = '/ams/api/v1/payments/pay';
        $requestTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        $orderUuid = $order->id()->uuid();
        $amountCents = (string) intval(round($order->amount()->amount() * 100));
        $currency = strtoupper($order->amount()->currency()->toString());

        $requestData = [
            'productCode'      => 'CASHIER_PAYMENT',
            'paymentRequestId' => $orderUuid,
            'order' => [
                'referenceOrderId' => $orderUuid,
                'orderDescription' => 'Order ' . $orderUuid,
                'orderAmount' => [
                    'value'    => $amountCents,
                    'currency' => $currency,
                ],
                'merchant' => [
                    'referenceMerchantId' => $this->merchantId,
                ],
            ],
            'paymentAmount' => [
                'value'    => $amountCents,
                'currency' => $currency,
            ],
            'paymentRedirectUrl' => $successUrl,
            'paymentNotifyUrl'   => $successUrl . '?webhook=alipay',
        ];

        $jsonBody = json_encode($requestData, JSON_UNESCAPED_SLASHES);
        $signature = $this->signRequest('POST', $path, $jsonBody, $requestTime);

        $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);

        try {
            $response = $client->post($path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Client-Id'    => $this->clientId,
                    'Request-Time' => $requestTime,
                    'Signature'    => sprintf('algorithm=RSA256,keyVersion=1,signature=%s', $signature),
                ],
                'body' => $jsonBody,
            ]);

            $data = json_decode((string) $response->getBody(), true);

            return [
                'checkout_url' => $data['normalUrl'] ?? $data['paymentUrl'] ?? null,
                'external_id'  => (string) ($data['paymentId'] ?? $orderUuid),
            ];
        } catch (\Exception $e) {
            error_log('Alipay: failed to create checkout session: ' . $e->getMessage());
            return [
                'checkout_url' => null,
                'external_id'  => null,
                'error'        => $e->getMessage(),
            ];
        }
    }

    /**
     * Validate Alipay webhook RSA256 signature.
     */
    public function validateWebhook(array $payload, array $headers): bool
    {
        $signatureHeader = $headers['signature']
            ?? $headers['Signature']
            ?? $headers['SIGNATURE']
            ?? '';
        $clientId = $headers['client-id']
            ?? $headers['Client-Id']
            ?? $headers['CLIENT-ID']
            ?? $this->clientId;
        $requestTime = $headers['request-time']
            ?? $headers['Request-Time']
            ?? $headers['REQUEST-TIME']
            ?? '';

        if (empty($signatureHeader)) {
            error_log('Alipay: missing Signature header in webhook');
            return false;
        }

        // Extract signature component (format: algorithm=RSA256,keyVersion=1,signature=xxx)
        $sigValue = '';
        foreach (explode(',', $signatureHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2 && $kv[0] === 'signature') {
                $sigValue = $kv[1];
                break;
            }
        }

        if (empty($sigValue)) {
            error_log('Alipay: could not extract signature value from header');
            return false;
        }

        $rawBody = $headers['X-RAW-BODY'] ?? json_encode($payload, JSON_UNESCAPED_SLASHES);
        $content = sprintf("POST /webhooks/alipay\n%s.%s.%s", $clientId, $requestTime, $rawBody);

        return $this->verifySignature($content, $sigValue);
    }

    /**
     * Parse an Alipay webhook event into normalized form.
     */
    public function parseWebhookEvent(array $payload): array
    {
        $result = $payload['result'] ?? [];
        $resultStatus = $result['resultStatus'] ?? '';
        $notifyType = $payload['notifyType'] ?? 'notifyPayment';

        $action = match ($resultStatus) {
            'S'     => ($notifyType === 'notifyRefund') ? 'REFUND_PROCESSED' : 'INITIAL_PAYMENT',
            'F'     => 'PAYMENT_FAILED',
            default => 'IGNORED',
        };

        $paymentAmount = $payload['paymentAmount']['value'] ?? $payload['refundAmount']['value'] ?? 0;
        $amount = (float) $paymentAmount / 100;
        $currency = strtoupper($payload['paymentAmount']['currency'] ?? $payload['refundAmount']['currency'] ?? 'USD');

        return [
            'action'                   => $action,
            'external_order_id'        => (string) ($payload['paymentId'] ?? ''),
            'external_subscription_id' => (string) ($payload['paymentId'] ?? ''),
            'external_transaction_id'  => (string) ($payload['paymentId'] ?? ''),
            'internal_order_id'        => $payload['paymentRequestId'] ?? null,
            'amount'                   => $amount,
            'currency'                 => $currency,
            'status'                   => ($action === 'INITIAL_PAYMENT') ? 'paid' : 'failed',
            'external_client_id'       => $payload['paymentRequestId'] ?? null,
            'trial_end'                => null,
            'full_refund'              => ($action === 'REFUND_PROCESSED'),
            'raw_payload'              => $payload,
        ];
    }

    /**
     * Issue a refund for an Alipay payment.
     */
    public function refund(string $externalTransactionId, float $amount, string $reason): array
    {
        $path = '/ams/api/v1/payments/refund';
        $requestTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        $refundRequestId = 'refund_' . bin2hex(random_bytes(8));

        $requestData = [
            'paymentId'       => $externalTransactionId,
            'refundRequestId' => $refundRequestId,
            'refundAmount'    => [
                'value'    => (string) intval(round($amount * 100)),
                'currency' => 'USD',
            ],
            'refundReason' => $reason,
        ];

        $jsonBody = json_encode($requestData, JSON_UNESCAPED_SLASHES);
        $signature = $this->signRequest('POST', $path, $jsonBody, $requestTime);

        try {
            $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);
            $response = $client->post($path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Client-Id'    => $this->clientId,
                    'Request-Time' => $requestTime,
                    'Signature'    => sprintf('algorithm=RSA256,keyVersion=1,signature=%s', $signature),
                ],
                'body' => $jsonBody,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $status = $data['result']['resultStatus'] ?? 'F';

            if ($status === 'S') {
                return [
                    'status'             => 'success',
                    'external_refund_id' => (string) ($data['refundId'] ?? $refundRequestId),
                ];
            }

            return [
                'status' => 'failed',
                'error'  => $data['result']['resultMessage'] ?? 'Refund failed',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ];
        }
    }

    /**
     * Fetch the current status of an Alipay payment.
     */
    public function verifyTransaction(string $externalId): array
    {
        $path = '/ams/api/v1/payments/inquiryPayment';
        $requestTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

        $requestData = ['paymentId' => $externalId];
        $jsonBody = json_encode($requestData, JSON_UNESCAPED_SLASHES);
        $signature = $this->signRequest('POST', $path, $jsonBody, $requestTime);

        try {
            $client = new \GuzzleHttp\Client(['base_uri' => self::BASE_URL]);
            $response = $client->post($path, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Client-Id'    => $this->clientId,
                    'Request-Time' => $requestTime,
                    'Signature'    => sprintf('algorithm=RSA256,keyVersion=1,signature=%s', $signature),
                ],
                'body' => $jsonBody,
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $status = $data['paymentStatus'] ?? $data['result']['resultStatus'] ?? '';

            if ($status === 'SUCCESS' || $status === 'S') {
                return [
                    'action'                   => 'INITIAL_PAYMENT',
                    'external_id'              => (string) ($data['paymentId'] ?? $externalId),
                    'external_subscription_id' => (string) ($data['paymentId'] ?? $externalId),
                    'external_transaction_id'  => (string) ($data['paymentId'] ?? $externalId),
                    'amount'                   => (float) (($data['paymentAmount']['value'] ?? 0) / 100),
                    'currency'                 => strtoupper($data['paymentAmount']['currency'] ?? 'USD'),
                    'external_client_id'       => $data['paymentRequestId'] ?? null,
                    'internal_order_id'        => $data['paymentRequestId'] ?? null,
                    'raw_payload'              => $data,
                ];
            }

            return ['action' => 'IGNORED', 'status' => $status];
        } catch (\Exception $e) {
            return ['action' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): array
    {
        return [
            'status' => 'failed',
            'error'  => 'Alipay Global does not support native recurring subscriptions.',
        ];
    }

    public function pauseSubscription(string $externalId): array
    {
        return [
            'status' => 'failed',
            'error'  => 'Alipay Global does not support subscription pausing.',
        ];
    }
}
