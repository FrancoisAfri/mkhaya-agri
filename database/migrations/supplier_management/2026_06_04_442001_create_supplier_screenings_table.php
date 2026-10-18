<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-assisted compliance screening of a supplier (and its directors): sanctions /
 * PEP / watchlist exposure and adverse-media signals. This is a preliminary,
 * model-driven screen — not a substitute for a licensed data provider — and is
 * stored so reviewers can action and audit it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_screenings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->string('status')->default('pending'); // pending|processing|completed|failed
            $table->string('risk_level')->nullable();      // low|medium|high
            $table->json('sanctions_hits')->nullable();
            $table->json('pep_hits')->nullable();
            $table->json('adverse_media')->nullable();
            $table->text('summary')->nullable();
            $table->json('recommendations')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->longText('raw_response')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('screened_by')->nullable();
            $table->timestamp('screened_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_screenings');
    }
};
