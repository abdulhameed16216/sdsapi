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
        Schema::create('machine_readings_category', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('machine_readings_id');
            $table->string('category'); // e.g., 'light_coffee', 'strong_coffee', etc.
            $table->decimal('reading_value', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign key
            $table->foreign('machine_readings_id')->references('id')->on('machine_readings')->onDelete('cascade');
            
            // Indexes
            $table->index('machine_readings_id');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_readings_category');
    }
};
