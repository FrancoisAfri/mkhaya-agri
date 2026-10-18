<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_document_extractions', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('uploaded_by');
            $table->unsignedInteger('asset_id')->nullable();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('file_size');
            $table->string('mime_type', 80);
            $table->string('extraction_status', 20)->default('pending')->index();
            $table->json('extracted_fields')->nullable();
            $table->json('confirmed_fields')->nullable();
            $table->text('extraction_error')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('confirmed_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedInteger('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['uploaded_by', 'extraction_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_document_extractions');
    }
};
