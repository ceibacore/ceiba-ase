<?php

namespace LemurAse\Shared\Infrastructure;

require_once __DIR__ . '/CeibaInstance.php';

if (!class_exists('LemurAse\Shared\Infrastructure\LemurInstance', false)) {
    class_alias('LemurAse\Shared\Infrastructure\CeibaInstance', 'LemurAse\Shared\Infrastructure\LemurInstance');
}
