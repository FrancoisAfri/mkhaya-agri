<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copilot_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('session_token')->unique();
            $table->json('messages');                        // [{role, content, clause_refs, timestamp}]
            $table->json('referenced_policies')->nullable(); // policy IDs surfaced in session
            $table->json('referenced_clauses')->nullable();  // clause IDs cited in answers
            $table->unsignedInteger('total_tokens_used')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_sessions');
    }
};
