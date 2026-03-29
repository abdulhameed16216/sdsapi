<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Customer Returns Table:
     * - Stores returns from customers (spoiled, damaged, etc.)
     * - Requires admin approval before moving to internal stocks or returning to vendor
     * - Does NOT affect customer stock until approved
     */
    public function up(): void
    {
        Schema::create('customer_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('delivery_id')->nullable(); // Reference to original delivery if available
            $table->unsignedBigInteger('stock_id')->nullable(); // Reference to stock record if available
            $table->integer('return_qty'); // Quantity being returned
            $table->enum('return_reason', [
                'spoiled',
                'damaged',
                'expired',
                'wrong_product',
                'overstock',
                'customer_request',
                'other'
            ])->default('other');
            $table->text('return_reason_details')->nullable(); // Additional details
            $table->date('return_date'); // Date when return was initiated
            $table->enum('status', [
                'pending',      // Waiting for admin approval
                'approved',     // Approved, ready for action
                'rejected',     // Rejected by admin
                'moved_to_internal', // Moved to internal stocks
                'returned_to_vendor', // Returned to vendor
                'disposed'      // Disposed/written off
            ])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable(); // Admin who approved
            $table->timestamp('approved_at')->nullable();
            $table->enum('action_taken', [
                'move_to_internal',
                'return_to_vendor',
                'dispose',
                null
            ])->nullable(); // What action was taken after approval
            $table->unsignedBigInteger('internal_stock_id')->nullable(); // If moved to internal stocks
            $table->unsignedBigInteger('vendor_id')->nullable(); // If returned to vendor
            $table->text('admin_notes')->nullable(); // Admin notes
            $table->text('rejection_reason')->nullable(); // If rejected
            $table->unsignedBigInteger('created_by'); // User who created the return
            $table->unsignedBigInteger('modified_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            // Foreign keys
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('delivery_id')->references('id')->on('delivery')->onDelete('set null'); // Note: table is 'delivery' not 'deliveries'
            $table->foreign('stock_id')->references('id')->on('stocks')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('internal_stock_id')->references('id')->on('internal_stocks')->onDelete('set null');
            $table->foreign('vendor_id')->references('id')->on('vendors')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('modified_by')->references('id')->on('users')->onDelete('set null');

            // Indexes
            $table->index('customer_id');
            $table->index('product_id');
            $table->index('status');
            $table->index('return_date');
            $table->index(['customer_id', 'product_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_returns');
    }
};
