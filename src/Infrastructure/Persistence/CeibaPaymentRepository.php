<?php

declare(strict_types=1);

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\Payment;
use LemurAse\Domain\Repositories\PaymentRepositoryInterface;
use LemurAse\Domain\ValueObjects\Currency;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\MinorUnits;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Shared\Infrastructure\CeibaInstance;

final class CeibaPaymentRepository implements PaymentRepositoryInterface
{
    public function save(Payment $payment): void
    {
        $db = CeibaInstance::get();
        $now = date('Y-m-d H:i:s');
        $data = [
            'context' => $payment->context(),
            'reference' => $payment->reference(),
            'account_ref' => $payment->accountRef(),
            'external_client_id' => $payment->externalClientId(),
            'gateway_id' => $payment->gatewayId()->uuid(),
            'provider' => $payment->provider(),
            'amount_minor' => $payment->amountMinor(),
            'currency' => $payment->currency(),
            'description' => $payment->description(),
            'status' => $payment->status(),
            'external_id' => $payment->externalId(),
            'external_payment_id' => $payment->externalPaymentId(),
            'refunded_minor' => $payment->refundedMinor(),
            'metadata' => $payment->metadata() === [] ? null : json_encode($payment->metadata(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'paid_at' => $payment->paidAt()?->format('Y-m-d H:i:s'),
            'updated_at' => $now,
        ];

        if ($db->query(TableNames::PAYMENTS)->where(['id' => $payment->id()->uuid()])->exists()) {
            $db->query(TableNames::PAYMENTS)->where(['id' => $payment->id()->uuid()])->update($data);
            return;
        }
        $db->query(TableNames::PAYMENTS)->insert($data + [
            'id' => $payment->id()->uuid(),
            'created_at' => $payment->createdAt()?->format('Y-m-d H:i:s') ?? $now,
        ]);
    }

    public function findById(EntityId $id): ?Payment
    {
        $row = CeibaInstance::get()->query(TableNames::PAYMENTS)->where(['id' => $id->uuid()])->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByProviderReference(string $provider, string $reference): ?Payment
    {
        if ($reference === '') {
            return null;
        }
        $db = CeibaInstance::get();
        $row = $db->query(TableNames::PAYMENTS)->where(['provider' => $provider, 'external_payment_id' => $reference])->first()
            ?: $db->query(TableNames::PAYMENTS)->where(['provider' => $provider, 'external_id' => $reference])->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function findByReference(string $context, string $reference): array
    {
        $rows = CeibaInstance::get()->query(TableNames::PAYMENTS)
            ->where(['context' => $context, 'reference' => $reference])
            ->orderBy('created_at', 'DESC')
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows ?? []);
    }

    public function findStalePending(\DateTimeImmutable $before, int $limit): array
    {
        $rows = CeibaInstance::get()->query(TableNames::PAYMENTS)
            ->where('status', '=', 'pending')
            ->where('created_at', '<', $before->format('Y-m-d H:i:s'))
            ->orderBy('created_at', 'ASC')
            ->limit(max(1, $limit))
            ->get();

        return array_map(fn (array $row) => $this->hydrate($row), $rows ?? []);
    }

    private function hydrate(array $row): Payment
    {
        $currency = (string) $row['currency'];
        $metadata = is_string($row['metadata'] ?? null) ? json_decode($row['metadata'], true) : ($row['metadata'] ?? null);

        return new Payment(
            EntityId::fromString((string) $row['id']),
            (string) $row['context'],
            (string) $row['reference'],
            ($row['account_ref'] ?? '') !== '' ? (string) $row['account_ref'] : null,
            (string) $row['external_client_id'],
            EntityId::fromString((string) $row['gateway_id']),
            (string) $row['provider'],
            Money::create(MinorUnits::fromMinor((int) $row['amount_minor'], $currency), Currency::fromString($currency)),
            (string) $row['description'],
            (string) $row['status'],
            $row['external_id'] !== null ? (string) $row['external_id'] : null,
            $row['external_payment_id'] !== null ? (string) $row['external_payment_id'] : null,
            (int) $row['refunded_minor'],
            is_array($metadata) ? $metadata : [],
            !empty($row['paid_at']) ? new \DateTimeImmutable((string) $row['paid_at']) : null,
            !empty($row['created_at']) ? new \DateTimeImmutable((string) $row['created_at']) : null,
        );
    }
}
