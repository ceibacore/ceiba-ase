<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaTransactionLogRepository.php';

if (!class_exists('LemurAse\Infrastructure\Persistence\LemurTransactionLogRepository', false)) {
    class_alias('LemurAse\Infrastructure\Persistence\CeibaTransactionLogRepository', 'LemurAse\Infrastructure\Persistence\LemurTransactionLogRepository');
}
