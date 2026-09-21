<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaPlanRepository.php';

if (!class_exists('LemurAse\Infrastructure\Persistence\LemurPlanRepository', false)) {
    class_alias('LemurAse\Infrastructure\Persistence\CeibaPlanRepository', 'LemurAse\Infrastructure\Persistence\LemurPlanRepository');
}
