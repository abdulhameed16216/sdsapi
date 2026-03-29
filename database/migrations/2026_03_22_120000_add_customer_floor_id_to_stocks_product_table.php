<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks_product', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_floor_id')->nullable()->after('delivery_products_id');
            $table->foreign('customer_floor_id')
                ->references('id')
                ->on('customers_floor')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stocks_product', function (Blueprint $table) {
            $table->dropForeign(['customer_floor_id']);
            $table->dropColumn('customer_floor_id');
        });
    }
};
