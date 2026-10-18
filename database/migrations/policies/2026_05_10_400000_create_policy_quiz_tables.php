<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Questions ──────────────────────────────────────────────────────────
        Schema::create('policy_questions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id');
            $table->text('question_text');
            $table->enum('type', ['multiple_choice', 'true_false', 'short_answer'])->default('multiple_choice');
            $table->jsonb('options')->nullable(); // [{label, value}] for multiple_choice
            $table->text('correct_answer');       // value string (e.g. "true", "B", free text)
            $table->text('explanation')->nullable(); // shown after attempt
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedTinyInteger('points')->default(1);
            $table->boolean('ai_generated')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('policy_id')->references('id')->on('policy')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['policy_id', 'sort_order']);
        });

        // ── Per-policy quiz settings ──────────────────────────────────────────
        Schema::create('policy_quiz_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->unique();
            $table->unsignedTinyInteger('pass_mark')->default(70);       // percentage 0-100
            $table->unsignedTinyInteger('max_attempts')->default(3);     // 0 = unlimited
            $table->unsignedSmallInteger('time_limit_minutes')->nullable(); // null = no limit
            $table->boolean('randomize_questions')->default(false);
            $table->boolean('show_correct_answers')->default(true);
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('policy')->cascadeOnDelete();
        });

        // ── Employee attempts (audit trail) ──────────────────────────────────
        Schema::create('policy_quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('attestation_id')->nullable(); // link to attestation if quiz is part of it
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->unsignedSmallInteger('total_questions')->default(0);
            $table->unsignedSmallInteger('correct_answers_count')->default(0);
            $table->unsignedTinyInteger('score')->default(0);  // percentage 0-100
            $table->boolean('passed')->default(false);
            $table->jsonb('answers')->nullable(); // [{question_id, given_answer, correct_answer, is_correct}]
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('policy_id')->references('id')->on('policy')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('attestation_id')->references('id')->on('policy_attestations')->nullOnDelete();

            $table->index(['policy_id', 'user_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_quiz_attempts');
        Schema::dropIfExists('policy_quiz_settings');
        Schema::dropIfExists('policy_questions');
    }
};
