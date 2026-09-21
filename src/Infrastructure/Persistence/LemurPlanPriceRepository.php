<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaPlanPriceRepository.php';

if (!class_exists('LemurPlanPriceRepository', false)) {
    class_alias('CeibaPlanPriceRepository', 'LemurPlanPriceRepository');
}
