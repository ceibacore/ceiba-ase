<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;
use LemurAse\Migration\AseColumnBlueprint;

/**
 * One-time payments requested by a host application in a context (e.g. 'marketplace'),
 * for one of its own records (reference), charged through an account's gateway.
 */
class Migration_20261108000001_CreatePaymentsTable extends AseBaseMigration
{
    public const VERSION     = '20261108000001';
    public const DESCRIPTION = 'create_payments_table';

    public function up(): void
    {
        $this->schema->createTable('payments', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->string('context', 50)->notNull();
            $t->string('reference', 100)->notNull();
            $t->string('account_ref', 80)->nullable();
            $t->string('external_client_id', 255)->notNull();
            $t->char('gateway_id', 36)->notNull();
            $t->string('provider', 50)->notNull();
            $t->bigInteger('amount_minor')->notNull();
            $t->char('currency', 3)->notNull();
            $t->string('description', 255)->notNull();
            $t->string('status', 30)->default('pending'); // pending|paid|failed|expired|refunded|partially_refunded|charged_back
            $t->string('external_id', 255)->nullable();
            $t->string('external_payment_id', 255)->nullable();
            $t->bigInteger('refunded_minor')->default(0);
            $t->text('metadata')->nullable();
            $t->datetime('paid_at')->nullable();
            $t->timestamps();
            $t->index(['context', 'reference'], 'idx_payments_context_reference');
            $t->index(['provider', 'external_id'], 'idx_payments_provider_external');
            $t->index(['provider', 'external_payment_id'], 'idx_payments_provider_payment');
            $t->index('account_ref', 'idx_payments_account');
            $t->index('status', 'idx_payments_status');
            $t->foreignKey('gateway_id', 'gateways', 'id', 'RESTRICT');
        });
    }

    public function down(): void
    {
        $this->schema->dropTableIfExists('payments');
    }
}
