<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add 'transfer' to the delivery_type ENUM
        DB::statement("ALTER TABLE delivery MODIFY COLUMN delivery_type ENUM('in', 'out', 'transfer') DEFAULT 'in'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove 'transfer' from the delivery_type ENUM (revert to original)
        DB::statement("ALTER TABLE delivery MODIFY COLUMN delivery_type ENUM('in', 'out') DEFAULT 'in'");
    }
};
