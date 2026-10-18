<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->string('name');
            $table->string('issuer');
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('certificate_url')->nullable();
            $table->boolean('ai_extracted')->default(false);
            $table->timestamps();

            $table->index(['candidate_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_certifications');
    }
};
