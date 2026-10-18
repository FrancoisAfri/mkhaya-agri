<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable audit log — no updated_at, no soft deletes.
        Schema::create('esign_audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('esign_envelopes')->cascadeOnDelete();
            $table->foreignId('signer_id')->nullable()->constrained('esign_signers')->nullOnDelete();
            $table->string('event', 40);
            // envelope_created|sent|viewed|otp_sent|otp_verified|otp_failed|signed
            // declined|completed|voided|expired|reminder_sent
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');          // no updated_at — immutable

            $table->index(['envelope_id', 'created_at']);
            $table->index(['signer_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_audit_entries');
    }
};
