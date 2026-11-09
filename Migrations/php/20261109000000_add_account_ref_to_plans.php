<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;

/**
 * Plans belong to an account: NULL is the platform (plans sold to academy owners),
 * 'tenant:{id}' an academy's own plans (memberships sold to its students, charged through
 * its own gateways). Existing plans stay platform plans.
 */
class Migration_20261109000000_AddAccountRefToPlans extends AseBaseMigration
{
    public const VERSION     = '20261109000000';
    public const DESCRIPTION = 'add_account_ref_to_plans';

    public function up(): void
    {
        $this->schema->addColumn('plans', 'account_ref', 'VARCHAR(80)', ['nullable' => true, 'default' => null]);
        $this->schema->addIndex('plans', ['account_ref'], 'idx_plans_account');
    }

    public function down(): void
    {
        $this->schema->dropIndex('plans', 'idx_plans_account');
        $this->schema->dropColumn('plans', 'account_ref');
    }
}
