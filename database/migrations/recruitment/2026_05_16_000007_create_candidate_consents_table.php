<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')
                  ->constrained('recruitment_candidates')
                  ->cascadeOnDelete();
            $table->string('consent_type', 50); // e.g. 'ai_processing', 'data_retention', 'marketing'
            $table->string('status', 20)->default('pending');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['candidate_id', 'consent_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_consents');
    }
};
