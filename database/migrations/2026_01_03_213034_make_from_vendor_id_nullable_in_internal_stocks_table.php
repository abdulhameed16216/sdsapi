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
        Schema::table('internal_stocks', function (Blueprint $table) {
            // Make from_vendor_id nullable to allow internal stocks from customer returns
            $table->unsignedBigInteger('from_vendor_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internal_stocks', function (Blueprint $table) {
            // Revert from_vendor_id to not nullable
            // Note: This will fail if there are existing null values
            $table->unsignedBigInteger('from_vendor_id')->nullable(false)->change();
        });
    }
};
