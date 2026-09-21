<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaSubscriptionRepository.php';

if (!class_exists('LemurAse\Infrastructure\Persistence\LemurSubscriptionRepository', false)) {
    class_alias('LemurAse\Infrastructure\Persistence\CeibaSubscriptionRepository', 'LemurAse\Infrastructure\Persistence\LemurSubscriptionRepository');
}
