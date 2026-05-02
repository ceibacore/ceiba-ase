<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;

/**
 * Add support for trial period subscriptions by introducing the 'trialing' status.
 *
 * The 'trialing' status is used to represent subscriptions that are currently
 * in their trial period. Once the trial ends, the status transitions to 'active'
 * upon the first successful charge.
 *
 * This migration is part of Gap #4 implementation.
 */
class Migration_20260502000000_AddTrialingStatus extends AseBaseMigration
{
    public const VERSION     = '20260502000000';
    public const DESCRIPTION = 'add_trialing_status';

    /**
     * Up: Add 'trialing' to the subscription status values.
     *
     * Since MySQL stores status as VARCHAR(50), we can add it without
     * changing the column type. If the system previously enforced ENUM,
     * we update it to accept the new value.
     */
    public function up(): void
    {
        // In MySQL, we check if the column is currently ENUM and update if needed
        // For portability (MySQL + PostgreSQL), the original schema uses VARCHAR(50)
        // so no additional work is needed. The 'trialing' value is already valid.
        //
        // If a stricter ENUM validation is desired in the future, use:
        // ALTER TABLE {prefix}subscriptions MODIFY COLUMN status 
        //   ENUM('pending', 'active', 'past_due', 'canceled', 'trialing', 'unpaid') 
        //   NOT NULL DEFAULT 'pending'
        //
        // For now, since the column is VARCHAR(50), we only document the new value.
        // No schema change is required.

        $this->schema->statement(
            "-- Trialing status is now supported (status column is VARCHAR(50), allows any value)"
        );
    }

    /**
     * Down: Revert if needed (no schema changes to revert).
     */
    public function down(): void
    {
        // No schema changes were made, so nothing to revert.
        // If in the future the column becomes a strict ENUM, this would revert it.
    }
}
