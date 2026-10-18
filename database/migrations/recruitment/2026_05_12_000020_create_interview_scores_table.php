<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interview_scores', function (Blueprint $table) {
            $table->id();
            // One score sheet per interview
            $table->foreignId('interview_id')->unique()->constrained('recruitment_interviews')->cascadeOnDelete();
            $table->foreignId('scored_by')->constrained('users')->restrictOnDelete();
            $table->decimal('technical_score', 5, 2)->nullable();
            $table->decimal('communication_score', 5, 2)->nullable();
            $table->decimal('culture_fit_score', 5, 2)->nullable();
            $table->decimal('overall_score', 5, 2)->nullable();
            $table->enum('recommendation', ['strong_yes', 'yes', 'maybe', 'no', 'strong_no'])->nullable();
            $table->text('comments')->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            $table->index('scored_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_scores');
    }
};
