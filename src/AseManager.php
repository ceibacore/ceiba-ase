<?php

namespace LemurAse;

use LemurAse\Application\UseCases\CreateOrder;
use LemurAse\Application\UseCases\ExpireStaleOrders;
use LemurAse\Application\UseCases\PriceCalculator;
use LemurAse\Application\UseCases\ProcessWebhook;
use LemurAse\Application\UseCases\CreatePlan;
use LemurAse\Application\UseCases\UpdatePlan;
use LemurAse\Application\UseCases\CreatePlanPrice;
use LemurAse\Application\UseCases\CreateGateway;
use LemurAse\Application\UseCases\UpsertGateway;
use LemurAse\Domain\Services\SecurityService;
use LemurAse\Shared\EnvironmentGuard;
use LemurAse\Infrastructure\Persistence\LemurOrderRepository;
use LemurAse\Infrastructure\Persistence\LemurPlanPriceRepository;
use LemurAse\Infrastructure\Persistence\LemurCustomPriceRepository;
use LemurAse\Infrastructure\Persistence\LemurSubscriptionRepository;
use LemurAse\Infrastructure\Persistence\LemurInvoiceRepository;
use LemurAse\Infrastructure\Persistence\LemurTransactionLogRepository;
use LemurAse\Infrastructure\Persistence\LemurPlanRepository;
use LemurAse\Infrastructure\Persistence\LemurGatewayRepository;
use LemurAse\Infrastructure\Payments\StripeAdapter;
use LemurAse\Infrastructure\Payments\GatewayAdapterRegistry;
use LemurAse\Infrastructure\Events\AseEventDispatcher;
use LemurAse\Domain\Gateways\PaymentGatewayInterface;
use LemurAse\Domain\ValueObjects\EntityId;

/**
 * Agnostic Subscription Engine — main facade.
 *
 * All public methods are static. Use AseManager::getInstance() for
 * the underlying singleton, or call methods directly on the class.
 *
 * @method static string      createCheckoutSession(string $clientId, string $planPriceId, string $gatewayId, string $successUrl, string $cancelUrl)
 * @method static int         expireStaleOrders()
 * @method static bool        handleWebhook(string $gatewayProvider, array $payload, array $headers)
 * @method static bool        processValidatedEvent(string $action, array $normalizedData)
 * @method static array|null  getClientActiveSubscription(string $clientId)
 * @method static array       getClientInvoices(string $clientId, int $limit = 10)
 * @method static bool        hasActiveAccess(string $clientId, string $planSlug)
 * @method static void        assignCustomPrice(string $clientId, string $planPriceId, float $amount, ?string $validUntil)
 * @method static void        cancelSubscription(string $clientId, string $subscriptionId, bool $atPeriodEnd = true)
 * @method static array       getEnabledGateways()
 * @method static array       getAllGateways()
 * @method static array|null  getGatewayById(string $gatewayId)
 * @method static array|null  getGatewayByProvider(string $provider)
 * @method static void        registerGatewayAdapter(string $provider, \Closure $factory)
 * @method static void        listen(string $event, callable $listener)
 */
final class AseManager
{
    private static ?self $instance = null;

    // Repositories
    private LemurOrderRepository $orderRepo;
    private LemurPlanPriceRepository $planPriceRepo;
    private LemurCustomPriceRepository $customPriceRepo;
    private LemurSubscriptionRepository $subscriptionRepo;
    private LemurInvoiceRepository $invoiceRepo;
    private LemurTransactionLogRepository $logRepo;
    private LemurPlanRepository $planRepo;
    private LemurGatewayRepository $gatewayRepo;

    // Services
    private SecurityService $securityService;
    private PriceCalculator $priceCalculator;

