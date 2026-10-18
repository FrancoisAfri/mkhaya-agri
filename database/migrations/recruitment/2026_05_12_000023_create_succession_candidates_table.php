<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('succession_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('recruitment_jobs')->cascadeOnDelete();
            $table->enum('readiness_level', ['ready_now', 'ready_in_1_year', 'ready_in_2_years', 'development_needed']);
            $table->text('notes')->nullable();
            $table->foreignId('nominated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['candidate_id', 'job_id']);
            $table->index(['job_id', 'readiness_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('succession_candidates');
    }
};
