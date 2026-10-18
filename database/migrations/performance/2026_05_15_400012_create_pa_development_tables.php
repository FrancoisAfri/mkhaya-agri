<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global skills library
        Schema::create('pa_skills', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category'); // technical | soft | leadership | domain | compliance
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Employee skill profiles with proficiency levels
        Schema::create('pa_employee_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('skill_id')->constrained('pa_skills')->cascadeOnDelete();
            $table->unsignedTinyInteger('current_level')->default(1); // 1=beginner … 5=expert
            $table->unsignedTinyInteger('target_level')->default(3);
            $table->boolean('self_rated')->default(true);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('evidence')->nullable();
            $table->unique(['employee_id', 'skill_id']);
            $table->timestamps();
        });

        // Individual Development Plans
        Schema::create('pa_development_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('manager_id')->constrained('users');
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->string('title');
            $table->text('career_goal')->nullable();
            $table->text('strengths')->nullable();
            $table->text('development_areas')->nullable();
            $table->string('status')->default('draft'); // draft | active | completed | paused
            $table->unsignedTinyInteger('overall_progress')->default(0); // 0–100 %
            $table->text('ai_recommendations')->nullable();
            $table->text('manager_notes')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // Activities within an IDP
        Schema::create('pa_development_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('pa_development_plans')->cascadeOnDelete();
            $table->string('title');
            $table->string('activity_type'); // training | mentoring | project | certification | reading | shadowing | coaching
            $table->text('description')->nullable();
            $table->date('target_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->string('status')->default('pending'); // pending | in_progress | completed | cancelled
            $table->string('provider')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->text('evidence_notes')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_development_activities');
        Schema::dropIfExists('pa_development_plans');
        Schema::dropIfExists('pa_employee_skills');
        Schema::dropIfExists('pa_skills');
    }
};