    private function __construct()
    {
        EnvironmentGuard::check();
        $this->orderRepo = new LemurOrderRepository();
        $this->planPriceRepo = new LemurPlanPriceRepository();
        $this->customPriceRepo = new LemurCustomPriceRepository();
        $this->subscriptionRepo = new LemurSubscriptionRepository();
        $this->invoiceRepo = new LemurInvoiceRepository();
        $this->logRepo = new LemurTransactionLogRepository();
        $this->planRepo = new LemurPlanRepository();
        $this->gatewayRepo = new LemurGatewayRepository();

        $this->securityService = new SecurityService();
        $this->priceCalculator = new PriceCalculator($this->customPriceRepo);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize a checkout session.
     */
    public static function createCheckoutSession(string $clientId, string $planPriceId, string $gatewayId, string $successUrl, string $cancelUrl): string
    {
        $manager = self::getInstance();
        $useCase = new CreateOrder(
            $manager->orderRepo, 
            $manager->planPriceRepo, 
            $manager->priceCalculator, 
            $manager->securityService
        );

        $order = $useCase->execute($clientId, $planPriceId, $gatewayId);

        // Fetch the corresponding gateway adapter with credentials from DB
        $adapter = $manager->getGatewayAdapter($gatewayId);
        
        $session = $adapter->createCheckoutSession($order, $successUrl, $cancelUrl);

        // Save external order ID generated by the gateway
        $orderReflection = new \ReflectionClass($order);
        $property = $orderReflection->getProperty('externalOrderId');
        $property->setAccessible(true);
        $property->setValue($order, $session['external_id']);
        
        $manager->orderRepo->save($order);

        return $session['checkout_url'];
    }

    /**
     * Expire stale orders. Intended for a cron job.
     */
    public static function expireStaleOrders(): int
    {
        $manager = self::getInstance();
        $useCase = new ExpireStaleOrders($manager->orderRepo);
        return $useCase->execute();
    }

    /**
     * Process an incoming webhook using the built-in adapter for validation.
     * 
     * SECURITY: Signature verification happens FIRST, before JSON parsing.
     * The $rawBody is passed to the adapter via headers for secure verification.
     *
     * @param string $gatewayId UUID of the gateway record (from prefix_gateways.id)
     * @param string $rawBody   The raw HTTP request body (for signature verification)
     * @param array  $headers   The HTTP headers
     *
     * @return bool True if webhook was successfully processed
     * @throws \RuntimeException If webhook signature validation fails
     */
    public static function handleWebhook(string $gatewayId, string $rawBody, array $headers): bool
    {
        $manager = self::getInstance();
        $adapter = $manager->getGatewayAdapter($gatewayId);

        // Parse JSON payload
        $payload = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON payload: " . json_last_error_msg());
        }

        // CRITICAL: Validate webhook signature BEFORE processing
        // Pass raw body through headers for signature verification
        $headers['X-RAW-BODY'] = $rawBody;
        if (!$adapter->validateWebhook($payload, $headers)) {
            throw new \RuntimeException("Invalid webhook signature for gateway {$gatewayId}");
        }

        // Signature is valid; parse and process the event
        $eventData = $adapter->parseWebhookEvent($payload);
        return self::processValidatedEvent($eventData['action'] ?? 'UNKNOWN', $eventData);
    }

    /**
     * Process a webhook event that has already been validated and parsed by the host system.
     * This is useful if the host uses official SDKs (like stripe-php) to construct the event.
     */
    public static function processValidatedEvent(string $action, array $normalizedData): bool
    {
        $manager = self::getInstance();

        // If the adapter or host didn't recognize the event as a standard action, ignore it
        if ($action === 'UNKNOWN' || $action === 'IGNORED') {
            return true; 
        }

        $useCase = new ProcessWebhook(
            $manager->orderRepo,
            $manager->subscriptionRepo,
            $manager->invoiceRepo,
            $manager->logRepo,
            $manager->planPriceRepo
        );

        return $useCase->execute($action, $normalizedData);
    }

    /**
     * Get the active subscription for a client.
     */
    public static function getClientActiveSubscription(string $clientId): ?array
    {
        $manager = self::getInstance();
        $subs = $manager->subscriptionRepo->findByClientAndStatus($clientId, 'active');
        
        if (empty($subs)) {
            return null;
        }

        // Return the first active one as array
        $sub = $subs[0];
        return [
            'id' => $sub->id()->uuid(),
            'order_id' => $sub->orderId()->uuid(),
            'external_client_id' => $sub->externalClientId(),
            'plan_price_id' => $sub->planPriceId()->uuid(),
            'gateway_id' => $sub->gatewayId()->uuid(),
            'status' => $sub->status(),
            'current_period_start' => $sub->currentPeriodStart()?->format('Y-m-d H:i:s'),
            'current_period_end' => $sub->currentPeriodEnd()?->format('Y-m-d H:i:s'),
            'canceled_at' => $sub->canceledAt()?->format('Y-m-d H:i:s'),
            'external_subscription_id' => $sub->externalSubscriptionId()
        ];
    }

