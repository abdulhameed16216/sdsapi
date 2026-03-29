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
            $table->decimal('punch_out_latitude', 10, 8)->nullable()->after('longitude');
            $table->decimal('punch_out_longitude', 11, 8)->nullable()->after('punch_out_latitude');
            $table->string('punch_out_location', 500)->nullable()->after('punch_out_longitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn(['punch_out_latitude', 'punch_out_longitude', 'punch_out_location']);
        });
    }
};
