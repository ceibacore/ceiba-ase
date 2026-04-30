<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\Services\SecurityService;
use LemurAse\Domain\Repositories\OrderRepositoryInterface;
use LemurAse\Domain\Repositories\PlanPriceRepositoryInterface;

final class CreateOrder
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo,
        private readonly PlanPriceRepositoryInterface $planPriceRepo,
        private readonly PriceCalculator $priceCalculator,
        private readonly SecurityService $securityService
    ) {}

    public function execute(string $clientId, string $planPriceId, string $gatewayId): Order
    {
        $planPrice = $this->planPriceRepo->findById(EntityId::fromString($planPriceId));
        if (!$planPrice) {
            throw new \InvalidArgumentException("Plan Price not found.");
        }

        $amount = $this->priceCalculator->calculate($clientId, $planPrice);

        $dataToHash = [
            'client_id' => $clientId,
            'plan_price_id' => $planPriceId,
            'amount' => $amount->amount(),
            'currency' => $amount->currency()->toString(),
            'timestamp' => time()
        ];

        $hash = $this->securityService->generateHash($dataToHash);

        $order = new Order(
            EntityId::generate(),
            $clientId,
            $planPrice->id(),
            EntityId::fromString($gatewayId),
            $amount,
            $hash,
            'pending',
            null,
            (new \DateTimeImmutable())->modify('+24 hours')
        );

        $this->orderRepo->save($order);

        return $order;
    }
}
