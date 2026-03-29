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
            $table->decimal('latitude', 10, 8)->nullable()->after('selfie_image');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            // Update type column to support new values: 'selfie', 'location', 'manual_regularization', 'regular', 'regularized'
            // Note: If type column doesn't exist or needs modification, uncomment below
            // $table->string('type', 50)->default('regular')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
