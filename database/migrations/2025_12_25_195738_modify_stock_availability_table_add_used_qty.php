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
     * Changes:
     * - Add used_qty column (stock used/consumed)
     * - Remove stock_in_qty and stock_out_qty (will be calculated dynamically)
     * - Keep calculated_available_qty for reference but it will be calculated
     * - Keep closing_qty (calculated as available - used)
     */
    public function up(): void
    {
        Schema::table('stock_availability', function (Blueprint $table) {
            // Add used_qty column
            $table->integer('used_qty')->default(0)->after('calculated_available_qty');
        });

        // Migrate existing data: calculate used_qty from closing_qty
        // used_qty = calculated_available_qty - closing_qty
        DB::statement('UPDATE stock_availability SET used_qty = GREATEST(0, calculated_available_qty - closing_qty)');

        // Note: We keep stock_in_qty and stock_out_qty columns for now for backward compatibility
        // But they will not be used in new logic - they will be calculated dynamically
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_availability', function (Blueprint $table) {
            $table->dropColumn('used_qty');
        });
    }
};
