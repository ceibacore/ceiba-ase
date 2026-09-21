<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaPlanRepository.php';

if (!class_exists('LemurPlanRepository', false)) {
    class_alias('CeibaPlanRepository', 'LemurPlanRepository');
}
