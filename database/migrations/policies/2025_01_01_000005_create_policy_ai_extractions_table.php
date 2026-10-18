<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_ai_extractions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('document_id')->index();
            $table->string('ai_provider');                   // 'claude', 'openai', 'gemini'
            $table->string('ai_model')->nullable();
            $table->json('raw_prompt');
            $table->json('raw_response');
            $table->json('parsed_output')->nullable();       // normalised clauses array
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('tokens_used')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('policy_documents')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_ai_extractions');
    }
};
