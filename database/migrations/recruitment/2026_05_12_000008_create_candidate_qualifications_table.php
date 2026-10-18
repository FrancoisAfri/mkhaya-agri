<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->string('institution');
            $table->enum('qualification_type', [
                'certificate', 'diploma', 'degree', 'honours', 'masters', 'doctorate', 'other',
            ]);
            $table->string('field_of_study');
            $table->year('year_completed')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->boolean('ai_extracted')->default(false);
            $table->timestamps();

            $table->index(['candidate_id', 'qualification_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_qualifications');
    }
};
