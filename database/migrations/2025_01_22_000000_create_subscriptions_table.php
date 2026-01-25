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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('short_description')->nullable();
            $table->text('main_description')->nullable();
            $table->decimal('start_price', 10, 2)->nullable();
            $table->decimal('end_price', 10, 2)->nullable();
            $table->string('price_unit', 50)->default('month');
            $table->json('sections')->nullable();
            $table->integer('status')->default(0); // 0 = Draft, 1 = Published, 2 = Archived
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};

