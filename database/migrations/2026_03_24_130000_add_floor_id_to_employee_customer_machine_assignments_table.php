<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_customer_machine_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('floor_id')->nullable()->after('customer_id');
            $table->foreign('floor_id')
                ->references('id')
                ->on('customers_floor')
                ->onDelete('set null');
            $table->index('floor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_customer_machine_assignments', function (Blueprint $table) {
            $table->dropForeign(['floor_id']);
            $table->dropIndex(['floor_id']);
            $table->dropColumn('floor_id');
        });
    }
};

