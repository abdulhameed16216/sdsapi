<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_floor_id')->nullable()->after('customer_id');
            $table->foreign('customer_floor_id')->references('id')->on('customers_floor')->nullOnDelete();
        });

        // Best-effort backfill from old line-level column:
        // set stock header floor to first non-null floor id found for that stock.
        DB::statement("
            UPDATE stocks s
            JOIN (
                SELECT stock_id, MIN(customer_floor_id) AS customer_floor_id
                FROM stocks_product
                WHERE customer_floor_id IS NOT NULL
                GROUP BY stock_id
            ) x ON x.stock_id = s.id
            SET s.customer_floor_id = x.customer_floor_id
            WHERE s.customer_floor_id IS NULL
        ");

        Schema::table('stocks_product', function (Blueprint $table) {
            $table->dropForeign(['customer_floor_id']);
            $table->dropColumn('customer_floor_id');
        });
    }

    public function down(): void
    {
        Schema::table('stocks_product', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_floor_id')->nullable()->after('delivery_products_id');
            $table->foreign('customer_floor_id')->references('id')->on('customers_floor')->nullOnDelete();
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropForeign(['customer_floor_id']);
            $table->dropColumn('customer_floor_id');
        });
    }
};

