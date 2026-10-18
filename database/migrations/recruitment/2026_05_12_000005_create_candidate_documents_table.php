<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->enum('type', ['cv', 'id_document', 'certificate', 'qualification', 'reference', 'other']);
            // Private S3 path — served via signed URLs (15min TTL), never exposed directly
            $table->string('path');
            $table->string('mime_type', 100);
            $table->string('original_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamp('virus_scanned_at')->nullable();
            $table->boolean('virus_clean')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['candidate_id', 'type']);
            $table->index('virus_scanned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_documents');
    }
};
