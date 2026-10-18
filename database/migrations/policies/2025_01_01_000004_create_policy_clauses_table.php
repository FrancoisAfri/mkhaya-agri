<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_clauses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('policy_id')->index();
            $table->unsignedBigInteger('version_id')->nullable()->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->string('clause_reference')->nullable();  // e.g. "Section 3.2"
            $table->enum('type', ['obligation', 'prohibition', 'threshold', 'approval', 'general']);
            $table->text('content');
            $table->text('ai_interpretation')->nullable();
            $table->json('extracted_entities')->nullable();  // amounts, roles, dates found by AI
            $table->boolean('ai_extracted')->default(false);
            $table->boolean('approved')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('policy_id')->references('id')->on('policy')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_clauses');
    }
};
