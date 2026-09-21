<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaGatewayRepository.php';

if (!class_exists('LemurGatewayRepository', false)) {
    class_alias('CeibaGatewayRepository', 'LemurGatewayRepository');
}
