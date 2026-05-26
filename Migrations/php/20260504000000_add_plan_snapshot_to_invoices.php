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
        $this->schema->addColumn('invoices', 'plan_snapshot', 'JSON', [
            'nullable' => true,
            'default'  => null,
        ]);
    }

    public function down(): void
    {
        $this->schema->dropColumn('invoices', 'plan_snapshot');
    }
}
