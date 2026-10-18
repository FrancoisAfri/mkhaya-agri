<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2 — transactional outbox. Producers write a row here (in their own DB
 * transaction) describing a FinancialEvent; the poster drains it into a journal
 * via Ledger::post(). Gives durable, replayable, exactly-once posting without a
 * message broker. See docs/FINANCE_ACCOUNTING_BLUEPRINT.md §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_postings', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('source_module', 40);
            $table->string('source_ref', 120)->nullable();
            $table->string('idempotency_key', 120)->index();
            $table->json('payload');
            $table->string('status', 12)->default('pending'); // pending|posted|failed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['source_module', 'source_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_postings');
    }
};
