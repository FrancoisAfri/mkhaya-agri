<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survey planner — schedule a survey for a future date (one-off) or on a
 * recurring cadence (weekly / monthly / quarterly / yearly). When a plan is due
 * the system creates a survey from its snapshot, sends it and schedules the next
 * run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_survey_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 40)->default('general');
            $table->json('questions');                  // snapshot used for each wave
            $table->json('audience')->nullable();
            $table->string('cadence', 12)->default('once'); // once|weekly|monthly|quarterly|yearly
            $table->timestamp('next_run_at');
            $table->unsignedSmallInteger('deadline_days')->default(14); // survey deadline = sent + N days
            $table->string('thank_you')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('runs_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_survey_plans');
    }
};
