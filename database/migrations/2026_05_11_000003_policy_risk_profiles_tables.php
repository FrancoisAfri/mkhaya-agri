<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
            // Skip if tables already created by PHASE 6 migration
            if (Schema::hasTable('policy_risk_profiles')) {
                return;
            }
        
        // Current risk profiles for users and departments
        Schema::create('policy_risk_profiles', function (Blueprint $table) {
            $table->id();

            // Either user_id or department_id (one must be set)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('cascade');
            $table->unsignedBigInteger('department_id')->nullable();

            // Risk score 0-100
            $table->unsignedSmallInteger('risk_score')->default(0);

            // Risk category: critical|high|medium|low|minimal
            $table->enum('risk_category', ['critical', 'high', 'medium', 'low', 'minimal'])->default('minimal');

            // Breakdown of risk components (JSON)
            // For users: {violation_frequency, severity_level, recency, repeat_violations, near_misses, approval_overrides}
            // For departments: {average_user_risk, high_risk_percentage, violating_users, total_violations}
            $table->json('breakdown')->nullable();

            // When the risk was calculated
            $table->timestamp('calculated_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('department_id');
            $table->index('risk_score');
            $table->index('risk_category');
            $table->index('calculated_at');

            // Ensure only one of user_id or department_id is set
            $table->unique('user_id');
            $table->unique('department_id');
        });

        // Historical risk score tracking
        Schema::create('policy_risk_profile_history', function (Blueprint $table) {
            $table->id();

            // Either user_id or department_id
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('cascade');
            $table->unsignedBigInteger('department_id')->nullable();

            // Risk score at this point in time
            $table->unsignedSmallInteger('risk_score')->default(0);

            // Category at this point
            $table->enum('risk_category', ['critical', 'high', 'medium', 'low', 'minimal'])->default('minimal');

            // When recorded
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            // Indexes for time-series queries
            $table->index('user_id');
            $table->index('department_id');
            $table->index('recorded_at');
            $table->index(['user_id', 'recorded_at']);
            $table->index(['department_id', 'recorded_at']);
        });

        // Risk alerts/thresholds for monitoring
        Schema::create('policy_risk_alerts', function (Blueprint $table) {
            $table->id();

            // Either user_id or department_id
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('cascade');
            $table->unsignedBigInteger('department_id')->nullable();

            // Alert triggers when risk crosses threshold
            $table->unsignedSmallInteger('risk_score');
            $table->enum('alert_type', [
                'threshold_exceeded',  // Score exceeded threshold
                'trend_increasing',    // Trend is increasing
                'repeated_violation',  // Same rule violated multiple times
                'critical_severity',   // Critical violation occurred
                'high_override_count', // Too many exceptions granted
            ]);

            // Status of alert
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');

            // Who should review this alert
            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->onDelete('set null');

            // Notes about the alert
            $table->text('notes')->nullable();

            // Timestamps
            $table->timestamp('triggered_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('department_id');
            $table->index('status');
            $table->index('alert_type');
            $table->index('triggered_at');
        });

        // Risk score thresholds (configurable per organization)
        Schema::create('policy_risk_thresholds', function (Blueprint $table) {
            $table->id();

            $table->string('threshold_name');         // 'critical', 'high', 'medium', 'low'
            $table->unsignedSmallInteger('score');    // Score threshold (0-100)
            $table->string('description')->nullable();

            // Actions to take when score reaches this level
            $table->json('actions')->nullable();       // ['notify_manager', 'restrict_access', 'escalate']

            $table->timestamps();

            $table->unique('threshold_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_risk_thresholds');
        Schema::dropIfExists('policy_risk_alerts');
        Schema::dropIfExists('policy_risk_profile_history');
        Schema::dropIfExists('policy_risk_profiles');
    }
};
