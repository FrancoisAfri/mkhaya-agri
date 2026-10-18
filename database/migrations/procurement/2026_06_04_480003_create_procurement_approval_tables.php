<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement approval workflow: an approval instance per request with a chain
 * of steps (built from the approval-threshold policy rules), comments and
 * escalations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->index();
            $table->string('status')->default('pending'); // pending|approved|rejected|cancelled
            $table->unsignedInteger('current_step')->default(1);
            $table->unsignedInteger('total_steps')->default(1);
            $table->unsignedBigInteger('started_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('procurement_approval_id')->index();
            $table->unsignedInteger('step');
            $table->string('name');
            $table->string('role')->nullable();
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->string('status')->default('pending'); // pending|approved|rejected|skipped
            $table->text('comment')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('procurement_approval_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('procurement_approval_id')->index();
            $table->unsignedBigInteger('step_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('comment');
            $table->timestamps();
        });

        Schema::create('procurement_escalations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('procurement_approval_id')->index();
            $table->unsignedBigInteger('step_id')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('escalated_to')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_escalations');
        Schema::dropIfExists('procurement_approval_comments');
        Schema::dropIfExists('procurement_approval_steps');
        Schema::dropIfExists('procurement_approvals');
    }
};
