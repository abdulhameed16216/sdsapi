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
        Schema::table('attendances', function (Blueprint $table) {
            // Add latitude column if it doesn't exist
            if (!Schema::hasColumn('attendances', 'latitude')) {
                $table->decimal('latitude', 10, 8)->nullable()->after('user_id');
            }
            
            // Add longitude column if it doesn't exist
            if (!Schema::hasColumn('attendances', 'longitude')) {
                $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            }
            
            // Add attendance_type column if it doesn't exist
            if (!Schema::hasColumn('attendances', 'attendance_type')) {
                $table->string('attendance_type', 50)->default('location')->after('longitude');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'latitude')) {
                $table->dropColumn('latitude');
            }
            
            if (Schema::hasColumn('attendances', 'longitude')) {
                $table->dropColumn('longitude');
            }
            
            if (Schema::hasColumn('attendances', 'attendance_type')) {
                $table->dropColumn('attendance_type');
            }
        });
    }
};
