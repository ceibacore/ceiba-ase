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
final class AlipayAdapter implements PaymentGatewayInterface, \LemurAse\Domain\Gateways\OneTimePaymentGatewayInterface, \LemurAse\Domain\Gateways\CredentialProbeInterface
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
     * Create an Alipay Cashier Payment checkout session.
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        return $this->createPaymentSession($order, $successUrl, $cancelUrl);
    }

    /** Plan orders and ceiba-ase payments share the checkout: the id travels as the order reference. */
    public function createPaymentSession(\LemurAse\Domain\Gateways\Chargeable $order, string $successUrl, string $cancelUrl): array
    {
        $path = '/ams/api/v1/payments/pay';
        $requestTime = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
        $orderUuid = $order->id()->uuid();
        $currency = strtoupper($order->amount()->currency()->toString());
        $amountCents = (string) \LemurAse\Domain\ValueObjects\MinorUnits::toMinor($order->amount()->amount(), $currency);
        // Antom posts the payment result to this URL and signs its path: it must be the
        // host's webhook endpoint (it used to be the success page, so no webhook arrived).
        $notifyUrl = \LemurAse\Infrastructure\Http\WebhookUrls::for('alipay', $order->context(), $order->accountRef())
            ?? throw new \RuntimeException('Alipay: no webhook URL. Register WebhookUrls::resolveUsing() in the host application.');

        $requestData = [
            'productCode'      => 'CASHIER_PAYMENT',
            'paymentRequestId' => $orderUuid,
            'order' => [
                'referenceOrderId' => $orderUuid,
                'orderDescription' => mb_substr($order->description(), 0, 250),
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
            'paymentNotifyUrl'   => $notifyUrl,
        ];

        $jsonBody = json_encode($requestData, JSON_UNESCAPED_SLASHES);
        $signature = $this->signRequest('POST', $path, $jsonBody, $requestTime);

        $client = \LemurAse\Infrastructure\Http\HttpClientFactory::create(['base_uri' => self::BASE_URL]);

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
        // The signature covers the exact raw body; re-encoding the decoded payload is a fallback.
        $rawBody = $headers['X-RAW-BODY'] ?? json_encode($payload, JSON_UNESCAPED_SLASHES);
        try {
            Alipay\AlipaySignature::verify((string) $rawBody, $headers, $this->alipayPublicKey, $this->clientId,
                \LemurAse\Infrastructure\Http\WebhookUrls::pathFor('alipay') ?? Alipay\AlipaySignature::DEFAULT_NOTIFY_PATH);
            return true;
        } catch (\InvalidArgumentException $e) {
            error_log('Alipay: ' . $e->getMessage());
            return false;
        }
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
        $currency = strtoupper($payload['paymentAmount']['currency'] ?? $payload['refundAmount']['currency'] ?? 'USD');
        $amount = \LemurAse\Domain\ValueObjects\MinorUnits::fromMinor($paymentAmount, $currency);

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

        // Refund in the payment's own currency (never assume USD): look it up first.
        $payment = $this->verifyTransaction($externalTransactionId);
        $currency = strtoupper((string) ($payment['currency'] ?? ''));
        if (!preg_match('/^[A-Z]{3}$/', $currency) || ($payment['action'] ?? '') !== 'INITIAL_PAYMENT') {
            return ['status' => 'failed', 'error' => 'Alipay: payment currency unavailable; refund not sent'];
        }
        $requestData = [
            'paymentId'       => $externalTransactionId,
            'refundRequestId' => $refundRequestId,
            'refundAmount'    => [
                'value'    => (string) \LemurAse\Domain\ValueObjects\MinorUnits::toMinor($amount, $currency),
                'currency' => $currency,
            ],
            'refundReason' => $reason,
        ];

        $jsonBody = json_encode($requestData, JSON_UNESCAPED_SLASHES);
        $signature = $this->signRequest('POST', $path, $jsonBody, $requestTime);

        try {
            $client = \LemurAse\Infrastructure\Http\HttpClientFactory::create(['base_uri' => self::BASE_URL]);
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
            $client = \LemurAse\Infrastructure\Http\HttpClientFactory::create(['base_uri' => self::BASE_URL]);
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
                    'amount'                   => \LemurAse\Domain\ValueObjects\MinorUnits::fromMinor($data['paymentAmount']['value'] ?? 0, (string) ($data['paymentAmount']['currency'] ?? 'USD')),
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

    /** Antom has no harmless authenticated call: both RSA keys are validated offline. */
    public function probeCredentials(): array
    {
        $private = str_contains($this->privateKey, '-----BEGIN') ? $this->privateKey
            : "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($this->privateKey, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
        $public = str_contains($this->alipayPublicKey, '-----BEGIN') ? $this->alipayPublicKey
            : "-----BEGIN PUBLIC KEY-----\n" . wordwrap($this->alipayPublicKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        if (!openssl_pkey_get_private($private)) {
            return ['ok' => false, 'livemode' => null, 'message' => 'Alipay: la clave privada RSA no es válida.'];
        }
        if (!openssl_pkey_get_public($public)) {
            return ['ok' => false, 'livemode' => null, 'message' => 'Alipay: la clave pública de Alipay no es válida.'];
        }
        return ['ok' => true, 'livemode' => !$this->sandbox, 'message' => 'Alipay: claves válidas (verificación local; Antom no ofrece una llamada de prueba).'];
    }
}
