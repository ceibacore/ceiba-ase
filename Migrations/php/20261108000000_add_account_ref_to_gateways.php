<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;

/**
 * Gateways belong to an account: NULL is the platform (instance owner), 'tenant:{id}' an
 * academy with its own provider credentials. Existing gateways stay platform gateways.
 */
class Migration_20261108000000_AddAccountRefToGateways extends AseBaseMigration
{
    public const VERSION     = '20261108000000';
    public const DESCRIPTION = 'add_account_ref_to_gateways';

    public function up(): void
    {
        $this->schema->addColumn('gateways', 'account_ref', 'VARCHAR(80)', ['nullable' => true, 'default' => null]);
        $this->schema->addIndex('gateways', ['account_ref', 'provider'], 'idx_gateways_account_provider');
    }

    public function down(): void
    {
        $this->schema->dropIndex('gateways', 'idx_gateways_account_provider');
        $this->schema->dropColumn('gateways', 'account_ref');
    }
}
