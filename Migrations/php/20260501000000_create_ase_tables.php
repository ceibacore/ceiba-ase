<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;
use LemurAse\Migration\AseColumnBlueprint;

/**
 * Initial schema for lemur-ase.
 * Converted from migrations/pure_sql/001_initial_schema.sql
 *
 * Note: ENUM columns from the original schema are replaced with VARCHAR(50)
 * for cross-database portability (MySQL + PostgreSQL).
 * The prefix is applied automatically by AseSchemaBuilder.
 */
class Migration_20260501000000_CreateAseTables extends AseBaseMigration
{
    public const VERSION     = '20260501000000';
    public const DESCRIPTION = 'create_ase_tables';

    public function up(): void
    {
        // ── plans ────────────────────────────────────────────────────────────
        $this->schema->createTable('plans', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('short_id', 12)->notNull()->unique();
            $t->string('slug', 100)->notNull()->unique();
            $t->string('name', 150)->notNull();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
            $t->index('short_id', 'idx_short_id');
        });

        // ── plan_prices ───────────────────────────────────────────────────────
        $this->schema->createTable('plan_prices', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('short_id', 12)->notNull()->unique();
            $t->char('plan_id', 36)->notNull();
            $t->string('type', 50)->notNull();           // recurring | one_time
            $t->decimal('amount', 10, 2)->notNull();
            $t->char('currency', 3)->notNull();
            $t->string('interval', 50)->nullable();      // day | month | year
            $t->integer('interval_count')->default(1);
            $t->integer('trial_days')->default(0);
            $t->boolean('is_active')->default(1);
            $t->index('short_id', 'idx_short_id');
            $t->foreignKey('plan_id', 'plans', 'id', 'CASCADE');
        });

        // ── custom_prices ─────────────────────────────────────────────────────
        $this->schema->createTable('custom_prices', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('short_id', 12)->notNull()->unique();
            $t->char('plan_price_id', 36)->notNull();
            $t->string('external_client_id', 255)->notNull();
            $t->decimal('custom_amount', 10, 2)->notNull();
            $t->datetime('valid_until')->nullable();
            $t->index('short_id', 'idx_short_id');
            $t->uniqueIndex(['external_client_id', 'plan_price_id'], 'idx_client_plan');
            $t->foreignKey('plan_price_id', 'plan_prices', 'id', 'CASCADE');
        });

        // ── gateways ─────────────────────────────────────────────────────────
        $this->schema->createTable('gateways', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('short_id', 12)->notNull()->unique();
            $t->string('provider', 50)->notNull();
            $t->text('credentials')->notNull();
            $t->boolean('is_active')->default(1);
            $t->index('short_id', 'idx_short_id');
            $t->index('is_active', 'idx_gateways_is_active');
        });

        // ── orders ────────────────────────────────────────────────────────────
        $this->schema->createTable('orders', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('short_id', 12)->notNull()->unique();
            $t->string('external_client_id', 255)->notNull();
            $t->char('plan_price_id', 36)->notNull();
            $t->char('gateway_id', 36)->notNull();
            $t->char('custom_price_id', 36)->nullable();
            $t->decimal('amount', 10, 2)->notNull();
            $t->char('currency', 3)->notNull();
            $t->string('security_hash', 255)->notNull();
            $t->string('status', 50)->default('pending'); // pending|paid|failed|expired|refunded
            $t->datetime('expires_at')->nullable();
            $t->string('external_order_id', 255)->nullable();
            $t->timestamps();
            $t->index('short_id', 'idx_short_id');
            $t->index(['external_client_id', 'status'], 'idx_client_status');
            $t->foreignKey('plan_price_id', 'plan_prices', 'id', 'RESTRICT');
            $t->foreignKey('gateway_id', 'gateways', 'id', 'RESTRICT');
        });

        // ── subscriptions ────────────────────────────────────────────────────
        $this->schema->createTable('subscriptions', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('short_id', 12)->notNull()->unique();
            $t->char('order_id', 36)->notNull();
            $t->string('external_client_id', 255)->notNull();
            $t->char('plan_price_id', 36)->notNull();
            $t->char('gateway_id', 36)->notNull();
            $t->string('external_subscription_id', 255)->nullable();
            $t->string('status', 50)->notNull(); // pending|active|past_due|canceled|unpaid
            $t->datetime('current_period_start')->nullable();
            $t->datetime('current_period_end')->nullable();
            $t->datetime('canceled_at')->nullable();
            $t->timestamps();
            $t->index('short_id', 'idx_short_id');
            $t->index(['external_client_id', 'status'], 'idx_client_status');
            $t->foreignKey('order_id', 'orders', 'id', 'RESTRICT');
            $t->foreignKey('plan_price_id', 'plan_prices', 'id', 'RESTRICT');
            $t->foreignKey('gateway_id', 'gateways', 'id', 'RESTRICT');
        });

        // ── invoices ──────────────────────────────────────────────────────────
        $this->schema->createTable('invoices', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('short_id', 12)->notNull()->unique();
            $t->char('order_id', 36)->notNull();
            $t->char('subscription_id', 36)->nullable();
            $t->string('external_client_id', 255)->notNull();
            $t->string('invoice_number', 50)->notNull()->unique();
            $t->decimal('subtotal', 10, 2)->notNull();
            $t->decimal('tax_amount', 10, 2)->default(0);
            $t->decimal('total', 10, 2)->notNull();
            $t->char('currency', 3)->notNull();
            $t->datetime('period_start')->nullable();
            $t->datetime('period_end')->nullable();
            $t->string('status', 50)->notNull(); // draft|issued|paid|void
            $t->datetime('issued_at')->nullable();
            $t->datetime('due_at')->nullable();
            $t->datetime('paid_at')->nullable();
            $t->datetime('created_at')->default('CURRENT_TIMESTAMP');
            $t->index('short_id', 'idx_short_id');
            $t->foreignKey('order_id', 'orders', 'id', 'RESTRICT');
        });

        // ── transactions_log ──────────────────────────────────────────────────
        $this->schema->createTable('transactions_log', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->char('short_id', 12)->notNull()->unique();
            $t->char('subscription_id', 36)->nullable();
            $t->char('order_id', 36)->nullable();
            $t->string('external_client_id', 255)->notNull();
            $t->char('gateway_id', 36)->notNull();
            $t->string('external_transaction_id', 255)->nullable()->unique();
            $t->string('type', 50)->notNull();    // payment|refund|chargeback
            $t->decimal('amount', 10, 2)->notNull();
            $t->string('status', 50)->notNull();  // success|failed|pending
            $t->string('security_hash', 255)->notNull();
            $t->json('raw_payload')->nullable();
            $t->datetime('created_at')->default('CURRENT_TIMESTAMP');
            $t->index('short_id', 'idx_short_id');
            $t->index(['external_client_id', 'status'], 'idx_client_status');
            $t->foreignKey('gateway_id', 'gateways', 'id', 'RESTRICT');
        });
    }

    public function down(): void
    {
        // Disable FK checks so we can drop in any order
        $this->schema->statement('SET FOREIGN_KEY_CHECKS=0');

        $this->schema->dropTableIfExists('transactions_log');
        $this->schema->dropTableIfExists('invoices');
        $this->schema->dropTableIfExists('subscriptions');
        $this->schema->dropTableIfExists('orders');
        $this->schema->dropTableIfExists('gateways');
        $this->schema->dropTableIfExists('custom_prices');
        $this->schema->dropTableIfExists('plan_prices');
        $this->schema->dropTableIfExists('plans');

        $this->schema->statement('SET FOREIGN_KEY_CHECKS=1');
    }
}
