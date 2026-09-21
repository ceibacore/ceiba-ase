<?php

namespace LemurAse\Infrastructure\Persistence;

require_once __DIR__ . '/CeibaSubscriptionRepository.php';

if (!class_exists('LemurSubscriptionRepository', false)) {
    class_alias('CeibaSubscriptionRepository', 'LemurSubscriptionRepository');
}
