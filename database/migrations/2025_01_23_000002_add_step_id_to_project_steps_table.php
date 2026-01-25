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
        Schema::table('project_steps', function (Blueprint $table) {
            $table->integer('step_id')->nullable()->after('project_id')->comment('Step number (1-5)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_steps', function (Blueprint $table) {
            $table->dropColumn('step_id');
        });
    }
};

