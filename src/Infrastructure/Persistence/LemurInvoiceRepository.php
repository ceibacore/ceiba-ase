<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Domain\Entities\Invoice;
use LemurAse\Domain\ValueObjects\EntityId;
use LemurAse\Domain\ValueObjects\Money;
use LemurAse\Domain\ValueObjects\Currency;
use LemurAse\Domain\Repositories\InvoiceRepositoryInterface;
use LemurAse\Shared\Infrastructure\LemurInstance;

final class LemurInvoiceRepository implements InvoiceRepositoryInterface
{
    public function save(Invoice $invoice): void
    {
        $db = LemurInstance::get();
        $data = [
            'id' => $invoice->id()->uuid(),
            'short_id' => $invoice->id()->short(),
            'order_id' => $invoice->orderId()->uuid(),
            'subscription_id' => $invoice->subscriptionId()?->uuid(),
            'external_client_id' => $invoice->externalClientId(),
            'invoice_number' => $invoice->invoiceNumber(),
            'subtotal' => $invoice->subtotal(),
            'tax_amount' => $invoice->taxAmount(),
            'total' => $invoice->total()->amount(),
            'currency' => $invoice->total()->currency()->toString(),
            'period_start' => $invoice->periodStart()?->format('Y-m-d H:i:s'),
            'period_end' => $invoice->periodEnd()?->format('Y-m-d H:i:s'),
            'status' => $invoice->status(),
            'issued_at' => $invoice->issuedAt()?->format('Y-m-d H:i:s'),
            'due_at' => $invoice->dueAt()?->format('Y-m-d H:i:s'),
            'paid_at' => $invoice->paidAt()?->format('Y-m-d H:i:s'),
            'plan_snapshot' => $invoice->planSnapshot() ? json_encode($invoice->planSnapshot()) : null,
        ];

        $db->query(TableNames::INVOICES)->insert($data);
    }

    public function findById(EntityId $id): ?Invoice
    {
        $db = LemurInstance::get();
        $row = $db->query(TableNames::INVOICES)->where(['id' => $id->uuid()])->first();
        return $row ? $this->mapToEntity($row) : null;
    }

    public function getNextInvoiceNumber(string $externalClientId): string
    {
        $db = LemurInstance::get();
        $row = $db->query(TableNames::INVOICES)
            ->select('COUNT(*) as total')
            ->where(['external_client_id' => $externalClientId])
            ->first();
        
        $count = (int) ($row['total'] ?? 0);
        $next = $count + 1;
        return "INV-" . strtoupper(substr($externalClientId, 0, 5)) . "-" . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    public function findByClient(string $clientId): array
    {
        $db = LemurInstance::get();
        $rows = $db->query(TableNames::INVOICES)
            ->where(['external_client_id' => $clientId])
            ->orderBy('issued_at', 'DESC')
            ->get();
            
        $entities = [];
        foreach ($rows as $row) {
            $entities[] = $this->mapToEntity($row);
        }
        return $entities;
    }

    private function mapToEntity(array $row): Invoice
    {
        return new Invoice(
            EntityId::fromString($row['id']),
            EntityId::fromString($row['order_id']),
            $row['external_client_id'],
            $row['invoice_number'],
            Money::create((float)$row['total'], Currency::fromString($row['currency'])),
            $row['status'],
            $row['subscription_id'] ? EntityId::fromString($row['subscription_id']) : null,
            (float)$row['subtotal'],
            (float)$row['tax_amount'],
            $row['period_start'] ? new \DateTimeImmutable($row['period_start']) : null,
            $row['period_end'] ? new \DateTimeImmutable($row['period_end']) : null,
            $row['issued_at'] ? new \DateTimeImmutable($row['issued_at']) : null,
            $row['due_at'] ? new \DateTimeImmutable($row['due_at']) : null,
            $row['paid_at'] ? new \DateTimeImmutable($row['paid_at']) : null,
            (!empty($row['plan_snapshot'])) ? json_decode($row['plan_snapshot'], true) : null
        );
    }
}
