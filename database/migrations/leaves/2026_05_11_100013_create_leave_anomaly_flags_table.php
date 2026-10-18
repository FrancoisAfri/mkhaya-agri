<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_anomaly_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_people')->cascadeOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->nullOnDelete();

            $table->enum('anomaly_type', [
                'monday_friday_abuse',
                'holiday_abuse',
                'excessive_sick',
                'negative_balance',
                'burnout_risk',
                'understaffing_risk',
                'pattern_detected',
                'concurrent_abuse',
                'policy_violation',
            ]);

            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');

            $table->string('ai_model_version', 50)->nullable();
            $table->decimal('ai_confidence_score', 5, 2)->nullable();
            $table->enum('detection_method', ['rule_based', 'ai_ml', 'hybrid'])->default('rule_based');

            $table->text('description')->nullable();
            $table->json('evidence')->nullable()
                ->comment('Supporting data points that triggered this flag');

            $table->enum('status', ['open', 'under_review', 'resolved', 'dismissed', 'escalated'])
                ->default('open');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->boolean('false_positive')->default(false);

            $table->timestamp('detected_at')->useCurrent();
            $table->timestamps();

            $table->index(['employee_id', 'anomaly_type', 'status']);
            $table->index(['severity', 'status']);
            $table->index('detected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_anomaly_flags');
    }
};
