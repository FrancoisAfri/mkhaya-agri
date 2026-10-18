<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nl_rule_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('created_by')->index();
            $table->text('natural_language_input');
            $table->json('ai_parsed_rule')->nullable();      // structured rule proposal from AI
            $table->json('user_edits')->nullable();          // user modifications before saving
            $table->enum('status', ['pending_review', 'approved', 'rejected', 'converted'])->default('pending_review');
            $table->unsignedBigInteger('converted_rule_id')->nullable(); // FK to policy_rules if approved
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nl_rule_drafts');
    }
};
