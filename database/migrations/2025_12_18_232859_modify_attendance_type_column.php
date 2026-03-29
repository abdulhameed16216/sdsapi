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
            // Modify type column to support all attendance types
            // Change from ENUM or small VARCHAR to VARCHAR(50) to support:
            // 'regular', 'regularized', 'selfie', 'location', 'manual_regularization'
            $table->string('type', 50)->default('regular')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Note: Cannot fully reverse this change without knowing the original column definition
        // If needed, manually restore the original type column definition
        Schema::table('attendance', function (Blueprint $table) {
            // Revert to original type if it was an ENUM
            // This is a placeholder - adjust based on your original schema
        });
    }
};
