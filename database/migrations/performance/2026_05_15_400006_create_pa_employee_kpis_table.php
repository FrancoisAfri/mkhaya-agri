<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_employee_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('pa_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('goal_id')->nullable()->constrained('pa_goals')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->decimal('target_value', 10, 2)->nullable();
            $table->string('unit', 50)->nullable()->comment('%, count, ZAR, score out of 5, etc.');
            $table->decimal('weighting', 5, 2)->default(0)->comment('Percentage weight of this KPI in total score');
            $table->boolean('self_update_allowed')->default(true);
            $table->boolean('manager_update_allowed')->default(true);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'cancelled'])->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('pa_kpi_progress_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_kpi_id')->constrained('pa_employee_kpis')->cascadeOnDelete();
            $table->decimal('value', 10, 2);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->date('log_date');
            $table->timestamps();
        });

        Schema::create('pa_kpi_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_kpi_id')->constrained('pa_employee_kpis')->cascadeOnDelete();
            $table->string('file_name', 255);
            $table->string('mime', 100)->nullable();
            $table->longText('data')->nullable()->comment('Base64 encoded file content');
            $table->string('uuid', 36)->nullable();
            $table->string('type', 50)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('pa_kpi_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_kpi_id')->unique()->constrained('pa_employee_kpis')->cascadeOnDelete();
            $table->decimal('achieved_value', 10, 2)->nullable();
            $table->decimal('score_pct', 5, 2)->nullable()->comment('Achievement % of target');
            $table->decimal('weighted_score', 5, 2)->nullable()->comment('score_pct × weighting / 100');
            $table->text('notes')->nullable();
            $table->foreignId('scored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scored_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_kpi_scores');
        Schema::dropIfExists('pa_kpi_evidence');
        Schema::dropIfExists('pa_kpi_progress_logs');
        Schema::dropIfExists('pa_employee_kpis');
    }
};
