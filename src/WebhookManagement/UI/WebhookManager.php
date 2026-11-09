<?php

namespace LemurAse\WebhookManagement\UI;

use LemurAse\WebhookManagement\Domain\PaymentVerificationException;

use LemurAse\AseManager;
use LemurAse\Application\UseCases\ProcessPaymentEvent;
use LemurAse\Domain\Entities\Payment;
use LemurAse\Domain\ValueObjects\AccountRef;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Infrastructure\Http\WebhookUrls;
use LemurAse\WebhookManagement\Domain\WebhookEvent;
use Ramsey\Uuid\Uuid;
use LemurAse\WebhookManagement\Application\ProcessWebhookUseCase;
use LemurAse\WebhookManagement\Infrastructure\Adapters\StripeWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\PayPalWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\MercadoPagoWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\AlipayWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\PaystackWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\RazorpayWebhookAdapter;
use LemurAse\WebhookManagement\Infrastructure\Adapters\WebhookAdapterInterface;

final class WebhookManager
{
    private static array $adapters = [
        'stripe'      => StripeWebhookAdapter::class,
        'paypal'      => PayPalWebhookAdapter::class,
        'mercadopago' => MercadoPagoWebhookAdapter::class,
        'alipay'      => AlipayWebhookAdapter::class,
        'paystack'    => PaystackWebhookAdapter::class,
        'razorpay'    => RazorpayWebhookAdapter::class,
    ];

    /**
     * Entry point to process any incoming webhook.
     *
     * The request is verified with the credentials of the account that owns the URL
     * (null = platform, 'tenant:{id}' = an academy's own gateway). The event then goes to:
     *  - the one-time payment it refers to (ase_payments), which must belong to that same
     *    account (ProcessPaymentEvent);
     *  - otherwise, for the platform account only, the plan subscription flow.
     * Events of an account that match no payment are acknowledged and recorded in the outbox
     * as webhook.unmatched (orphans, for reconciliation).
     *
     * @param string $payload Raw body from request
     * @param array  $headers Headers from request
     * @param string $provider 'stripe', 'paypal', 'mercadopago', 'alipay', etc.
     * @param string|null $accountRef Owner of the gateway credentials (null = platform)
     */
    public static function handle(string $payload, array $headers, string $provider, ?string $accountRef = null): bool
    {
        $providerKey = strtolower(trim($provider));
        $adapterClass = self::$adapters[$providerKey] ?? null;
        if (!$adapterClass) {
            throw new \InvalidArgumentException("No webhook adapter registered for provider: {$provider}");
        }
        $accountRef = AccountRef::normalize($accountRef);

        /** @var WebhookAdapterInterface $adapter */
        $adapter = new $adapterClass();
        $ase = AseManager::getInstance();

        // 1. Credentials of the account behind this URL (inactive gateways still verify late events).
        $gateway = $ase->gatewayRepo->findByProviderForAccount($providerKey, $accountRef, false);
        if ($accountRef !== null && $gateway === null) {
            throw new \InvalidArgumentException("No webhook adapter registered for provider: {$provider} on this account");
        }
        $config = $gateway ? $gateway->credentials() + ['gateway_id' => $gateway->id()->uuid()] : [];
        if ($accountRef !== null && !isset($config['notify_path'])) {
            // Alipay signs the path it was told to notify: the account's own webhook URL.
            $path = WebhookUrls::pathFor($providerKey, 'marketplace', $accountRef);
            if ($path !== null) {
                $config['notify_path'] = $path;
            }
        }

        // 2. Verify and normalize
        $event = $adapter->parse($payload, $headers, $config);
        if (isset($config['gateway_id'])) {
            $event = $event->withData(['gateway_id' => $config['gateway_id']]);
        }

        // 3. One-time payment?
        $payment = self::findPayment($providerKey, $event);
        if ($payment !== null) {
            return (new ProcessPaymentEvent($ase->paymentRepo, $ase->logRepo))->execute($payment, $event, $accountRef);
        }
        // 4. Subscription flow: an account's own plans (memberships) or the platform's plans.
        //    Each URL only reaches the plans of its own account.
        $owner = self::subscriptionAccount($event);
        if ($owner !== false) {
            if ($owner !== $accountRef) {
                throw new PaymentVerificationException(sprintf('Event %s belongs to a plan of another account.', $event->externalId));
            }
            return self::createUseCase()->execute($event);
        }

        if ($accountRef !== null) {
            // Acknowledged (the provider must not retry forever) but kept for reconciliation.
            if ($event->action !== 'UNKNOWN') {
                \LemurAse\Infrastructure\Events\AseEventDispatcher::recordOutbox('webhook.unmatched', [
                    'provider' => $providerKey,
                    'account_ref' => $accountRef,
                    'external_id' => $event->externalId,
                    'action' => $event->action,
                    'amount' => $event->data['amount'] ?? null,
                    'currency' => $event->data['currency'] ?? null,
                    'reference' => (string) ($event->data['internal_order_id'] ?? $event->data['refund_reference'] ?? $event->data['external_transaction_id'] ?? ''),
                ], 'webhook', $providerKey . ':' . $event->externalId);
            }
            error_log(sprintf('ASE webhook: %s event %s of account %s matches no payment; recorded as webhook.unmatched.', $providerKey, $event->externalId, $accountRef));
            return true;
        }

        // 5. Platform events of plans not known yet (e.g. a subscription created at the provider).
        return self::createUseCase()->execute($event);
    }