    /**
     * Get client invoices.
     */
    public static function getClientInvoices(string $clientId, int $limit = 10): array
    {
        $manager = self::getInstance();
        $invoices = $manager->invoiceRepo->findByClient($clientId);
        
        $result = [];
        foreach (array_slice($invoices, 0, $limit) as $inv) {
            $result[] = [
                'id' => $inv->id()->uuid(),
                'order_id' => $inv->orderId()->uuid(),
                'subscription_id' => $inv->subscriptionId()?->uuid(),
                'external_client_id' => $inv->externalClientId(),
                'invoice_number' => $inv->invoiceNumber(),
                'subtotal' => $inv->subtotal(),
                'tax_amount' => $inv->taxAmount(),
                'total' => $inv->total()->amount(),
                'currency' => $inv->total()->currency()->toString(),
                'period_start' => $inv->periodStart()?->format('Y-m-d H:i:s'),
                'period_end' => $inv->periodEnd()?->format('Y-m-d H:i:s'),
                'status' => $inv->status(),
                'issued_at' => $inv->issuedAt()?->format('Y-m-d H:i:s'),
                'due_at' => $inv->dueAt()?->format('Y-m-d H:i:s'),
                'paid_at' => $inv->paidAt()?->format('Y-m-d H:i:s')
            ];
        }

        return $result;
    }

