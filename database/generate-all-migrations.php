<?php
/**
 * Generate migrations from all existing database tables
 * This will create migration files that match your current database structure
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== Generating Migrations from Existing Database ===\n\n";

// Get all tables except migrations
$tables = DB::select('SHOW TABLES');
$database = DB::connection()->getDatabaseName();
$tableColumn = "Tables_in_{$database}";

$tableList = [];
foreach ($tables as $table) {
    $tableName = $table->$tableColumn;
    if ($tableName !== 'migrations') {
        $tableList[] = $tableName;
        echo "- {$tableName}\n";
    }
}

echo "\nFound " . count($tableList) . " tables.\n";
echo "Generating migrations...\n\n";

// Use artisan command to generate migrations
$tableString = implode(',', $tableList);
exec("php artisan migrate:generate --tables={$tableString} --no-interaction --path=database/migrations 2>&1", $output, $returnCode);

foreach ($output as $line) {
    echo $line . "\n";
}

echo "\n=== Done! ===\n";
echo "Migration files have been generated in database/migrations/\n";
echo "Note: These migrations are marked as 'already exists' since tables are in the database.\n";
echo "You may want to review and update them to be proper CREATE TABLE migrations.\n";

