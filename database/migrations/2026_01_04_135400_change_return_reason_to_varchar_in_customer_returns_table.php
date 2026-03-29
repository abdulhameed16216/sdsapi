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
     * Change return_reason from ENUM to VARCHAR for flexibility
     */
    public function up(): void
    {
        // Change return_reason from ENUM to VARCHAR(255)
        DB::statement("ALTER TABLE customer_returns MODIFY COLUMN return_reason VARCHAR(255) DEFAULT 'other'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert back to ENUM (with all current values)
        DB::statement("ALTER TABLE customer_returns MODIFY COLUMN return_reason ENUM('spoiled', 'damaged', 'expired', 'wrong_product', 'qty_mismatched', 'overstock', 'customer_request', 'other') DEFAULT 'other'");
    }
};
