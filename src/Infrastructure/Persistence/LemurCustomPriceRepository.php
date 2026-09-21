<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaCustomPriceRepository.php';

if (!class_exists('LemurCustomPriceRepository', false)) {
    class_alias('CeibaCustomPriceRepository', 'LemurCustomPriceRepository');
}
