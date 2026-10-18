<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_signers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('envelope_id')->constrained('esign_envelopes')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('role', 30)->default('signer'); // employee|manager|hr|witness|authorized_signatory|signer
            $table->unsignedTinyInteger('signing_order')->default(1);
            $table->string('status', 20)->default('pending'); // pending|notified|viewed|otp_sent|otp_verified|signed|declined
            $table->string('token', 64)->nullable()->unique(); // signing link token — set at send time
            $table->timestamp('token_expires_at')->nullable();
            // Consent
            $table->boolean('consent_given')->default(false);
            $table->timestamp('consent_given_at')->nullable();
            // Signature
            $table->text('signature_data')->nullable();        // base64 drawn or typed name
            $table->string('signature_type', 20)->nullable();  // drawn|typed
            $table->timestamp('signed_at')->nullable();
            $table->string('declined_reason')->nullable();
            $table->timestamp('declined_at')->nullable();
            // Audit
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['envelope_id', 'signing_order']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_signers');
    }
};
