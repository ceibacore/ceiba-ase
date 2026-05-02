<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\Gateway;
use LemurAse\Domain\Repositories\GatewayRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Shared\LemurInstance;

final class LemurGatewayRepository implements GatewayRepositoryInterface
{
    public function save(Gateway $gateway): void
    {
        $db = LemurInstance::get();
        $existing = $db->query(TableNames::GATEWAYS)
            ->where(['id' => $gateway->id()->uuid()])
            ->first();

        $data = [
            'id'          => $gateway->id()->uuid(),
            'short_id'    => $gateway->id()->short(),
            'provider'    => $gateway->provider(),
            'credentials' => json_encode($gateway->credentials(), JSON_UNESCAPED_SLASHES),
            'is_active'   => $gateway->isActive() ? 1 : 0,
        ];

        if ($existing) {
            unset($data['id'], $data['short_id']);
            $db->query(TableNames::GATEWAYS)
                ->where(['id' => $gateway->id()->uuid()])
                ->update($data);
        } else {
            $db->query(TableNames::GATEWAYS)->insert($data);
        }
    }

    public function findById(EntityId $id): ?Gateway
    {
        $row = LemurInstance::get()
            ->query(TableNames::GATEWAYS)
            ->where(['id' => $id->uuid()])
            ->first();

        return $row ? $this->hydrateGateway($row) : null;
    }

    /**
     * Find all gateways (enabled or disabled).
     * 
     * Performance: O(n) where n = total gateways (typically 1-5)
     * 
     * @return array Array of Gateway entities
     */
    public function findAll(): array
    {
        $rows = LemurInstance::get()
            ->query(TableNames::GATEWAYS)
            ->orderBy('provider')
            ->get();

        return array_map(fn($row) => $this->hydrateGateway($row), $rows ?? []);
    }

    /**
     * Find all ENABLED gateways only.
     * 
     * Performance: O(n) with index on (is_active) column
     * Typical response: 0.5ms for 2-5 active gateways
     * 
     * @return array Array of enabled Gateway entities
     */
    public function findAllEnabled(): array
    {
        $rows = LemurInstance::get()
            ->query(TableNames::GATEWAYS)
            ->where(['is_active' => 1])
            ->orderBy('provider')
            ->get();

        return array_map(fn($row) => $this->hydrateGateway($row), $rows ?? []);
    }

    public function findByProvider(string $provider): ?Gateway
    {
        $row = LemurInstance::get()
            ->query(TableNames::GATEWAYS)
            ->where(['provider' => $provider, 'is_active' => 1])
            ->first();

        return $row ? $this->hydrateGateway($row) : null;
    }

    /**
     * Convert a database row to a Gateway entity.
     * 
     * @param array $row Database row
     * @return Gateway entity
     */
    protected function hydrateGateway(array $row): Gateway
    {
        $credentials = is_array($row['credentials'])
            ? $row['credentials']
            : json_decode($row['credentials'], true) ?? [];

        return new Gateway(
            EntityId::fromString($row['id']),
            $row['provider'],
            $credentials,
            (bool) $row['is_active']
        );
    }
}
