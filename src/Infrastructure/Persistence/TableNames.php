<?php

namespace LemurAse\Infrastructure\Persistence;

final class TableNames
{
    public const PLANS = 'plans';
    public const PLAN_PRICES = 'plan_prices';
    public const CUSTOM_PRICES = 'custom_prices';
    public const GATEWAYS = 'gateways';
    public const ORDERS = 'orders';
    public const SUBSCRIPTIONS = 'subscriptions';
    public const INVOICES = 'invoices';
    public const TRANSACTIONS_LOG = 'transactions_log';

    public static function all(): array
    {
        return [
            self::PLANS,
            self::PLAN_PRICES,
            self::CUSTOM_PRICES,
            self::GATEWAYS,
            self::ORDERS,
            self::SUBSCRIPTIONS,
            self::INVOICES,
            self::TRANSACTIONS_LOG,
        ];
    }
}
