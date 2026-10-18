<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            
            $table->enum('document_type', [
                'invoice',
                'warranty',
                'manual',
                'certificate',
                'insurance',
                'other'
            ]);
            
            $table->string('document_name');
            $table->string('file_path');
            $table->bigInteger('file_size')->comment('Bytes');
            $table->string('mime_type');
            
            $table->foreignId('uploaded_by_user_id')->constrained('users');
            $table->timestamp('uploaded_at');
            
            $table->enum('extraction_status', [
                'pending',
                'processing',
                'completed',
                'failed'
            ])->default('pending');
            
            $table->timestamp('extraction_started_at')->nullable();
            $table->timestamp('extraction_completed_at')->nullable();
            
            $table->timestamps();
            
            $table->index(['asset_id', 'document_type']);
            $table->index('extraction_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_documents');
    }
};
