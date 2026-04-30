<?php

namespace LemurAse\Domain\Repositories;

use LemurAse\Domain\Entities\Invoice;
use LemurAse\Domain\ValueObjects\EntityId;

interface InvoiceRepositoryInterface
{
    public function save(Invoice $invoice): void;
    public function findById(EntityId $id): ?Invoice;
    public function getNextInvoiceNumber(string $externalClientId): string;
}
