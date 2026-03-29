<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Drop stock_availability and stock_availability_groups tables
     * These tables are no longer needed as we calculate stock availability on-the-fly from stocks_product table
     */
    public function up(): void
    {
        // Disable foreign key checks temporarily
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Drop tables (foreign keys will be dropped automatically)
        Schema::dropIfExists('stock_availability');
        Schema::dropIfExists('stock_availability_groups');

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    }

    /**
     * Reverse the migrations.
     * Note: This will not recreate the tables as we don't have the original schema
     * If you need to rollback, you'll need to restore from a backup
     */
    public function down(): void
    {
        // Cannot recreate tables without original schema
        // Tables should be restored from backup if rollback is needed
    }
};
