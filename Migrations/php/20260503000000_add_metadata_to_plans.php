<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;

/**
 * Add metadata JSON column to ase_plans table
 */
class Migration_20260503000000_AddMetadataToPlans extends AseBaseMigration
{
    public const VERSION     = '20260503000000';
    public const DESCRIPTION = 'add_metadata_to_plans';

    public function up(): void
    {
        $this->schema->statement(
            'ALTER TABLE `ase_plans` ADD COLUMN `metadata` JSON DEFAULT NULL COMMENT "Plan metadata"'
        );
    }

    public function down(): void
    {
        $this->schema->statement(
            'ALTER TABLE `ase_plans` DROP COLUMN `metadata`'
        );
    }
}