<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_signature_requests', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('contract_id')
                ->constrained('recruitment_contracts')
                ->cascadeOnDelete();

            // Signatory is either an internal user (HR/manager) or an external candidate
            $table->enum('signatory_type', ['candidate', 'hr_manager', 'witness']);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('candidate_id')
                ->nullable()
                ->constrained('recruitment_candidates')
                ->nullOnDelete();

            // Token: raw value never stored — only SHA-256 hash
            // Compared with hash_equals() to prevent timing attacks
            $table->string('token_hash')->unique();
            $table->timestamp('token_expires_at'); // 7 days from sent

            // OTP for candidate signing
            $table->string('otp_hash')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedSmallInteger('otp_attempts')->default(0);
            $table->timestamp('otp_locked_at')->nullable();

            // Signed evidence
            $table->timestamp('signed_at')->nullable();
            $table->string('signature_ip', 45)->nullable();
            $table->text('signature_user_agent')->nullable();

            $table->timestamps();

            // Each signatory signs a contract once
            $table->unique(['contract_id', 'signatory_type', 'user_id']);

            // Fast lookup by token hash (used in every portal request)
            $table->index('token_hash');
            $table->index(['contract_id', 'signatory_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_signature_requests');
    }
};
