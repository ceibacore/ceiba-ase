<?php

declare(strict_types=1);

namespace LemurAse\Domain\ValueObjects;

/**
 * ISO 4217 minor-unit conversion. Payment APIs such as Stripe and Antom/Alipay
 * take amounts in the smallest unit; multiplying by 100 is wrong for zero-decimal
 * (JPY, CLP...) and three-decimal (KWD, BHD...) currencies.
 */
final class MinorUnits
{
    /** Currencies whose minor unit is not 1/100. */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'MGA' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0,
        'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper(trim($currency))] ?? 2;
    }

    /** Decimal amount to integer minor units (e.g. 10.50 USD -> 1050, 1000 JPY -> 1000). */
    public static function toMinor(float|int|string $amount, string $currency): int
    {
        return (int) round(((float) $amount) * (10 ** self::exponent($currency)));
    }

    /** Integer minor units to a decimal amount. */
    public static function fromMinor(int|float|string $minor, string $currency): float
    {
        return round(((float) $minor) / (10 ** self::exponent($currency)), self::exponent($currency));
    }

    /** Decimal string with the currency's exact number of decimals (for PayPal-style APIs). */
    public static function format(float|int|string $amount, string $currency): string
    {
        return number_format((float) $amount, self::exponent($currency), '.', '');
    }

    /** True when both amounts are equal once expressed in minor units. */
    public static function equals(float|int|string $a, float|int|string $b, string $currency): bool
    {
        return self::toMinor($a, $currency) === self::toMinor($b, $currency);
    }
}
