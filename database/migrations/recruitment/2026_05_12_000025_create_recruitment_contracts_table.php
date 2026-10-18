<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_contracts', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('offer_id')->constrained('recruitment_offers')->cascadeOnDelete();
            $table->foreignId('application_id')->constrained('recruitment_applications')->restrictOnDelete();
            $table->foreignId('job_id')->constrained('recruitment_jobs')->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->string('status')->default('draft');

            // Contract content — generated from template, stored as HTML
            $table->unsignedBigInteger('template_id')->nullable();
            $table->longText('content')->nullable();
            $table->string('pdf_path')->nullable(); // Private S3 path

            // Lifecycle
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable(); // 7 days for signing
            $table->timestamp('fully_signed_at')->nullable();

            $table->timestamps();

            $table->index(['candidate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_contracts');
    }
};
