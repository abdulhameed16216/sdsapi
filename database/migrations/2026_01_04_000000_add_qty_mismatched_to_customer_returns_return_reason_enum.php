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
     * Add 'qty_mismatched' to the return_reason enum in customer_returns table
     */
    public function up(): void
    {
        // MySQL doesn't support direct enum modification, so we need to alter the column
        // First, check if the column exists and get its current definition
        DB::statement("ALTER TABLE customer_returns MODIFY COLUMN return_reason ENUM('spoiled', 'damaged', 'expired', 'wrong_product', 'qty_mismatched', 'overstock', 'customer_request', 'other') DEFAULT 'other'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'qty_mismatched' from enum (revert to original)
        DB::statement("ALTER TABLE customer_returns MODIFY COLUMN return_reason ENUM('spoiled', 'damaged', 'expired', 'wrong_product', 'overstock', 'customer_request', 'other') DEFAULT 'other'");
    }
};

