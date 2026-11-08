<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\ValueObjects\MinorUnits;
use LemurAse\Infrastructure\Customers\CustomerContacts;
use LemurAse\Infrastructure\Payments\Paystack\PaystackClient;
use LemurAse\Infrastructure\Payments\Paystack\PaystackEvents;
use LemurAse\Infrastructure\Payments\Paystack\PaystackSignature;

/**
 * Paystack (Nigeria, Ghana, South Africa, Kenya, Côte d'Ivoire, Egypt).
 *
 * Checkout: Paystack Standard (redirect to authorization_url). The order is identified by
 * metadata.order_id; the transaction reference becomes the order's external id.
 * Webhooks: HMAC-SHA512 of the raw body with the secret key (x-paystack-signature).
 * Test or live mode follows the keys (sk_test_ / sk_live_).
 */
final class PaystackAdapter implements PaymentGatewayInterface, \LemurAse\Domain\Gateways\OneTimePaymentGatewayInterface, \LemurAse\Domain\Gateways\CredentialProbeInterface
{
    private readonly PaystackClient $api;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $publicKey,
    ) {
        $this->api = new PaystackClient($secretKey);
    }

    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        return $this->createPaymentSession($order, $successUrl, $cancelUrl);
    }

    /** Plan orders and ceiba-ase payments share the checkout: the id travels as the order reference. */
    public function createPaymentSession(\LemurAse\Domain\Gateways\Chargeable $order, string $successUrl, string $cancelUrl): array
    {
        $currency = strtoupper($order->amount()->currency()->toString());
        $contact = CustomerContacts::require($order->externalClientId(), 'Paystack');
        // A reference is single-use at Paystack: a new checkout for the same order needs a new one.
        $reference = sprintf('ase-%s-%s', $order->id()->uuid(), bin2hex(random_bytes(4)));

        $data = $this->api->initialize([
            'email'        => $contact['email'],
            'amount'       => MinorUnits::toMinor($order->amount()->amount(), $currency),
            'currency'     => $currency,
            'reference'    => $reference,
            'callback_url' => $successUrl,
            'metadata'     => [
                'order_id'           => $order->id()->uuid(),
                'external_client_id' => $order->externalClientId(),
                'cancel_action'      => $cancelUrl,
            ],
        ]);
        if (empty($data['authorization_url'])) {
            throw new \RuntimeException('Paystack did not return an authorization URL.');
        }

        return [
            'checkout_url' => (string) $data['authorization_url'],
            'external_id'  => (string) ($data['reference'] ?? $reference),
        ];
    }

    /** Needs the exact raw body in $headers['X-RAW-BODY']: re-encoded JSON does not match the signature. */
    public function validateWebhook(array $payload, array $headers): bool
    {
        try {
            PaystackSignature::verify((string) ($headers['X-RAW-BODY'] ?? ''), $headers, $this->secretKey);
            return true;
        } catch (\InvalidArgumentException $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function parseWebhookEvent(array $payload): array
    {
        $normalized = PaystackEvents::normalize($payload, $this->api);
        return ['action' => $normalized['action'] === 'UNKNOWN' ? 'IGNORED' : $normalized['action']]
            + $normalized['data'] + ['external_id' => $normalized['external_id']];
    }

    public function refund(string $externalTransactionId, float $amount, string $reason): array
    {
        try {
            // Refund in the transaction's own currency (never assumed).
            $transaction = $this->api->verify($externalTransactionId);
            $currency = strtoupper((string) ($transaction['currency'] ?? ''));
            if (($transaction['status'] ?? '') !== 'success' || !preg_match('/^[A-Z]{3}$/', $currency)) {
                return ['status' => 'failed', 'error' => 'Paystack: transaction is not refundable'];
            }
            $body = ['transaction' => $externalTransactionId, 'currency' => $currency, 'merchant_note' => mb_substr($reason, 0, 255)];
            if ($amount > 0) {
                $body['amount'] = MinorUnits::toMinor($amount, $currency);
            }
            $refund = $this->api->refund($body);
            return ['status' => 'success', 'external_refund_id' => (string) ($refund['id'] ?? '')];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function verifyTransaction(string $externalId): array
    {
        try {
            $transaction = $this->api->verify($externalId);
        } catch (\Throwable $e) {
            return ['action' => 'FAILED', 'error' => $e->getMessage()];
        }
        if (($transaction['status'] ?? '') !== 'success') {
            return ['action' => 'IGNORED', 'status' => (string) ($transaction['status'] ?? 'unknown')];
        }
        return ['action' => 'INITIAL_PAYMENT', 'external_id' => $externalId]
            + PaystackEvents::paymentData($transaction, $transaction);
    }

    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): array
    {
        return ['status' => 'failed', 'error' => 'Paystack payments are one-time; ceiba-ase does not support Paystack subscriptions.'];
    }

    public function pauseSubscription(string $externalId): array
    {
        return ['status' => 'failed', 'error' => 'Paystack payments are one-time; ceiba-ase does not support pausing them.'];
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    /** GET /balance with the secret key. */
    public function probeCredentials(): array
    {
        try {
            $this->api->balance();
            return ['ok' => true, 'livemode' => str_starts_with($this->secretKey, 'sk_live_'), 'message' => 'Paystack: credenciales válidas.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'livemode' => null, 'message' => $e->getMessage()];
        }
    }
}
