<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting recordings — uploaded audio is transcribed and summarised by AI, the
 * summary is saved to the related record's notes, and a follow-up meeting can be
 * scheduled from the AI's recommendation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_meetings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('related_type', 12)->nullable();  // lead|contact|account|deal
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('audio_path')->nullable();
            $table->longText('transcript')->nullable();
            $table->longText('summary')->nullable();
            $table->json('action_items')->nullable();
            $table->string('sentiment', 10)->nullable();
            $table->boolean('followup_suggested')->default(false);
            $table->string('followup_reason')->nullable();
            $table->string('status', 16)->default('uploaded'); // uploaded|transcribing|done|failed
            $table->string('status_message')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_meetings');
    }
};
