<?php
/**
 * Script to mark migrations as run after importing live database backup
 * 
 * Run this after importing your live database backup:
 * php database/mark-migrations-after-import.php
 * 
 * This will check which tables exist and mark corresponding migrations as run
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo "Checking existing tables and marking migrations as run...\n\n";

$migrationMap = [
    'personal_access_tokens' => '2019_12_14_000001_create_personal_access_tokens_table',
    'roles' => '2025_10_05_141414_create_roles_table',
    'employees' => '2025_10_05_141439_create_employees_table',
    'users' => '2025_10_05_141600_create_users_table',
    'customers' => '2025_10_09_145925_create_customers_table',
    'machines' => '2025_10_09_161017_create_machines_table',
    'products' => '2025_10_09_164638_create_products_table',
    'customer_machines' => '2024_01_20_000003_create_customer_machines_table',
    // Add more mappings as needed
];

$batch = 1;
$inserted = 0;

foreach ($migrationMap as $tableName => $migrationName) {
    if (Schema::hasTable($tableName)) {
        // Check if migration already exists
        $exists = DB::table('migrations')
            ->where('migration', $migrationName)
            ->exists();
        
        if (!$exists) {
            DB::table('migrations')->insert([
                'migration' => $migrationName,
                'batch' => $batch
            ]);
            echo "✓ Marked migration as run: {$migrationName} (table: {$tableName})\n";
            $inserted++;
        } else {
            echo "- Migration already exists: {$migrationName}\n";
        }
    } else {
        echo "✗ Table not found: {$tableName} (migration: {$migrationName})\n";
    }
}

echo "\nTotal migrations marked: {$inserted}\n";
echo "Done! You can now run migrations for new tables.\n";

