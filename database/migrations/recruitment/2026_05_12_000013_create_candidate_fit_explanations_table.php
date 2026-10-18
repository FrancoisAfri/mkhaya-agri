<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_fit_explanations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fit_score_id')->constrained('candidate_fit_scores')->cascadeOnDelete();
            $table->enum('dimension', ['skills', 'experience', 'qualification', 'cultural']);
            $table->text('explanation');
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['fit_score_id', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_fit_explanations');
    }
};
