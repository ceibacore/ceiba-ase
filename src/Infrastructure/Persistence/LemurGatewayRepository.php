<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaGatewayRepository.php';

if (!class_exists('LemurAse\Infrastructure\Persistence\LemurGatewayRepository', false)) {
    class_alias('LemurAse\Infrastructure\Persistence\CeibaGatewayRepository', 'LemurAse\Infrastructure\Persistence\LemurGatewayRepository');
}
