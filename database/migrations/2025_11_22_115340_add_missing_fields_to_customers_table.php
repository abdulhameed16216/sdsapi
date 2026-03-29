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
            // Add mobile_number column
            if (!Schema::hasColumn('customers', 'mobile_number')) {
                $table->string('mobile_number', 15)->nullable()->after('phone');
            }
            
            // Add nature_of_account column
            if (!Schema::hasColumn('customers', 'nature_of_account')) {
                $table->enum('nature_of_account', ['litre', 'cuppage', 'consumables'])->nullable()->after('customer_type');
            }
            
            // Add agreement_start_date column
            if (!Schema::hasColumn('customers', 'agreement_start_date')) {
                $table->date('agreement_start_date')->nullable()->after('notes');
            }
            
            // Add agreement_end_date column
            if (!Schema::hasColumn('customers', 'agreement_end_date')) {
                $table->date('agreement_end_date')->nullable()->after('agreement_start_date');
            }
            
            // Add documents column (JSON to store array of document paths)
            if (!Schema::hasColumn('customers', 'documents')) {
                $table->json('documents')->nullable()->after('agreement_end_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'mobile_number')) {
                $table->dropColumn('mobile_number');
            }
            if (Schema::hasColumn('customers', 'nature_of_account')) {
                $table->dropColumn('nature_of_account');
            }
            if (Schema::hasColumn('customers', 'agreement_start_date')) {
                $table->dropColumn('agreement_start_date');
            }
            if (Schema::hasColumn('customers', 'agreement_end_date')) {
                $table->dropColumn('agreement_end_date');
            }
            if (Schema::hasColumn('customers', 'documents')) {
                $table->dropColumn('documents');
            }
        });
    }
};
