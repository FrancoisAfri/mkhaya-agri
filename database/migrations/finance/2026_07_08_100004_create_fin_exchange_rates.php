<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 (0.3) — currencies & exchange rates. Foreign-currency journals are
 * converted to the base currency at post time using the rate effective on/before
 * the journal date (source + rate type recorded for audit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);   // FROM (e.g. USD)
            $table->char('quote_currency', 3);  // TO   (e.g. ZAR)
            $table->decimal('rate', 18, 8);
            $table->string('rate_type', 20)->default('spot'); // spot | average | closing
            $table->string('source', 60)->nullable();
            $table->date('effective_date');
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'rate_type', 'effective_date'], 'fin_fx_unique');
            $table->index(['base_currency', 'quote_currency', 'rate_type', 'effective_date'], 'fin_fx_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_exchange_rates');
    }
};
