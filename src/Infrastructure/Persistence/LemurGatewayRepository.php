<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\Gateway;
use LemurAse\Domain\Repositories\GatewayRepositoryInterface;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Infrastructure\Security\CredentialEncryptor;
use LemurAse\Shared\Infrastructure\LemurInstance;

final class LemurGatewayRepository implements GatewayRepositoryInterface
{
    private ?CredentialEncryptor $encryptor;

    public function __construct()
    {
        // Encryptor is optional — if GATEWAY_ENCRYPTION_KEY is not set,
        // credentials are stored as plaintext JSON (legacy / dev mode).
        // Production deployments MUST set GATEWAY_ENCRYPTION_KEY.
        $key = (string) getenv('GATEWAY_ENCRYPTION_KEY');
        $this->encryptor = $key !== '' ? new CredentialEncryptor($key) : null;
    }

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
            'credentials' => $this->encodeCredentials($gateway->credentials()),
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

    public function findAll(): array
    {
        $rows = LemurInstance::get()
            ->query(TableNames::GATEWAYS)
            ->orderBy('provider')
            ->get();

        return array_map(fn($row) => $this->hydrateGateway($row), $rows ?? []);
    }

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

    // ── Encryption helpers ────────────────────────────────────────────────────

    private function encodeCredentials(array $credentials): string
    {
        if ($this->encryptor !== null) {
            return $this->encryptor->encrypt($credentials);
        }

        // Plaintext fallback (dev / no key configured)
        return json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decodeCredentials(mixed $stored): array
    {
        if (is_array($stored)) {
            // Some DB drivers auto-decode JSON columns
            return $stored;
        }

        $stored = (string) $stored;

        if ($this->encryptor !== null && $this->encryptor->isEncrypted($stored)) {
            return $this->encryptor->decrypt($stored);
        }

        // Plaintext JSON (legacy or dev mode)
        $decoded = json_decode($stored, associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    // ── Hydration ─────────────────────────────────────────────────────────────

    protected function hydrateGateway(array $row): Gateway
    {
        return new Gateway(
            EntityId::fromString($row['id']),
            $row['provider'],
            $this->decodeCredentials($row['credentials']),
            (bool) $row['is_active'],
        );
    }
}
