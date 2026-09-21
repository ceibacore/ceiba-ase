<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaOrderRepository.php';

if (!class_exists('LemurOrderRepository', false)) {
    class_alias('CeibaOrderRepository', 'LemurOrderRepository');
}
