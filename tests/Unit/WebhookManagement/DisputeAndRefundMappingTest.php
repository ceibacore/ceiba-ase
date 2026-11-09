<?php

declare(strict_types=1);

namespace LemurAse\Tests\Unit\WebhookManagement;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LemurAse\Infrastructure\Http\HttpClientFactory;
use LemurAse\Infrastructure\Payments\Razorpay\RazorpayEvents;
use LemurAse\WebhookManagement\Infrastructure\Adapters\PayPalWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\StripeWebhookAdapter;
use PHPUnit\Framework\TestCase;

/** Provider disputes and refunds normalized to DISPUTE_OPENED / DISPUTE_CLOSED / REFUND_PROCESSED. */
final class DisputeAndRefundMappingTest extends TestCase
{
    private const STRIPE_SECRET = 'whsec_test_disputes_0123456789';
    private const PAYPAL = ['client_id' => 'cid', 'secret' => 'sec', 'webhook_id' => 'WH-1', 'sandbox' => false];

    private MockHandler $http;

    protected function setUp(): void
    {
        $this->http = new MockHandler();
        HttpClientFactory::useHandler(HandlerStack::create($this->http));
    }

    protected function tearDown(): void
    {
        HttpClientFactory::useHandler(null);
    }

    private function stripe(string $type, array $dispute): \LemurAse\WebhookManagement\Domain\WebhookEvent
    {
        $raw = json_encode(['id' => 'evt_' . $type, 'object' => 'event', 'type' => $type, 'data' => ['object' => $dispute]]);
        $t = (string) time();

        return (new StripeWebhookAdapter())->parse($raw, ['stripe-signature' => "t={$t},v1=" . hash_hmac('sha256', "{$t}.{$raw}", self::STRIPE_SECRET)],
            ['webhook_secret' => self::STRIPE_SECRET]);
    }

    private function paypal(array $event): \LemurAse\WebhookManagement\Domain\WebhookEvent
    {
        $this->http->append(
            new Response(200, [], json_encode(['access_token' => 'tok'])),
            new Response(200, [], json_encode(['verification_status' => 'SUCCESS'])),
        );

        return (new PayPalWebhookAdapter())->parse(json_encode($event), [
            'paypal-transmission-id' => ['8b7f0a10-1d11-11ef-9b8c-0b3b8a8f1d2e'],
            'paypal-transmission-time' => [gmdate('Y-m-d\TH:i:s\Z')],
            'paypal-cert-url' => ['https://api.paypal.com/v1/notifications/certs/CERT-1'],
            'paypal-transmission-sig' => ['c2lnbmF0dXJl'],
            'paypal-auth-algo' => ['SHA256withRSA'],
        ], self::PAYPAL);
    }

    public function testStripeDisputesPointAtThePaymentIntent(): void
    {
        $opened = $this->stripe('charge.dispute.created', ['id' => 'dp_1', 'object' => 'dispute', 'charge' => 'ch_1',
            'payment_intent' => 'pi_1', 'amount' => 4990, 'currency' => 'usd', 'reason' => 'fraudulent', 'status' => 'needs_response']);
        $this->assertSame(['DISPUTE_OPENED', 'pi_1', 'dp_1', 49.9, 'USD', 'fraudulent', null], [$opened->action,
            $opened->data['refund_reference'], $opened->data['dispute_id'], $opened->data['amount'], $opened->data['currency'],
            $opened->data['reason'], $opened->data['outcome']]);

        $lost = $this->stripe('charge.dispute.closed', ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_1',
            'amount' => 4990, 'currency' => 'usd', 'status' => 'lost']);
        $this->assertSame(['DISPUTE_CLOSED', 'lost'], [$lost->action, $lost->data['outcome']]);

        $inquiry = $this->stripe('charge.dispute.closed', ['id' => 'dp_2', 'object' => 'dispute', 'payment_intent' => 'pi_1',
            'amount' => 4990, 'currency' => 'usd', 'status' => 'warning_closed']);
        $this->assertNull($inquiry->data['outcome'], 'An inquiry closed without chargeback is not an outcome');
    }

    public function testPayPalCaptureRefundsAreMappedToTheCapture(): void
    {
        $event = $this->paypal(['id' => 'WH-R1', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [
            'id' => 'REF-9', 'custom_id' => 'payment-uuid', 'amount' => ['value' => '10.00', 'currency_code' => 'EUR'],
            'seller_payable_breakdown' => ['total_refunded_amount' => ['value' => '25.00', 'currency_code' => 'EUR']],
            'links' => [['rel' => 'self', 'href' => 'https://api.paypal.com/v2/payments/refunds/REF-9'],
                ['rel' => 'up', 'href' => 'https://api.paypal.com/v2/payments/captures/CAP-1']],
        ]]);

        $this->assertSame(['REFUND_PROCESSED', 'CAP-1', 'payment-uuid', 'paypal:refund:REF-9', 10.0, 25.0, 'EUR'], [$event->action,
            $event->data['refund_reference'], $event->data['internal_order_id'], $event->data['external_refund_id'],
            $event->data['amount'], $event->data['refunded_total'], $event->data['currency']]);
    }

    public function testPayPalDisputesAndTheirOutcome(): void
    {
        $resource = ['dispute_id' => 'PP-D-1', 'reason' => 'MERCHANDISE_OR_SERVICE_NOT_RECEIVED',
            'dispute_amount' => ['value' => '49.90', 'currency_code' => 'USD'],
            'disputed_transactions' => [['seller_transaction_id' => 'CAP-1', 'custom' => 'payment-uuid']]];

        $opened = $this->paypal(['id' => 'WH-D1', 'event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => $resource]);
        $this->assertSame(['DISPUTE_OPENED', 'CAP-1', 'PP-D-1', 49.9], [$opened->action, $opened->data['refund_reference'],
            $opened->data['dispute_id'], $opened->data['amount']]);

        $won = $this->paypal(['id' => 'WH-D2', 'event_type' => 'CUSTOMER.DISPUTE.RESOLVED',
            'resource' => $resource + ['dispute_outcome' => ['outcome_code' => 'RESOLVED_SELLER_FAVOUR']]]);
        $lost = $this->paypal(['id' => 'WH-D3', 'event_type' => 'CUSTOMER.DISPUTE.RESOLVED',
            'resource' => $resource + ['dispute_outcome' => ['outcome_code' => 'RESOLVED_BUYER_FAVOUR']]]);
        $this->assertSame(['DISPUTE_CLOSED', 'won', 'lost'], [$won->action, $won->data['outcome'], $lost->data['outcome']]);
    }

    public function testRazorpayDisputes(): void
    {
        $payload = ['dispute' => ['entity' => ['id' => 'disp_1', 'payment_id' => 'pay_1', 'amount' => 499000, 'currency' => 'INR',
            'reason_code' => 'chargeback']], 'payment' => ['entity' => ['id' => 'pay_1', 'notes' => ['order_id' => 'payment-uuid']]]];

        $opened = RazorpayEvents::normalize(['event' => 'payment.dispute.created', 'payload' => $payload]);
        $lost = RazorpayEvents::normalize(['event' => 'payment.dispute.lost', 'payload' => $payload]);

        $this->assertSame(['DISPUTE_OPENED', 'payment-uuid', 'pay_1', 'disp_1', 4990.0, null],
            [$opened['action'], $opened['data']['internal_order_id'], $opened['data']['external_transaction_id'],
                $opened['data']['dispute_id'], $opened['data']['amount'], $opened['data']['outcome']]);
        $this->assertSame(['DISPUTE_CLOSED', 'lost'], [$lost['action'], $lost['data']['outcome']]);
    }
}
