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
        Schema::table('customers', function (Blueprint $table) {
            // Make username nullable
            $table->string('username')->nullable()->change();
            
            // Make password nullable
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Revert username to not nullable
            $table->string('username')->nullable(false)->change();
            
            // Revert password to not nullable
            $table->string('password')->nullable(false)->change();
        });
    }
};

