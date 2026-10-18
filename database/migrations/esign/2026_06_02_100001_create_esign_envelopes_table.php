<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_envelopes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('document_type', 40); // appointment_letter|disciplinary|contract|offboarding|other
            $table->string('status', 20)->default('draft'); // draft|sent|in_progress|completed|expired|voided
            $table->string('document_path')->nullable();          // original uploaded doc
            $table->string('signed_document_path')->nullable();   // final doc (future: PDF with embedded sig page)
            $table->string('document_hash', 64)->nullable();      // SHA256 of original for tamper detection
            $table->string('disk', 20)->default('local');         // filesystem disk used
            $table->text('message')->nullable();                   // covering note to signers
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('voided_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            // Linkable to HR entities (polymorphic-style)
            $table->string('related_type')->nullable(); // e.g. App\Modules\HR\Disciplinary\Models\DisciplinaryCase
            $table->unsignedBigInteger('related_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['document_type', 'status']);
            $table->index(['related_type', 'related_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_envelopes');
    }
};
