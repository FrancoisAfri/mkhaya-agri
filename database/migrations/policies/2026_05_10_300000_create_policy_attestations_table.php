<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_attestations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('policy_version_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('deadline')->nullable()->index();
            $table->unsignedSmallInteger('quiz_score')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'overdue'])->default('pending')->index();
            $table->string('signature_token', 64)->nullable()->unique(); // digital sign token
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'user_id', 'policy_version_id'], 'pa_policy_user_version_unique');
            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('policy_version_id')->references('id')->on('policy_versions')->onDelete('set null');
            $table->foreign('campaign_id')->references('id')->on('attestation_campaigns')->onDelete('set null');
        });

        // Track individual reminder sends to avoid duplicate reminders
        Schema::create('policy_attestation_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attestation_id')->index();
            $table->enum('type', ['reminder', 'escalation'])->default('reminder');
            $table->timestamp('sent_at')->useCurrent();
            $table->unsignedBigInteger('escalated_to')->nullable(); // manager user_id

            $table->foreign('attestation_id')->references('id')->on('policy_attestations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_attestation_reminders');
        Schema::dropIfExists('policy_attestations');
    }
};
