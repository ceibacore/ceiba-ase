<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Payments;

use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\ValueObjects\MinorUnits;
use LemurAse\Infrastructure\Customers\CustomerContacts;
use LemurAse\Infrastructure\Payments\Razorpay\RazorpayClient;
use LemurAse\Infrastructure\Payments\Razorpay\RazorpayEvents;
use LemurAse\Infrastructure\Payments\Razorpay\RazorpaySignature;

/**
 * Razorpay (India; international cards).
 *
 * Checkout: Payment Links (redirect to short_url). reference_id is the order id; the
 * payment link id becomes the order's external id.
 * Webhooks: HMAC-SHA256 of the raw body with the dashboard webhook secret
 * (X-Razorpay-Signature). Subscribe to payment_link.paid and refund.processed.
 */
final class RazorpayAdapter implements PaymentGatewayInterface, \LemurAse\Domain\Gateways\OneTimePaymentGatewayInterface, \LemurAse\Domain\Gateways\CredentialProbeInterface
{
    private readonly RazorpayClient $api;

    public function __construct(
        private readonly string $keyId,
        string $keySecret,
        private readonly string $webhookSecret,
    ) {
        $this->api = new RazorpayClient($keyId, $keySecret);
    }

    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        return $this->createPaymentSession($order, $successUrl, $cancelUrl);
    }

    /** Plan orders and ceiba-ase payments share the checkout: the id travels as the order reference. */
    public function createPaymentSession(\LemurAse\Domain\Gateways\Chargeable $order, string $successUrl, string $cancelUrl): array
    {
        $currency = strtoupper($order->amount()->currency()->toString());
        $orderId = $order->id()->uuid();
        $body = [
            'amount'          => MinorUnits::toMinor($order->amount()->amount(), $currency),
            'currency'        => $currency,
            'accept_partial'  => false,
            'reference_id'    => $orderId,
            'description'     => mb_substr($order->description(), 0, 2048),
            'notify'          => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'callback_url'    => $successUrl,
            'callback_method' => 'get',
            'notes'           => ['order_id' => $orderId, 'external_client_id' => $order->externalClientId()],
        ];
        $contact = CustomerContacts::find($order->externalClientId());
        if ($contact !== null) {
            $body['customer'] = array_filter(['email' => $contact['email'], 'name' => $contact['name']]);
        }

        $link = $this->api->createPaymentLink($body);
        if (empty($link['short_url']) || empty($link['id'])) {
            throw new \RuntimeException('Razorpay did not return a payment link.');
        }

        return ['checkout_url' => (string) $link['short_url'], 'external_id' => (string) $link['id']];
    }

    /** Needs the exact raw body in $headers['X-RAW-BODY']: re-encoded JSON does not match the signature. */
    public function validateWebhook(array $payload, array $headers): bool
    {
        try {
            RazorpaySignature::verify((string) ($headers['X-RAW-BODY'] ?? ''), $headers, $this->webhookSecret);
            return true;
        } catch (\InvalidArgumentException $e) {
            error_log($e->getMessage());
            return false;
        }
    }

    public function parseWebhookEvent(array $payload): array
    {
        $normalized = RazorpayEvents::normalize($payload);
        return ['action' => $normalized['action'] === 'UNKNOWN' ? 'IGNORED' : $normalized['action']]
            + $normalized['data'] + ['external_id' => $normalized['external_id']];
    }

    /** $externalTransactionId is the payment link id stored on the order. */
    public function refund(string $externalTransactionId, float $amount, string $reason): array
    {
        try {
            $link = $this->api->paymentLink($externalTransactionId);
            $payment = self::capturedPayment($link);
            $currency = strtoupper((string) ($link['currency'] ?? ''));
            if ($payment === null || !preg_match('/^[A-Z]{3}$/', $currency)) {
                return ['status' => 'failed', 'error' => 'Razorpay: payment link has no captured payment'];
            }
            $body = ['notes' => [
                'order_id' => (string) ($link['reference_id'] ?? ''),
                'reason'   => mb_substr($reason, 0, 255),
            ]];
            if ($amount > 0) {
                $body['amount'] = MinorUnits::toMinor($amount, $currency);
            }
            $refund = $this->api->refundPayment((string) $payment['payment_id'], $body);
            return ['status' => 'success', 'external_refund_id' => (string) ($refund['id'] ?? '')];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    public function verifyTransaction(string $externalId): array
    {
        try {
            $link = $this->api->paymentLink($externalId);
        } catch (\Throwable $e) {
            return ['action' => 'FAILED', 'error' => $e->getMessage()];
        }
        $payment = self::capturedPayment($link);
        if (($link['status'] ?? '') !== 'paid' || $payment === null) {
            return ['action' => 'IGNORED', 'status' => (string) ($link['status'] ?? 'unknown')];
        }
        return ['action' => 'INITIAL_PAYMENT', 'external_id' => $externalId] + RazorpayEvents::paymentData(
            $link,
            ['id' => $payment['payment_id'], 'amount' => $payment['amount'] ?? $link['amount_paid'] ?? 0, 'currency' => $link['currency'] ?? ''],
            $link,
        );
    }

    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): array
    {
        return ['status' => 'failed', 'error' => 'Razorpay payment links are one-time; ceiba-ase does not support Razorpay subscriptions.'];
    }

    public function pauseSubscription(string $externalId): array
    {
        return ['status' => 'failed', 'error' => 'Razorpay payment links are one-time; ceiba-ase does not support pausing them.'];
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    /** @return array{payment_id: string, amount?: int}|null */
    private static function capturedPayment(array $link): ?array
    {
        foreach ((array) ($link['payments'] ?? []) as $payment) {
            if (is_array($payment) && ($payment['status'] ?? '') === 'captured' && !empty($payment['payment_id'])) {
                return $payment;
            }
        }
        return null;
    }

    /** GET /v1/payments?count=1 with the key pair (the webhook secret cannot be checked remotely). */
    public function probeCredentials(): array
    {
        try {
            $this->api->listPayments(1);
            return ['ok' => true, 'livemode' => str_starts_with($this->keyId, 'rzp_live_'), 'message' => 'Razorpay: credenciales válidas.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'livemode' => null, 'message' => $e->getMessage()];
        }
    }
}
