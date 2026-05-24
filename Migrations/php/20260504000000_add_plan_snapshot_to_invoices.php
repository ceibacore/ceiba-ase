<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;

/**
 * Add plan_snapshot JSON column to ase_invoices table
 */
class Migration_20260504000000_AddPlanSnapshotToInvoices extends AseBaseMigration
{
    public const VERSION     = '20260504000000';
    public const DESCRIPTION = 'add_plan_snapshot_to_invoices';

    public function up(): void
    {
        $this->schema->statement(
            'ALTER TABLE `ase_invoices` ADD COLUMN `plan_snapshot` JSON DEFAULT NULL COMMENT "Plan and price point-in-time details"'
        );
    }

    public function down(): void
    {
        $this->schema->statement(
            'ALTER TABLE `ase_invoices` DROP COLUMN `plan_snapshot`'
        );
    }
}
