<?php

namespace LemurAse\Migrations;

use LemurAse\Migration\AseBaseMigration;
use LemurAse\Migration\AseColumnBlueprint;

class Migration_20260527195244_CreateCustomersTable extends AseBaseMigration
{
    public const VERSION     = '20260527195244';
    public const DESCRIPTION = 'create_customers_table';

    public function up(): void
    {
        $this->schema->createTable('customers', function (AseColumnBlueprint $t) {
            $t->string('client_id', 255)->notNull();
            $t->string('gateway_provider', 50)->notNull();
            $t->string('gateway_customer_id', 255)->notNull();
            $t->timestamps();
            $t->uniqueIndex(['client_id', 'gateway_provider'], 'idx_client_provider');
        });
    }

    public function down(): void
    {
        $this->schema->dropTableIfExists('customers');
    }
}
