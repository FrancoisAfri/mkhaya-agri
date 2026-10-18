<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_ai_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->enum('insight_type', [
                'declining_performance',
                'exceptional_performance',
                'inconsistent_scoring',
                'scoring_bias',
                'kpi_at_risk',
                'trend_analysis',
            ]);
            $table->enum('severity', ['info', 'warning', 'critical'])->default('info');
            $table->string('title', 200);
            $table->text('body');
            $table->json('data')->nullable()->comment('Raw metrics / evidence behind the insight');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pa_performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cycle_id')->constrained('pa_cycles')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('overall_score', 5, 2)->nullable()->comment('Weighted total KPI score %');
            $table->decimal('kpi_completion_rate', 5, 2)->nullable()->comment('% of KPIs with a logged value');
            $table->json('data')->nullable()->comment('Per-KPI breakdown');
            $table->timestamps();

            $table->unique(['employee_id', 'cycle_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_performance_snapshots');
        Schema::dropIfExists('pa_ai_insights');
    }
};
