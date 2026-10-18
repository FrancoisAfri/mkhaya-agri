<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('hr_person_id')->constrained('hr_persons')->cascadeOnDelete();
            $table->string('document_type', 50); // id_document|passport|proof_of_address|banking_details|tax_number|emergency_contact|qualification|other
            $table->string('label', 150);
            $table->boolean('is_required')->default(true);
            $table->string('status', 30)->default('pending'); // pending|submitted|approved|rejected
            $table->string('file_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['hr_person_id', 'status']);
            $table->index(['hr_person_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_documents');
    }
};
