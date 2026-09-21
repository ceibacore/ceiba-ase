<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\Order;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;
use LemurAse\Domain\Repositories\OrderRepositoryInterface;
use LemurAse\Shared\Infrastructure\CeibaInstance;
use LemurAse\Infrastructure\Persistence\TableNames;

final class CeibaOrderRepository implements OrderRepositoryInterface
{
    public function save(Order $order): void
    {
        $db = CeibaInstance::get();
        $exists = $db->query(TableNames::ORDERS)->where(['id' => $order->id()->uuid()])->first();

        $data = [
            'id' => $order->id()->uuid(),
            'short_id' => $order->id()->short(),
            'external_client_id' => $order->externalClientId(),
            'plan_price_id' => $order->planPriceId()->uuid(),
            'gateway_id' => $order->gatewayId()->uuid(),
            'amount' => $order->amount()->amount(),
            'currency' => $order->amount()->currency()->toString(),
            'security_hash' => $order->securityHash(),
            'status' => $order->status(),
            'expires_at' => $order->expiresAt()?->format('Y-m-d H:i:s'),
            'external_order_id' => $order->externalOrderId()
        ];

        if ($exists) {
            $db->query(TableNames::ORDERS)->where(['id' => $order->id()->uuid()])->update($data);
        } else {
            $db->query(TableNames::ORDERS)->insert($data);
        }
    }

    public function findById(EntityId $id): ?Order
    {
        $db = CeibaInstance::get();
        $row = $db->query(TableNames::ORDERS)->where(['id' => $id->uuid()])->first();

        if (!$row) return null;

        return $this->mapToEntity($row);
    }

    public function findExpired(\DateTimeImmutable $now): array
    {
        $db = CeibaInstance::get();
        $rows = $db->query(TableNames::ORDERS)
            ->where(['status' => 'pending'])
            ->whereRaw('expires_at < ?', [$now->format('Y-m-d H:i:s')])
            ->get();

        return array_map([$this, 'mapToEntity'], $rows);
    }

    public function findByExternalTransactionId(string $externalTxId): ?Order
    {
        $db = CeibaInstance::get();
        $row = $db->query(TableNames::ORDERS)
            ->where(["external_order_id" => $externalTxId])
            ->first();

        return $row ? $this->mapToEntity($row) : null;
    }

    private function mapToEntity(array $row): Order
    {
        return new Order(
            EntityId::fromString($row['id']),
            $row['external_client_id'],
            EntityId::fromString($row['plan_price_id']),
            EntityId::fromString($row['gateway_id']),
            Money::create((float)$row['amount'], Currency::fromString($row['currency'])),
            $row['security_hash'],
            $row['status'],
            $row['custom_price_id'] ? EntityId::fromString($row['custom_price_id']) : null,
            $row['expires_at'] ? new \DateTimeImmutable($row['expires_at']) : null,
            $row['external_order_id']
        );
    }
}
