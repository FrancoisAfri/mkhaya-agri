<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banking & reconciliation (Phase 3). A bank account maps to a CoA account; its
 * imported statement lines are reconciled against the book entries (journal
 * lines posted to that account) via a separate match table — journal lines stay
 * immutable, reconciliation is derived from the matches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('account_id'); // CoA (asset) account
            $table->string('name');
            $table->string('type', 20)->default('bank'); // bank | petty_cash | loan | investment | wallet
            $table->char('currency', 3)->default('ZAR');
            $table->string('bank_name', 80)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fin_bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->date('statement_date');
            $table->string('description', 255)->nullable();
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 18, 2);      // signed: + inflow, − outflow
            $table->decimal('balance', 18, 2)->nullable();
            $table->string('hash', 40);            // dedup within an account
            $table->string('status', 12)->default('unmatched'); // unmatched | matched
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['bank_account_id', 'hash']);
            $table->index(['bank_account_id', 'status']);
        });

        Schema::create('fin_bank_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('statement_line_id');
            $table->unsignedBigInteger('journal_line_id');
            $table->decimal('amount', 18, 2);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('statement_line_id');
            $table->index('journal_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_matches');
        Schema::dropIfExists('fin_bank_statement_lines');
        Schema::dropIfExists('fin_bank_accounts');
    }
};
