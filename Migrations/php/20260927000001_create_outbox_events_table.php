<?php

declare(strict_types=1);

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;
use LemurAse\Migration\AseColumnBlueprint;

class Migration_20260927000001_CreateOutboxEventsTable extends AseBaseMigration
{
    public const VERSION     = '20260927000001';
    public const DESCRIPTION = 'create_outbox_events_table';

    public function up(): void
    {
        // ── outbox_events ─────────────────────────────────────────────────────
        $this->schema->createTable('outbox_events', function (AseColumnBlueprint $t) {
            $t->char('id', 36)->primary();
            $t->string('event_name', 100)->notNull();
            $t->string('aggregate_type', 50)->notNull();
            $t->char('aggregate_id', 36)->notNull();
            $t->text('payload')->notNull();
            $t->string('status', 30)->default('pending');
            $t->integer('attempts')->default(0);
            $t->text('error_message')->nullable();
            $t->datetime('published_at')->nullable();
            $t->timestamps();
            $t->index('status', 'idx_outbox_status');
            $t->index('event_name', 'idx_outbox_event');
            $t->index('aggregate_id', 'idx_outbox_agg_id');
            $t->index('created_at', 'idx_outbox_created');
        });
    }

    public function down(): void
    {
        $this->schema->dropTableIfExists('outbox_events');
    }
}
