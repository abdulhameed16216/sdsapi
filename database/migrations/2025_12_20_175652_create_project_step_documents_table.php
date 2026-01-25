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
        Schema::create('project_step_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->onDelete('cascade');
            $table->foreignId('project_step_id')->constrained('project_steps')->onDelete('cascade');
            $table->string('name'); // Original file name
            $table->string('file_name'); // Stored file name
            $table->string('file_path'); // Relative file path
            $table->string('file_url'); // Full URL to access the file
            $table->integer('file_size')->nullable(); // File size in bytes
            $table->string('mime_type')->nullable(); // MIME type
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_step_documents');
    }
};
