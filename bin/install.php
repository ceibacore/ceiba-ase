<?php

require_once __DIR__ . '/../vendor/autoload.php';

use LemurAse\Shared\LemurInstance;

echo "Installing Agnostic Subscription Engine (ASE) tables...\n";

try {
    $db = LemurInstance::get();
    $prefix = $_ENV['DB_PREFIX'] ?? getenv('DB_PREFIX') ?: 'ase_';
    $sql = file_get_contents(__DIR__ . '/../Migrations/pure_sql/001_initial_schema.sql');
    
    if ($prefix !== 'ase_') {
        $sql = str_replace('ase_', $prefix, $sql);
    }
    
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $db->pdo()->exec($statement);
        }
    }
    
    echo "✅ Schema installed successfully!\n";
} catch (\Exception $e) {
    echo "❌ Error installing schema: " . $e->getMessage() . "\n";
    exit(1);
}
