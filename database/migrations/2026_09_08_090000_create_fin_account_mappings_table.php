<?php

use Database\Seeders\FinAccountMappingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which ledger account each operational module's postings land in.
 *
 * Every module that posts to the general ledger has so far carried its account codes as constants:
 * Project Management maps five cost categories to 5100–5410, Payroll maps six payslip components to
 * 6100–2400, and Payroll's own comment says "overridable later via a mapping table". This is that
 * table, and it is shared rather than per-module because the problem is identical in both and a
 * second copy is a second place for one of them to drift.
 *
 * The failure it fixes is a quiet one. A tenant whose chart of accounts does not carry those exact
 * codes gets *no* postings at all — correctly, because a journal against a guessed account is worse
 * than no journal, but invisibly: the cost is approved, the screen says so, and the books never
 * move. There was nowhere to tell the system which accounts to use instead.
 *
 * ── Shape ───────────────────────────────────────────────────────────────────
 *
 * (company_id, source_module, key) → account_code. `company_id` is nullable and a null row is the
 * platform default, which is how the seeded constants keep today's behaviour byte-identical on the
 * day this ships: every tenant already resolves to exactly the code that was hardcoded, and only a
 * tenant who edits one changes anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_account_mappings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();

            // Nullable: a null company is the platform-wide default that every tenant inherits
            // until they override it. Shared global rows, as the rest of the platform does them.
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();

            // Which module is posting — 'project_management', 'payroll'.
            $t->string('source_module', 60);

            // What is being posted: a PM cost category, a payslip component, a revenue line.
            $t->string('key', 60);

            // The account code, matched against `fin_accounts.code`. Deliberately the code and not
            // an id: a chart is re-imported and re-keyed, and the code is what survives that.
            $t->string('account_code', 40);

            $t->string('label')->nullable();
            $t->timestamps();

            $t->unique(['company_id', 'source_module', 'key'], 'fin_account_map_unique');
            $t->index(['source_module', 'key']);
        });

        /*
         * Seeded here rather than left to a separate command.
         *
         * The defaults *are* the constants the modules carried a moment ago, so seeding them is
         * what makes this migration a no-op in behaviour: every tenant resolves to the same account
         * it resolved to yesterday. An empty table would instead switch every posting off on the
         * day of deployment, which is the opposite of what a mapping table is for.
         */
        (new FinAccountMappingSeeder())->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_account_mappings');
    }
};
