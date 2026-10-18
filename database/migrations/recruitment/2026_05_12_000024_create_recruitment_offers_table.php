<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_offers', function (Blueprint $table) {
            $table->id();

            // UUID for candidate-portal routes — prevents IDOR
            $table->uuid('uuid')->unique();

            $table->foreignId('application_id')->constrained('recruitment_applications')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('recruitment_jobs')->restrictOnDelete();
            $table->foreignId('candidate_id')->constrained('recruitment_candidates')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('draft');

            // Offer terms
            $table->string('position_title');
            $table->string('department')->nullable();
            $table->date('start_date');
            $table->decimal('salary_amount', 12, 2);
            $table->char('salary_currency', 3)->default('ZAR');
            $table->string('contract_type')->default('permanent');
            $table->text('notes')->nullable();

            // Token for candidate portal access — stored as SHA-256 hash
            // The raw token is only ever in the email link; we never store it
            $table->string('token_hash')->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable(); // 48h from sent_at

            // OTP for step-up verification when accepting offer
            $table->string('otp_hash')->nullable();           // SHA-256 of 6-digit OTP
            $table->timestamp('otp_expires_at')->nullable();  // 15-min OTP window
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->timestamp('otp_locked_at')->nullable();   // locked after 5 failed attempts

            // Lifecycle timestamps
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();      // 48h offer validity window
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();

            $table->timestamps();

            // Fast lookups per candidate / job / status
            $table->index(['candidate_id', 'status']);
            $table->index(['application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_offers');
    }
};
