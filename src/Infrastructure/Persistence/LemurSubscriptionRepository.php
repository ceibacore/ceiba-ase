<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\Subscription;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\Repositories\SubscriptionRepositoryInterface;
use LemurAse\Shared\Infrastructure\LemurInstance;

final class LemurSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function save(Subscription $subscription): void
    {
        $db = LemurInstance::get();
        $exists = $db->query(TableNames::SUBSCRIPTIONS)->where(['id' => $subscription->id()->uuid()])->first();

        $data = [
            'id' => $subscription->id()->uuid(),
            'short_id' => $subscription->id()->short(),
            'order_id' => $subscription->orderId()->uuid(),
            'external_client_id' => $subscription->externalClientId(),
            'plan_price_id' => $subscription->planPriceId()->uuid(),
            'gateway_id' => $subscription->gatewayId()->uuid(),
            'external_subscription_id' => $subscription->externalSubscriptionId(),
            'status' => $subscription->status(),
            'current_period_start' => $subscription->currentPeriodStart()?->format('Y-m-d H:i:s'),
            'current_period_end' => $subscription->currentPeriodEnd()?->format('Y-m-d H:i:s'),
            'canceled_at' => $subscription->canceledAt()?->format('Y-m-d H:i:s'),
        ];

        if ($exists) {
            $db->query(TableNames::SUBSCRIPTIONS)->where(['id' => $subscription->id()->uuid()])->update($data);
        } else {
            $db->query(TableNames::SUBSCRIPTIONS)->insert($data);
        }
    }

    public function findById(EntityId $id): ?Subscription
    {
        $db = LemurInstance::get();
        $row = $db->query(TableNames::SUBSCRIPTIONS)->where(['id' => $id->uuid()])->first();
        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByExternalId(string $externalId): ?Subscription
    {
        $db = LemurInstance::get();
        $row = $db->query(TableNames::SUBSCRIPTIONS)->where(['external_subscription_id' => $externalId])->first();
        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByClientAndStatus(string $clientId, string $status): array
    {
        $db = LemurInstance::get();
        $rows = $db->query(TableNames::SUBSCRIPTIONS)
            ->where([
                'external_client_id' => $clientId,
                'status' => $status
            ])
            ->get();
            
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->mapToEntity($row);
        }
        return $entities;
    }

    public function findByOrderId(EntityId $orderId): ?Subscription
    {
        $db = LemurInstance::get();
        $row = $db->query(TableNames::SUBSCRIPTIONS)
            ->where(["order_id" => $orderId->uuid()])
            ->first();

        return $row ? $this->mapToEntity($row) : null;
    }

    private function mapToEntity(array $row): Subscription
    {
        return new Subscription(
            EntityId::fromString($row['id']),
            EntityId::fromString($row['order_id']),
            $row['external_client_id'],
            EntityId::fromString($row['plan_price_id']),
            EntityId::fromString($row['gateway_id']),
            $row['status'],
            $row['current_period_start'] ? new \DateTimeImmutable($row['current_period_start']) : null,
            $row['current_period_end'] ? new \DateTimeImmutable($row['current_period_end']) : null,
            $row['canceled_at'] ? new \DateTimeImmutable($row['canceled_at']) : null,
            $row['external_subscription_id']
        );
    }
}
