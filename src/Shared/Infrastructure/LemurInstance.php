<?php

namespace LemurAse\Shared\Infrastructure;

require_once __DIR__ . '/CeibaInstance.php';

if (!class_exists('LemurInstance', false)) {
    class_alias('CeibaInstance', 'LemurInstance');
}
