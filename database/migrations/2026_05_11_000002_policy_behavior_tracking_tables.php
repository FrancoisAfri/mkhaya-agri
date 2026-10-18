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
        // Store individual violation patterns per user
        Schema::create('policy_violation_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Violation counts by severity
            $table->unsignedInteger('violation_count')->default(0);
            $table->unsignedInteger('critical_count')->default(0); // score >= 85
            $table->unsignedInteger('high_count')->default(0);     // score >= 70
            $table->unsignedInteger('medium_count')->default(0);   // score >= 40

            // Total severity (for averaging)
            $table->unsignedInteger('total_severity_score')->default(0);

            // Near-miss tracking (warnings, escalations)
            $table->unsignedInteger('near_miss_count')->default(0);

            // Exception/override tracking
            $table->unsignedInteger('approval_override_count')->default(0);

            // Temporal info
            $table->timestamp('last_violation_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->unique('user_id');
            $table->index('violation_count');
            $table->index('last_violation_at');
        });

        // Track near-miss incidents (warnings, escalations without violation)
        Schema::create('policy_near_misses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('rule_id')
                ->constrained('policy_rules')
                ->onDelete('cascade');
            $table->foreignId('policy_id')
                ->constrained('policy')
                ->onDelete('cascade');

            $table->string('module');        // leave, expense, etc.
            $table->string('action');        // submit, approve, etc.
            $table->string('reason');        // why action wasn't taken

            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('rule_id');
            $table->index('recorded_at');
        });

        // Track when exceptions/approvals override rules
        Schema::create('policy_approval_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('rule_id')
                ->constrained('policy_rules')
                ->onDelete('cascade');
            $table->foreignId('policy_id')
                ->constrained('policy')
                ->onDelete('cascade');

            $table->unsignedInteger('violation_count'); // How many violations were bypassed
            $table->foreignId('approved_by')
                ->constrained('users')
                ->onDelete('set null');

            $table->text('reason')->nullable();           // Why exception was granted
            $table->timestamp('approved_at')->useCurrent();
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('approved_by');
            $table->index('approved_at');
        });

        // Track repeat violations (same rule multiple times)
        Schema::create('policy_repeat_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('violation_id')
                ->constrained('policy_violations')
                ->onDelete('cascade');
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('rule_id')
                ->constrained('policy_rules')
                ->onDelete('cascade');

            $table->unsignedInteger('prior_count');      // How many times violated this rule before
            $table->timestamps();

            // Indexes
            $table->index('user_id');
            $table->index('rule_id');
            $table->unique(['violation_id']);
        });

        // Track violations per rule per user (for anomaly detection)
        Schema::create('policy_rule_violations_by_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('rule_id')
                ->constrained('policy_rules')
                ->onDelete('cascade');

            $table->unsignedInteger('violation_count')->default(0);
            $table->timestamp('last_violation_at')->nullable();
            $table->timestamps();

            // Unique per user+rule combination
            $table->unique(['user_id', 'rule_id']);
        });

        // Track violations per policy per user
        Schema::create('policy_policy_violations_by_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->foreignId('policy_id')
                ->constrained('policy')
                ->onDelete('cascade');

            $table->unsignedInteger('violation_count')->default(0);
            $table->timestamp('last_violation_at')->nullable();
            $table->timestamps();

            // Unique per user+policy combination
            $table->unique(['user_id', 'policy_id']);
        });

        // Track violations per rule per department
        Schema::create('policy_department_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')
                ->constrained('company_units')
                ->onDelete('cascade');
            $table->foreignId('rule_id')
                ->constrained('policy_rules')
                ->onDelete('cascade');

            $table->unsignedInteger('violation_count')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->timestamp('last_violation_at')->nullable();
            $table->timestamps();

            // Unique per department+rule combination
            $table->unique(['department_id', 'rule_id']);
        });

        // Track temporal patterns (when violations happen)
        Schema::create('policy_temporal_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->unsignedTinyInteger('hour_of_day');   // 0-23
            $table->unsignedTinyInteger('day_of_week');   // 0-6 (Sunday-Saturday)
            $table->unsignedInteger('occurrence_count')->default(0);

            $table->timestamps();

            // Unique per user+hour+day combination
            $table->unique(['user_id', 'hour_of_day', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_temporal_patterns');
        Schema::dropIfExists('policy_department_violations');
        Schema::dropIfExists('policy_policy_violations_by_user');
        Schema::dropIfExists('policy_rule_violations_by_user');
        Schema::dropIfExists('policy_repeat_violations');
        Schema::dropIfExists('policy_approval_overrides');
        Schema::dropIfExists('policy_near_misses');
        Schema::dropIfExists('policy_violation_patterns');
    }
};
