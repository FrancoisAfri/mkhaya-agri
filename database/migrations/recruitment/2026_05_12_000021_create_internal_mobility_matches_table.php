<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_mobility_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('recruitment_jobs')->cascadeOnDelete();
            $table->decimal('match_score', 5, 2);
            $table->enum('match_type', ['lateral', 'promotion', 'transfer']);
            $table->boolean('ai_generated')->default(true);
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            $table->unique(['candidate_id', 'job_id']);
            $table->index(['job_id', 'match_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_mobility_matches');
    }
};
