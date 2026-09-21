<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaTransactionLogRepository.php';

if (!class_exists('LemurTransactionLogRepository', false)) {
    class_alias('CeibaTransactionLogRepository', 'LemurTransactionLogRepository');
}
