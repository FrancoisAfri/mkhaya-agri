<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pa_talent_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->decimal('promotion_readiness_score', 5, 2)->default(0);
            // not_ready / developing / ready / ready_now
            $table->string('promotion_readiness_level')->default('not_ready');
            $table->decimal('leadership_score', 5, 2)->default(0);
            $table->boolean('is_leadership_pipeline')->default(false);
            // emerging / contributor / high_performer / top_talent
            $table->string('talent_tier')->default('contributor');
            $table->decimal('kpi_composite_score', 5, 2)->nullable();
            $table->unsignedSmallInteger('skill_breadth')->default(0);
            $table->decimal('skill_depth_avg', 5, 2)->default(0);
            $table->decimal('composite_score', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'cycle_id']);
            $table->index('talent_tier');
            $table->index('is_leadership_pipeline');
        });

        Schema::create('pa_learning_recommendations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('development_plan_id')->nullable();
            $table->unsignedBigInteger('cycle_id')->nullable();
            $table->unsignedBigInteger('skill_id')->nullable();
            // course / book / certification / workshop / mentoring / project / conference
            $table->string('recommendation_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('provider')->nullable();
            $table->unsignedSmallInteger('estimated_hours')->nullable();
            // low / medium / high / critical
            $table->string('priority')->default('medium');
            $table->boolean('ai_generated')->default(false);
            // pending / in_progress / completed / skipped
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['cycle_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_learning_recommendations');
        Schema::dropIfExists('pa_talent_profiles');
    }
};
