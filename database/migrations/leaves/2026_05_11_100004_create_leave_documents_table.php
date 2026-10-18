<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->foreignId('uploader_id')->constrained('users')->restrictOnDelete();

            // Document classification
            $table->string('document_type', 100)
                ->comment('medical_certificate|supporting_doc|birth_certificate|court_order|other');
            $table->string('original_filename', 255);
            $table->string('stored_filename', 255);
            $table->string('storage_path', 500);
            $table->string('disk', 50)->default('private');

            // File metadata
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->string('mime_type', 100)->nullable();

            // Security
            $table->boolean('is_encrypted')->default(true);
            $table->string('encryption_key_id', 100)->nullable();
            $table->boolean('access_log_enabled')->default(true);

            // Virus scan
            $table->timestamp('virus_scanned_at')->nullable();
            $table->boolean('is_clean')->nullable();

            // Lifecycle
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('leave_request_id');
            $table->index('uploader_id');
            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_documents');
    }
};
