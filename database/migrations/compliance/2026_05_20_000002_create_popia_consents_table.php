<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('popia_consents', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('purpose', 100);
            $table->string('status', 20)->default('granted'); // granted|declined|revoked
            $table->string('consent_version', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'consents_subject_idx');
            $table->index(['subject_type', 'subject_id', 'purpose'], 'consents_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popia_consents');
    }
};
