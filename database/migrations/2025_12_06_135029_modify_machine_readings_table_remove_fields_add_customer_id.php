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
        Schema::table('machine_readings', function (Blueprint $table) {
            // Drop columns that are no longer needed
            $table->dropColumn(['reading_value', 'unit', 'notes']);
            
            // Add customer_id column
            $table->unsignedBigInteger('customer_id')->nullable()->after('user_id');
            
            // Add foreign key for customer_id
            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            
            // Add index for customer_id
            $table->index('customer_id');
            
            // Update unique constraint to include customer_id
            $table->dropUnique('machine_readings_machine_date_unique');
            $table->unique(['customer_id', 'machine_id', 'reading_date'], 'machine_readings_customer_machine_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machine_readings', function (Blueprint $table) {
            // Drop foreign key and index
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id']);
            
            // Drop unique constraint
            $table->dropUnique('machine_readings_customer_machine_date_unique');
            
            // Restore original unique constraint
            $table->unique(['machine_id', 'reading_date'], 'machine_readings_machine_date_unique');
            
            // Drop customer_id column
            $table->dropColumn('customer_id');
            
            // Restore dropped columns
            $table->decimal('reading_value', 10, 2)->default(0)->after('reading_date');
            $table->string('unit')->nullable()->after('reading_value');
            $table->text('notes')->nullable()->after('unit');
        });
    }
};
