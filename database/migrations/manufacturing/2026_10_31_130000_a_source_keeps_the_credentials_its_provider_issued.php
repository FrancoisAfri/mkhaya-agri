<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some providers (paid weather and market data, member-only feeds) issue an API key or a login.
 * The source keeps it encrypted, and the fetcher sends it as the provider asks: a header, a query
 * parameter, or basic authentication. It is written, never shown back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prd_sources', fn (Blueprint $t) => $t->text('credentials')->nullable());
    }

    public function down(): void
    {
        Schema::table('prd_sources', fn (Blueprint $t) => $t->dropColumn('credentials'));
    }
};
