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
        Schema::create('employee_floor', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('floor_id');
            $table->unsignedBigInteger('employee_id');
            $table->timestamps();

            $table->foreign('group_id')
                ->references('id')
                ->on('customer_groups')
                ->onDelete('cascade');

            $table->foreign('location_id')
                ->references('id')
                ->on('customers')
                ->onDelete('cascade');

            $table->foreign('floor_id')
                ->references('id')
                ->on('customers_floor')
                ->onDelete('cascade');

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->onDelete('cascade');

            $table->index('group_id');
            $table->index('location_id');
            $table->index('floor_id');
            $table->index('employee_id');

            $table->unique(
                ['group_id', 'location_id', 'floor_id', 'employee_id'],
                'employee_floor_unique_mapping'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_floor');
    }
};

