<?php

namespace LemurAse\Domain\Services;

use LemurAse\Domain\Entities\PlanPrice;

/**
 * Calculates billing period end dates based on plan interval and count.
 * This service ensures that subscriptions renew according to the plan's
 * interval definition (day, month, year), not a hardcoded +1 month.
 *
 * Handles:
 * - Recurring plans: calculates next period end based on interval
 * - One-time plans: returns null (no renewal)
 * - Day/Month/Year intervals with customizable counts
 *
 * Uses DateTimeImmutable for immutability (no side effects).
 */
final class BillingPeriodCalculator
{
    /**
     * Calculates the date of the next billing period end.
     *
     * @param PlanPrice            $planPrice  The plan with interval and intervalCount
     * @param \DateTimeImmutable   $fromDate   Start date (usually "now")
     * @return \DateTimeImmutable|null         End date, or null for one-time plans
     *
     * @throws \InvalidArgumentException If the plan is recurring but has no interval,
     *         or if interval is unknown, or if intervalCount is invalid
     */
    public static function calculate(
        PlanPrice $planPrice,
        \DateTimeImmutable $fromDate
    ): ?\DateTimeImmutable {
        // One-time plans have no renewal period
        if ($planPrice->type() === 'one_time') {
            return null;
        }

        $interval = $planPrice->interval();
        $count = $planPrice->intervalCount();

        // Recurring plans MUST have an interval defined
        if ($interval === null) {
            throw new \InvalidArgumentException(
                "PlanPrice {$planPrice->id()->uuid()} is recurring but has no interval set."
            );
        }

        // Interval count must be positive
        if ($count < 1) {
            throw new \InvalidArgumentException(
                "PlanPrice {$planPrice->id()->uuid()} has an invalid intervalCount: {$count}."
            );
        }

        // Build the modifier string based on interval type
        $modifier = match ($interval) {
            'day'   => "+{$count} day",
            'month' => "+{$count} month",
            'year'  => "+{$count} year",
            default => throw new \InvalidArgumentException(
                "PlanPrice {$planPrice->id()->uuid()} has an unknown interval: '{$interval}'."
            ),
        };

        return $fromDate->modify($modifier);
    }
}
