<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $sql = file_get_contents(__DIR__ . '/../pure_sql/001_initial_schema.sql');
        DB::unprepared($sql);
    }

    public function down()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::statement('DROP TABLE IF EXISTS ase_transactions_log');
        DB::statement('DROP TABLE IF EXISTS ase_invoices');
        DB::statement('DROP TABLE IF EXISTS ase_subscriptions');
        DB::statement('DROP TABLE IF EXISTS ase_orders');
        DB::statement('DROP TABLE IF EXISTS ase_gateways');
        DB::statement('DROP TABLE IF EXISTS ase_custom_prices');
        DB::statement('DROP TABLE IF EXISTS ase_plan_prices');
        DB::statement('DROP TABLE IF EXISTS ase_plans');
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
};
