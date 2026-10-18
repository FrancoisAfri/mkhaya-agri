<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI playbook-deviation analysis of a contract against the clause library:
 * missing required clauses and per-clause deviations with risk ratings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_clause_analyses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contract_id')->unique();
            $table->string('status')->default('pending'); // pending|processing|completed|failed
            $table->json('missing_required')->nullable();
            $table->json('deviations')->nullable();
            $table->text('summary')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('analyzed_by')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_clause_analyses');
    }
};
