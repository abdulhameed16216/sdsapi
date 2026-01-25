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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mobile_number');
            $table->string('email');
            $table->string('service')->nullable();
            $table->string('state')->nullable();
            $table->string('project_type')->nullable();
            $table->string('budget')->nullable();
            $table->text('project_details')->nullable();
            $table->date('project_date')->nullable();
            $table->text('files')->nullable(); // JSON array of file paths
            $table->string('status')->default('pending'); // pending, contacted, completed, cancelled
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
