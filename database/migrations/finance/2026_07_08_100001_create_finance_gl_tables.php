<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — General Ledger core: chart of accounts, fiscal periods, journals
 * (immutable once posted), journal lines (double-entry), and per-company
 * numbering series. See docs/FINANCE_ACCOUNTING_BLUEPRINT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('code', 40);
            $table->string('name');
            $table->string('type', 20);              // asset|liability|equity|income|expense
            $table->string('subtype', 40)->nullable();
            $table->string('normal_balance', 6);     // debit|credit
            $table->foreignId('parent_id')->nullable()->constrained('fin_accounts')->nullOnDelete();
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('tax_code', 30)->nullable();
            $table->string('bank_link', 60)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['type', 'is_active']);
        });

        Schema::create('fin_fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->integer('fiscal_year');
            $table->integer('period_no');            // 1..12 (or 13 for adjustment)
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 12)->default('open'); // open|soft_close|hard_close|locked
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year', 'period_no']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('fin_number_series', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('doc_type', 30);          // journal|invoice|receipt|...
            $table->string('prefix', 16)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'doc_type']);
        });

        Schema::create('fin_journals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->string('journal_no', 40)->nullable();
            $table->date('journal_date');
            $table->foreignId('period_id')->nullable()->constrained('fin_fiscal_periods')->nullOnDelete();
            $table->string('source_module', 40)->default('manual');
            $table->string('source_ref', 120)->nullable();
            $table->string('event_type', 60)->nullable();
            $table->string('currency', 8)->default('ZAR');
            $table->string('description', 500)->nullable();
            $table->string('status', 12)->default('posted'); // draft|posted|reversed
            $table->foreignId('reverses_journal_id')->nullable()->constrained('fin_journals')->nullOnDelete();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'journal_date']);
            $table->index(['source_module', 'source_ref']);
        });

        Schema::create('fin_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('fin_journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('fin_accounts');
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('currency', 8)->default('ZAR');
            $table->decimal('fx_rate', 18, 8)->default(1);
            $table->decimal('base_debit', 18, 2)->default(0);
            $table->decimal('base_credit', 18, 2)->default(0);
            $table->string('narration', 500)->nullable();
            $table->json('dimensions')->nullable(); // {cost_centre, project, branch, employee, vehicle, ...}
            $table->timestamps();

            $table->index('account_id');
            $table->index('journal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_journal_lines');
        Schema::dropIfExists('fin_journals');
        Schema::dropIfExists('fin_number_series');
        Schema::dropIfExists('fin_fiscal_periods');
        Schema::dropIfExists('fin_accounts');
    }
};
