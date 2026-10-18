<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('version_id')->nullable()->index();
            $table->string('filename');
            $table->string('disk')->default('local');        // 'local', 's3', etc.
            $table->string('path');
            $table->string('mime_type');                     // application/pdf, application/msword, etc.
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->enum('extraction_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_documents');
    }
};
