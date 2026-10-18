<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_question_id')->constrained('interview_questions')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->restrictOnDelete();
            $table->text('response')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['interview_question_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_responses');
    }
};
