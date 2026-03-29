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
        Schema::table('attendance', function (Blueprint $table) {
            // Drop unique constraint to allow multiple entries per employee per day
            // This allows employees to punch in/out multiple times at different locations
            $table->dropUnique('attendance_emp_id_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            // Restore unique constraint if needed to rollback
            $table->unique(['emp_id', 'date'], 'attendance_emp_id_date_unique');
        });
    }
};
