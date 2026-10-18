<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Main probation record
        Schema::create('pa_probation_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users');
            $table->foreignId('manager_id')->constrained('users');
            $table->foreignId('cycle_id')->nullable()->constrained('pa_cycles')->nullOnDelete();
            $table->string('probation_type')->default('3_month'); // 3_month | 6_month | custom
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status')->default('active'); // active | extended | confirmed | terminated
            $table->string('recommendation')->nullable(); // confirm | extend | terminate
            $table->unsignedSmallInteger('risk_score')->nullable(); // 0–100
            $table->string('risk_level')->nullable();               // low | medium | high | critical
            $table->decimal('success_probability', 5, 2)->nullable();
            $table->text('ai_prediction')->nullable();
            $table->text('ai_summary')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // Objectives / goals set for the probation period
        Schema::create('pa_probation_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('pa_probation_reviews')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('weight')->default(0); // percentage 0–100
            $table->decimal('target_value', 10, 2)->nullable();
            $table->decimal('achieved_value', 10, 2)->nullable();
            $table->string('unit')->nullable();
            $table->string('status')->default('pending'); // pending | in_progress | met | not_met
            $table->text('manager_notes')->nullable();
            $table->timestamps();
        });

        // Scheduled probation check-in meetings
        Schema::create('pa_probation_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('pa_probation_reviews')->cascadeOnDelete();
            $table->string('checkin_type')->default('formal'); // formal | informal
            $table->dateTime('scheduled_at');
            $table->dateTime('completed_at')->nullable();
            $table->text('agenda')->nullable();
            $table->text('notes')->nullable();
            $table->json('action_items')->nullable();
            $table->string('status')->default('scheduled'); // scheduled | completed | missed
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        // Feedback captured during probation (manager / self / peer)
        Schema::create('pa_probation_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('pa_probation_reviews')->cascadeOnDelete();
            $table->foreignId('given_by')->constrained('users');
            $table->string('feedback_type')->default('manager'); // manager | self | peer
            $table->text('content');
            $table->decimal('rating', 3, 1)->nullable(); // 0–5
            $table->boolean('is_draft')->default(false);
            $table->timestamps();
        });

        // Audit trail of every risk calculation run
        Schema::create('pa_probation_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('pa_probation_reviews')->cascadeOnDelete();
            $table->unsignedSmallInteger('risk_score');        // 0–100
            $table->string('risk_level');                      // low | medium | high | critical
            $table->decimal('success_probability', 5, 2)->nullable();
            $table->string('recommendation');                  // confirm | extend | terminate
            $table->json('factors')->nullable();               // score breakdown
            $table->boolean('is_ai_generated')->default(false);
            $table->foreignId('scored_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pa_probation_risk_scores');
        Schema::dropIfExists('pa_probation_feedback');
        Schema::dropIfExists('pa_probation_checkins');
        Schema::dropIfExists('pa_probation_objectives');
        Schema::dropIfExists('pa_probation_reviews');
    }
};
