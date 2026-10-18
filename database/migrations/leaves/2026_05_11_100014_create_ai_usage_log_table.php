<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for every AI call made within the leave module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('hr_people')->nullOnDelete();

            $table->string('session_id', 100)->nullable();

            $table->enum('feature', [
                'leave_advisor',
                'decision_explainer',
                'anomaly_detection',
                'workforce_intelligence',
                'policy_analysis',
                'bcea_guidance',
                'parental_advisory',
            ]);

            $table->string('model_used', 100)->nullable();

            // Token accounting
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();

            // PII-tokenized summaries only — never raw employee data
            $table->text('input_summary')->nullable();
            $table->text('output_summary')->nullable();

            // Source reference
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('success')->default(true);
            $table->text('error_message')->nullable();

            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->boolean('pii_tokenized')->default(true);

            // Append-only
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'feature']);
            $table->index('employee_id');
            $table->index('created_at');
            $table->index(['feature', 'success']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_log');
    }
};
