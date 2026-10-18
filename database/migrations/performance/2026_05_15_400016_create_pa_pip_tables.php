<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_pip_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('manager_id');
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->string('title');
            $table->text('reason'); // why PIP was initiated
            $table->date('start_date');
            $table->date('target_end_date');
            $table->date('actual_end_date')->nullable();
            // draft / active / extended / closed_improved / closed_not_improved / escalated
            $table->string('status')->default('draft');
            $table->text('outcome_notes')->nullable();
            $table->text('ai_coaching')->nullable();
            $table->text('ai_outcome_recommendation')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['cycle_id', 'status']);
        });

        Schema::create('pa_pip_objectives', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pip_plan_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('metric')->nullable(); // how success is measured
            $table->string('target_value')->nullable();
            $table->unsignedTinyInteger('weight')->default(100);
            // not_started / in_progress / met / not_met
            $table->string('status')->default('not_started');
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->foreign('pip_plan_id')->references('id')->on('pa_pip_plans')->cascadeOnDelete();
        });

        Schema::create('pa_pip_progress_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pip_plan_id');
            $table->unsignedBigInteger('objective_id')->nullable();
            $table->unsignedBigInteger('logged_by');
            $table->unsignedTinyInteger('progress_pct')->default(0); // 0–100
            $table->text('notes')->nullable();
            $table->date('logged_at');
            $table->timestamps();

            $table->foreign('pip_plan_id')->references('id')->on('pa_pip_plans')->cascadeOnDelete();
            $table->index(['pip_plan_id', 'logged_at']);
        });

        Schema::create('pa_pip_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pip_plan_id');
            $table->unsignedBigInteger('conducted_by');
            $table->date('scheduled_date');
            // scheduled / completed / cancelled
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            // on_track / needs_attention / at_risk / not_improving
            $table->string('outcome')->nullable();
            $table->timestamps();

            $table->foreign('pip_plan_id')->references('id')->on('pa_pip_plans')->cascadeOnDelete();
            $table->index(['pip_plan_id', 'scheduled_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_pip_reviews');
        Schema::dropIfExists('pa_pip_progress_logs');
        Schema::dropIfExists('pa_pip_objectives');
        Schema::dropIfExists('pa_pip_plans');
    }
};
