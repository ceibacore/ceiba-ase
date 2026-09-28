<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;
use LemurAse\Migration\AseColumnBlueprint;

class Migration_20260927000000_CreateCreditTables extends AseBaseMigration
{
    public const VERSION     = '20260927000000';
    public const DESCRIPTION = 'create_credit_tables';

    public function up(): void
    {
        // ── credit_balances ──────────────────────────────────────────────────
        $this->schema->createTable('credit_balances', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->string('client_id', 255)->notNull();
            $t->char('subscription_id', 36)->nullable();
            $t->integer('balance')->default(0);
            $t->integer('allocated_total')->default(0);
            $t->integer('consumed_total')->default(0);
            $t->timestamps();
            $t->index('client_id', 'idx_credit_bal_client');
            $t->uniqueIndex(['client_id', 'subscription_id'], 'idx_credit_bal_client_sub');
        });

        // ── credit_ledger ────────────────────────────────────────────────────
        $this->schema->createTable('credit_ledger', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->string('client_id', 255)->notNull();
            $t->char('subscription_id', 36)->nullable();
            $t->string('type', 50)->notNull(); // GRANT, CONSUME, RELEASE, ADJUST
            $t->integer('amount')->notNull();
            $t->char('reference_license_id', 36)->nullable();
            $t->string('description', 255)->nullable();
            $t->timestamps();
            $t->index('client_id', 'idx_credit_ledg_client');
            $t->index('reference_license_id', 'idx_credit_ledg_ref');
        });
    }

    public function down(): void
    {
        $this->schema->dropTableIfExists('credit_ledger');
        $this->schema->dropTableIfExists('credit_balances');
    }
}
