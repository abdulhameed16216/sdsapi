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
        Schema::create('machine_readings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('machine_id');
            $table->date('reading_date');
            $table->decimal('reading_value', 10, 2)->default(0);
            $table->string('reading_type')->nullable();
            $table->string('unit')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('machine_id')->references('id')->on('machines')->onDelete('cascade');

            // Indexes
            $table->index('user_id');
            $table->index('machine_id');
            $table->index('reading_date');
            $table->index('reading_type');

            // Unique constraint: one reading per machine per date
            $table->unique(['machine_id', 'reading_date'], 'machine_readings_machine_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_readings');
    }
};
