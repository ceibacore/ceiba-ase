<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaOrderRepository.php';

if (!class_exists('LemurAse\Infrastructure\Persistence\LemurOrderRepository', false)) {
    class_alias('LemurAse\Infrastructure\Persistence\CeibaOrderRepository', 'LemurAse\Infrastructure\Persistence\LemurOrderRepository');
}
