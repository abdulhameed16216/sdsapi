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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('stock_alert'); // stock_alert, system, etc.
            $table->string('title');
            $table->text('message');
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['unread', 'read'])->default('unread');
            $table->bigInteger('user_id')->unsigned()->nullable(); // NULL = all users, specific ID = specific user
            $table->bigInteger('customer_id')->unsigned()->nullable(); // For customer-specific alerts
            $table->bigInteger('product_id')->unsigned()->nullable(); // For product-specific alerts
            $table->json('data')->nullable(); // Additional data (product details, stock info, etc.)
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('user_id');
            $table->index('status');
            $table->index('type');
            $table->index('priority');
            $table->index('customer_id');
            $table->index('product_id');
            $table->index('created_at');

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
