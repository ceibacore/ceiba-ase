<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaInvoiceRepository.php';

if (!class_exists('LemurAse\Infrastructure\Persistence\LemurInvoiceRepository', false)) {
    class_alias('LemurAse\Infrastructure\Persistence\CeibaInvoiceRepository', 'LemurAse\Infrastructure\Persistence\LemurInvoiceRepository');
}
