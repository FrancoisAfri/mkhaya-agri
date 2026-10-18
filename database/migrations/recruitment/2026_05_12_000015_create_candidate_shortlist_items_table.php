<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_shortlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shortlist_id')->constrained('recruitment_shortlists')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->restrictOnDelete();
            $table->foreignId('application_id')->constrained('recruitment_applications')->restrictOnDelete();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->boolean('ai_recommended')->default(true);
            $table->timestamps();

            $table->unique(['shortlist_id', 'candidate_id']);
            $table->index(['shortlist_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_shortlist_items');
    }
};
