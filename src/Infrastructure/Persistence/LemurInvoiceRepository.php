<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaInvoiceRepository.php';

if (!class_exists('LemurInvoiceRepository', false)) {
    class_alias('CeibaInvoiceRepository', 'LemurInvoiceRepository');
}