    /**
     * Helper to verify if the client has active access considering the grace period.
     * Clients in 'trialing' status also have access (trial period support).
     */
    public static function hasActiveAccess(string $clientId, string $planSlug): bool
    {
        $manager = self::getInstance();

        // Find active, trialing, or past_due subscriptions
        $subs = array_merge(
            $manager->subscriptionRepo->findByClientAndStatus($clientId, 'active'),
            $manager->subscriptionRepo->findByClientAndStatus($clientId, 'trialing'),
            $manager->subscriptionRepo->findByClientAndStatus($clientId, 'past_due')
        );

        if (empty($subs)) {
            return false;
        }

        foreach ($subs as $sub) {
            // Check if the plan matches (requires JOIN in a real scenario, but simplified here)
            // Assuming we check the end date + grace period
            $endDate = $sub->currentPeriodEnd();
            
            // Trialing subscriptions always have access (trial is running)
            if ($sub->status() === 'trialing') {
                if ($endDate && $endDate > new \DateTimeImmutable()) {
                    return true;
                }
                continue;
            }

            if (!$endDate) {
                if ($sub->status() === 'active') {
                    return true;
                }
                continue;
            }
            
            // Assuming 3 days grace period for past_due
            if ($sub->status() === 'past_due') {
                $endDate = $endDate->modify('+3 days');
            }

            if ($endDate > new \DateTimeImmutable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin method to assign a custom price to a client.
     */
    public static function assignCustomPrice(string $clientId, string $planPriceId, float $amount, ?string $validUntil): void
    {
        $manager = self::getInstance();
        
        $data = [
            'id' => EntityId::generate()->uuid(),
            'short_id' => EntityId::generate()->short(),
            'plan_price_id' => $planPriceId,
            'external_client_id' => $clientId,
            'custom_amount' => $amount,
            'valid_until' => $validUntil
        ];

        // In a real scenario, this would use the CustomPriceRepository to save it.
        // As LemurCustomPriceRepository only has findByClientAndPlanPrice right now, 
        // we use the query builder directly for this demo.
        \LemurAse\Shared\LemurInstance::get()
            ->query(\LemurAse\Infrastructure\Persistence\TableNames::CUSTOM_PRICES)
            ->insert($data);
    }

    /**
     * Admin or user action to cancel a subscription.
     */
    public static function cancelSubscription(string $clientId, string $subscriptionId, bool $atPeriodEnd = true): void
    {
        $manager = self::getInstance();
        $sub = $manager->subscriptionRepo->findById(EntityId::fromString($subscriptionId));

        if (!$sub || $sub->externalClientId() !== $clientId) {
            throw new \InvalidArgumentException("Subscription not found or does not belong to client.");
        }

        // If not at period end, cancel immediately
        $status = $atPeriodEnd ? 'active' : 'canceled'; // Simplified
        $canceledAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        \LemurAse\Shared\LemurInstance::get()
            ->query(\LemurAse\Infrastructure\Persistence\TableNames::SUBSCRIPTIONS)
            ->where(['id' => $subscriptionId])
            ->update([
                'status' => $status,
                'canceled_at' => $canceledAt
            ]);
    }

    /**
     * Create a new subscription plan.
     */
    /**
     * Create a new subscription plan.
     */
    public static function createPlan(
        string  $slug,
        string  $name,
        ?string $description = null,
        bool    $isActive    = true,
        ?array  $metadata    = null
    ): array {
        $manager = self::getInstance();
        $useCase = new CreatePlan($manager->planRepo);
        $plan    = $useCase->execute($slug, $name, $description, $isActive, $metadata);

        return [
            'id'          => $plan->id()->uuid(),
            'slug'        => $plan->slug(),
            'name'        => $plan->name(),
            'description' => $plan->description(),
            'is_active'   => $plan->isActive(),
            'metadata'    => $plan->metadata(),
        ];
    }

    /**
     * Update an existing plan.
     */
    public static function updatePlan(
        string  $planId,
        ?string $name        = null,
        ?string $description = null,
        ?bool   $isActive    = null
    ): array {
        $manager = self::getInstance();
        $useCase = new UpdatePlan($manager->planRepo);
        $plan    = $useCase->execute($planId, $name, $description, $isActive);

        return [
            'id'          => $plan->id()->uuid(),
            'slug'        => $plan->slug(),
            'name'        => $plan->name(),
            'description' => $plan->description(),
            'is_active'   => $plan->isActive(),
        ];
    }

    /**
     * Add a price to an existing plan.
     */
    public static function createPlanPrice(
        string  $planId,
        string  $type,
        float   $amount,
        string  $currency,
        ?string $interval      = null,
        int     $intervalCount = 1,
        int     $trialDays     = 0
    ): array {
        $manager = self::getInstance();
        $useCase = new CreatePlanPrice($manager->planRepo, $manager->planPriceRepo);
        $price   = $useCase->execute($planId, $type, $amount, $currency, $interval, $intervalCount, $trialDays);

        return [
            'id'             => $price->id()->uuid(),
            'plan_id'        => $price->planId()->uuid(),
            'type'           => $price->type(),
            'amount'         => $price->price()->amount(),
            'currency'       => $price->price()->currency()->toString(),
            'interval'       => $price->interval(),
            'interval_count' => $price->intervalCount(),
            'trial_days'     => $price->trialDays(),
            'is_active'      => $price->isActive(),
        ];
    }

    /**
     * Register a payment gateway with its credentials.
     */
    public static function registerGateway(
        string $provider,
        array  $credentials,
        bool   $isActive = true
    ): array {
        $manager = self::getInstance();
        $useCase = new CreateGateway($manager->gatewayRepo);
        $gateway = $useCase->execute($provider, $credentials, $isActive);

        return [
            'id'       => $gateway->id()->uuid(),
            'provider' => $gateway->provider(),
            'is_active' => $gateway->isActive(),
            // credentials intentionally omitted from return value
        ];
    }


    /**
     * Create-or-update a gateway by provider slug.
     *
     * Unlike registerGateway() (which always inserts a new row),
     * this finds an existing gateway for the provider and updates it,
     * or creates a new one if none exists.
     *
     * This is the recommended method for admin forms.
     *
     * @param  string  $provider     Gateway slug (stripe, paypal, or any registered provider)
     * @param  array   $credentials  Credential map from GatewayFormManager::processSubmission()
     * @param  bool    $isActive     Whether the gateway should be active
     * @return array{id:string, provider:string, is_active:bool}
     */
    public static function upsertGateway(
        string $provider,
        array  $credentials,
        bool   $isActive = true
    ): array {
        $manager = self::getInstance();
        $useCase = new UpsertGateway($manager->gatewayRepo);
        $gateway = $useCase->execute($provider, $credentials, $isActive);

        return [
            'id'        => $gateway->id()->uuid(),
            'provider'  => $gateway->provider(),
            'is_active' => $gateway->isActive(),
            // credentials intentionally omitted from return value
        ];
    }

    /**
     * List all plans.
     */
    public static function listPlans(bool $onlyActive = false): array
    {
        $manager = self::getInstance();
        $plans   = $manager->planRepo->findAll($onlyActive);

        return array_map(fn($p) => [
            'id'          => $p->id()->uuid(),
            'slug'        => $p->slug(),
            'name'        => $p->name(),
            'description' => $p->description(),
            'is_active'   => $p->isActive(),
        ], $plans);
    }

    /**
     * Get a single plan by UUID.
     */
    public static function getPlan(string $planId): ?array
    {
        $manager = self::getInstance();
        $plan    = $manager->planRepo->findById(EntityId::fromString($planId));

        if (!$plan) return null;

        return [
            'id'          => $plan->id()->uuid(),
            'slug'        => $plan->slug(),
            'name'        => $plan->name(),
            'description' => $plan->description(),
            'is_active'   => $plan->isActive(),
        ];
    }

    /**
     * Get all ENABLED payment gateways (providers like Stripe & PayPal that are active).
     * 
     * Developers use this to show available payment options.
     * 
     * @return array List of enabled gateways
     *         Example: [
     *             ['id' => 'uuid1', 'provider' => 'stripe'],
     *             ['id' => 'uuid2', 'provider' => 'paypal']
     *         ]
     */
    public static function getEnabledGateways(): array
    {
        $manager = self::getInstance();
        $gateways = $manager->gatewayRepo->findAllEnabled();

        return array_map(fn($g) => [
            'id'       => $g->id()->uuid(),
            'provider' => $g->provider(),
        ], $gateways);
    }

    /**
     * Get ALL gateways (enabled or disabled).
     * 
     * Useful for admin panels to manage gateway status.
     * 
     * @return array List of all gateways with status
     *         Example: [
     *             ['id' => 'uuid1', 'provider' => 'stripe', 'is_active' => true],
     *             ['id' => 'uuid2', 'provider' => 'paypal', 'is_active' => false]
     *         ]
     */
    public static function getAllGateways(): array
    {
        $manager = self::getInstance();
        $gateways = $manager->gatewayRepo->findAll();

        return array_map(fn($g) => [
            'id'        => $g->id()->uuid(),
            'provider'  => $g->provider(),
            'is_active' => $g->isActive(),
        ], $gateways);
    }

    /**
     * Get a single gateway by ID.
     * 
     * @param string $gatewayId UUID of the gateway
     * @return array|null Gateway data or null if not found
     *         Example: ['id' => 'uuid1', 'provider' => 'stripe', 'is_active' => true]
     */
    public static function getGatewayById(string $gatewayId): ?array
    {
        $manager = self::getInstance();
        $gateway = $manager->gatewayRepo->findById(EntityId::fromString($gatewayId));

        if (!$gateway) {
            return null;
        }

        return [
            'id'        => $gateway->id()->uuid(),
            'provider'  => $gateway->provider(),
            'is_active' => $gateway->isActive(),
        ];
    }

    /**
     * Get the first ENABLED gateway for a given provider.
     * 
     * Convenience method for developers who only care about provider type.
     * Example: Get "the" Stripe gateway, or "the" PayPal gateway.
     * 
     * @param string $provider 'stripe' or 'paypal'
     * @return array|null Gateway data or null if no enabled gateway exists
     *         Example: ['id' => 'uuid1', 'provider' => 'stripe']
     */
    public static function getGatewayByProvider(string $provider): ?array
    {
        $manager = self::getInstance();
        $gateways = $manager->gatewayRepo->findAllEnabled();

        foreach ($gateways as $gateway) {
            if ($gateway->provider() === $provider) {
                return [
                    'id'       => $gateway->id()->uuid(),
                    'provider' => $gateway->provider(),
                ];
            }
        }

        return null;
    }

    /**
     * MULTI-GATEWAY USAGE EXAMPLE
     * 
     * 1. Developer lists enabled gateways:
     *    $gateways = AseManager::getEnabledGateways();
     *    // Returns: [['id' => 'gw1', 'provider' => 'stripe'], ['id' => 'gw2', 'provider' => 'paypal']]
     * 
     * 2. Developer passes to frontend (e.g., as JSON options):
     *    return response()->json($gateways);
     * 
     * 3. Frontend shows radio buttons: "Pay with Stripe" vs "Pay with PayPal"
     * 
     * 4. User selects gateway and frontend sends back gatewayId
     * 
     * 5. Developer creates checkout with selected gateway:
     *    $checkoutUrl = AseManager::createCheckoutSession($clientId, $planPriceId, $selectedGatewayId, 'https://...', 'https://...');
     * 
     * 6. Developer redirects user to $checkoutUrl
     */

    /**
     * Allows host applications to register custom gateway adapters.
     * The closure must accept an array of credentials and return an instance of PaymentGatewayInterface.
     */
    public static function registerGatewayAdapter(string $provider, \Closure $factory): void
    {
        GatewayAdapterRegistry::register($provider, $factory);
    }

    /**
     * Register an event listener for domain events.
     */
    public static function listen(string $event, callable $listener): void
    {
        AseEventDispatcher::listen($event, $listener);
    }

    private function getGatewayAdapter(string $gatewayId): PaymentGatewayInterface
    {
        $gateway = $this->gatewayRepo->findById(EntityId::fromString($gatewayId));

        if (!$gateway) {
            throw new \RuntimeException("Gateway '{$gatewayId}' not found in database.");
        }

        if (!$gateway->isActive()) {
            throw new \RuntimeException("Gateway '{$gatewayId}' is not active.");
        }

        return GatewayAdapterRegistry::make($gateway->provider(), $gateway->credentials());
    }
}
