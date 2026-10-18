<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 (audit-first) — tamper-evident ledger. Each journal is cryptographically
 * chained to the previous one: hash = sha256(canonical(journal + lines) + prev_hash).
 * Altering any historic journal (even in the DB) breaks the chain from that point
 * on, which `finance:verify-chain` detects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_journals', function (Blueprint $table) {
            $table->unsignedBigInteger('seq')->nullable()->after('id');
            $table->char('prev_hash', 64)->nullable()->after('metadata');
            $table->char('hash', 64)->nullable()->after('prev_hash');
            $table->index(['company_id', 'seq']);
        });
    }

    public function down(): void
    {
        Schema::table('fin_journals', function (Blueprint $table) {
            $table->dropColumn(['seq', 'prev_hash', 'hash']);
        });
    }
};
