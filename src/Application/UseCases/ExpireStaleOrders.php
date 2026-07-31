<?php

namespace LemurAse\Application\UseCases;

use LemurAse\Domain\Repositories\OrderRepositoryInterface;

final class ExpireStaleOrders
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepo
    ) {}

    public function execute(): int
    {
        $now = new \DateTimeImmutable();
        $expiredOrders = $this->orderRepo->findExpired($now);
        
        $count = 0;
        foreach ($expiredOrders as $order) {
            $order->markAsExpired();
            $this->orderRepo->save($order);
            $count++;
        }

        return $count;
    }
}
