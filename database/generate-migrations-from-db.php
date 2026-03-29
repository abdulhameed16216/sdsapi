<?php
/**
 * Generate migrations from existing database tables
 * This script will:
 * 1. Get all tables from database (except migrations)
 * 2. Generate CREATE TABLE migrations for each table
 * 3. Store them in database/migrations/ folder
 * 
 * Run: php database/generate-migrations-from-db.php
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

$migrationsDir = __DIR__ . '/migrations';
$timestamp = date('Y_m_d_His');

echo "Found tables:\n";
foreach ($tables as $table) {
    $tableName = $table->$tableColumn;
    if ($tableName !== 'migrations') {
        echo "- {$tableName}\n";
    }
}

echo "\nDo you want to proceed? This will create migration files for all tables.\n";
echo "Enter 'yes' to continue: ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
fclose($handle);

if (trim($line) !== 'yes') {
    echo "Cancelled.\n";
    exit;
}

$batchNumber = 1;
foreach ($tables as $table) {
    $tableName = $table->$tableColumn;
    
    if ($tableName === 'migrations') {
        continue;
    }
    
    // Get table structure
    $createTable = DB::select("SHOW CREATE TABLE `{$tableName}`")[0];
    $createStatement = $createTable->{'Create Table'};
    
    // Generate migration file
    $migrationName = sprintf('%s_create_%s_table', $timestamp, $tableName);
    $fileName = $migrationName . '.php';
    $filePath = $migrationsDir . '/' . $fileName;
    
    // Create migration content
    $migrationContent = generateMigrationContent($tableName, $createStatement);
    
    file_put_contents($filePath, $migrationContent);
    
    echo "Created: {$fileName}\n";
    
    $timestamp = date('Y_m_d_His', strtotime("+1 second"));
}

echo "\n=== Migration files generated successfully! ===\n";
echo "You can now run: php artisan migrate\n";

function generateMigrationContent($tableName, $createStatement) {
    // Parse CREATE TABLE statement and convert to Laravel migration
    // This is a simplified version - you might need to enhance this
    
    $className = str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName))) . 'Table';
    
    return <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Note: This migration was generated from existing table structure.
     * The table '{$tableName}' already exists in the database.
     * This migration is for documentation purposes.
     */
    public function up(): void
    {
        // Table already exists - do nothing
        // This migration is only for tracking purposes
        
        // If you need to recreate the table, uncomment below:
        /*
        Schema::create('{$tableName}', function (Blueprint \$table) {
            // Migration content for {$tableName}
            // Generated from: {$createStatement}
        });
        */
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop existing table
        // Schema::dropIfExists('{$tableName}');
    }
};
PHP;
}

