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
        Schema::create('partner_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('short_desc')->nullable();
            $table->json('list_items')->nullable(); // Array of list items
            $table->unsignedBigInteger('user_id')->nullable(); // User who created it
            $table->timestamps();

            // Foreign key
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_programs');
    }
};
