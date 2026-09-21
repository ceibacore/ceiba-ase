<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaCustomPriceRepository.php';

if (!class_exists('LemurAse\Infrastructure\Persistence\LemurCustomPriceRepository', false)) {
    class_alias('LemurAse\Infrastructure\Persistence\CeibaCustomPriceRepository', 'LemurAse\Infrastructure\Persistence\LemurCustomPriceRepository');
}
