<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Change stock_type from ENUM to VARCHAR for flexibility
     */
    public function up(): void
    {
        // Change stock_type from ENUM to VARCHAR(255)
        DB::statement("ALTER TABLE stocks_product MODIFY COLUMN stock_type VARCHAR(255) DEFAULT NULL");
    }

    /**
     * Reverse the migrations.
     * Note: This will not recreate the ENUM as we don't have the original enum values
     * If you need to rollback, you'll need to restore from a backup
     */
    public function down(): void
    {
        // Cannot recreate ENUM without original values
        // Table should be restored from backup if rollback is needed
    }
};
