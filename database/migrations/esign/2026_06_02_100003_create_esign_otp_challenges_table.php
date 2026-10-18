<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_otp_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signer_id')->constrained('esign_signers')->cascadeOnDelete();
            $table->string('code_hash');              // bcrypt hash of the 6-digit code
            $table->string('channel', 10)->default('email'); // email|sms
            $table->string('sent_to');               // email address or phone number
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['signer_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_otp_challenges');
    }
};
