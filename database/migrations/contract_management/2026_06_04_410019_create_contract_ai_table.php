<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI analysis of a contract: extracted metadata, obligations, key dates, and
 * risk/compliance/complexity scoring produced by the AI agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_ai', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id')->unique();
            $table->string('status')->default('pending'); // pending|processing|completed|failed
            $table->string('provider')->nullable();
            $table->string('model')->nullable();

            $table->text('summary')->nullable();
            $table->json('parties')->nullable();
            $table->json('key_dates')->nullable();
            $table->json('obligations')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('termination')->nullable();
            $table->text('renewal_terms')->nullable();

            $table->decimal('risk_score', 5, 2)->nullable();
            $table->decimal('compliance_score', 5, 2)->nullable();
            $table->decimal('complexity_score', 5, 2)->nullable();
            $table->json('risk_factors')->nullable();
            $table->json('key_points')->nullable();
            $table->json('recommendations')->nullable();

            $table->unsignedInteger('tokens_used')->nullable();
            $table->longText('raw_response')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('analyzed_by')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_ai');
    }
};
