<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $prefix = env('ASE_DB_PREFIX', 'ase_');
        $sql = file_get_contents(__DIR__ . '/../pure_sql/001_initial_schema.sql');
        
        // Replace default 'ase_' prefix with configured one if different
        if ($prefix !== 'ase_') {
            $sql = str_replace('ase_', $prefix, $sql);
        }
        
        DB::unprepared($sql);
    }

    public function down()
    {
        $prefix = env('ASE_DB_PREFIX', 'ase_');
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        $tables = [
            'transactions_log', 'invoices', 'subscriptions', 'orders', 
            'gateways', 'custom_prices', 'plan_prices', 'plans'
        ];

        foreach ($tables as $table) {
            DB::statement("DROP TABLE IF EXISTS {$prefix}{$table}");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }
};
