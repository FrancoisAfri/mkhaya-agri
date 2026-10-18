<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_ai_profiles', function (Blueprint $table) {
            $table->id();
            // One AI profile per candidate
            $table->foreignId('candidate_id')->unique()->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->text('summary')->nullable();
            $table->json('strengths')->nullable();
            $table->json('weaknesses')->nullable();
            $table->json('flags')->nullable();
            $table->json('keywords')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('ai_model_used', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_ai_profiles');
    }
};
