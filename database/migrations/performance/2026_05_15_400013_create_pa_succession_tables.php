<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Critical positions requiring succession plans
        Schema::create('pa_succession_plans', function (Blueprint $table) {
            $table->id();
            $table->string('position_title');
            $table->string('department')->nullable();
            $table->foreignId('incumbent_id')->nullable()->constrained('users')->nullOnDelete(); // current holder
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->boolean('is_critical_role')->default(true);
            $table->string('status')->default('active'); // active | review | closed
            $table->unsignedTinyInteger('bench_strength')->nullable(); // 0–100 computed
            $table->text('notes')->nullable();
            $table->text('ai_analysis')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // Candidates for each succession plan
        Schema::create('pa_succession_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('pa_succession_plans')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users');
            $table->string('readiness_level'); // ready_now | 1_year | 2_years | not_ready
            $table->unsignedTinyInteger('potential_rating')->default(3); // 1–5
            $table->string('risk_of_loss')->default('medium'); // low | medium | high
            $table->string('retention_priority')->default('normal'); // normal | high | critical
            $table->decimal('ai_readiness_score', 5, 2)->nullable(); // 0–100
            $table->text('ai_recommendation')->nullable();
            $table->text('notes')->nullable();
            $table->unique(['plan_id', 'employee_id']);
            $table->timestamps();
        });

        // High-potential employee flags (org-wide)
        Schema::create('pa_high_potentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->decimal('composite_score', 5, 2);     // 0–100
            $table->string('potential_band');              // emerging | high | exceptional
            $table->json('factors')->nullable();           // score breakdown
            $table->boolean('is_ai_generated')->default(false);
            $table->foreignId('identified_by')->constrained('users');
            $table->unique(['employee_id', 'cycle_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_high_potentials');
        Schema::dropIfExists('pa_succession_candidates');
        Schema::dropIfExists('pa_succession_plans');
    }
};
