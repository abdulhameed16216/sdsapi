<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Phase 2: Add customer_group_id to customers (no table rename).
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_group_id')->nullable()->after('id');
            $table->foreign('customer_group_id')->references('id')->on('customer_groups')->onDelete('set null');
            $table->index('customer_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['customer_group_id']);
            $table->dropIndex(['customer_group_id']);
            $table->dropColumn('customer_group_id');
        });
    }
};
