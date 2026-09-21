<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaPlanPriceRepository.php';

if (!class_exists('LemurAse\Infrastructure\Persistence\LemurPlanPriceRepository', false)) {
    class_alias('LemurAse\Infrastructure\Persistence\CeibaPlanPriceRepository', 'LemurAse\Infrastructure\Persistence\LemurPlanPriceRepository');
}