    /**
     * Account of the plan an event refers to (through its order or subscription), or false
     * when it refers to none.
     */
    private static function subscriptionAccount(WebhookEvent $event): string|null|false
    {
        $ase = AseManager::getInstance();
        $planPriceId = null;
        $orderId = (string) ($event->data['internal_order_id'] ?? '');
        if ($orderId !== '' && Uuid::isValid($orderId) && ($order = $ase->orderRepo->findById(EntityId::fromString($orderId))) !== null) {
            $planPriceId = $order->planPriceId();
        }
        $externalSubscription = (string) ($event->data['external_subscription_id'] ?? '');
        if ($planPriceId === null && $externalSubscription !== '' && ($sub = $ase->subscriptionRepo->findByExternalId($externalSubscription)) !== null) {
            $planPriceId = $sub->planPriceId();
        }
        if ($planPriceId === null) {
            return false;
        }
        $price = $ase->planPriceRepo->findById($planPriceId);
        $plan = $price ? $ase->planRepo->findById($price->planId()) : null;

        return $plan ? $plan->accountRef() : false;
    }

    /** The payment an event refers to: by its id (sent as the order reference), then by provider ids. */
    private static function findPayment(string $provider, WebhookEvent $event): ?Payment
    {
        $repo = AseManager::getInstance()->paymentRepo;
        $internal = (string) ($event->data['internal_order_id'] ?? '');
        if ($internal !== '' && Uuid::isValid($internal)) {
            $payment = $repo->findById(EntityId::fromString($internal));
            if ($payment !== null) {
                return $payment->provider() === $provider ? $payment : null;
            }
        }
        foreach (['refund_reference', 'external_transaction_id', 'external_subscription_id'] as $key) {
            $reference = (string) ($event->data[$key] ?? '');
            if ($reference !== '' && ($payment = $repo->findByProviderReference($provider, $reference)) !== null) {
                return $payment;
            }
        }

        return null;
    }

    private static function createUseCase(): ProcessWebhookUseCase
    {
        $ase = AseManager::getInstance();
        return new ProcessWebhookUseCase(
            $ase->orderRepo,
            $ase->subscriptionRepo,
            $ase->invoiceRepo,
            $ase->logRepo,
            $ase->planPriceRepo,
            $ase->planRepo,
            $ase->gatewayRepo
        );
    }

    public static function registerAdapter(string $provider, string $adapterClass): void
    {
        self::$adapters[strtolower(trim($provider))] = $adapterClass;
    }
}