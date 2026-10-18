<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates "who the customer is" from "what Accounting calls them".
 *
 * fin_parties.code is the debtor code — it appears on invoices and statements, and
 * is only meaningful once Accounting is in use. Modules that work without
 * Accounting (CRM, Helpdesk, Property) still need a stable human-readable
 * reference, so every party also gets a permanent customer_number.
 *
 * The two never replace each other. Enabling Accounting *grants* a debtor code;
 * it does not rewrite the customer number, because that number may already be on
 * a ticket, a quote or an email. Joins always use uuid, which never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_parties', function (Blueprint $table) {
            // Permanent, issued on creation regardless of which module created it.
            $table->string('customer_number', 40)->nullable()->after('code');
            // When Accounting granted the debtor code (null = never promoted).
            $table->timestamp('debtor_code_issued_at')->nullable()->after('customer_number');
            // Which module first created this party — useful for reconciliation.
            $table->string('created_by_module', 40)->nullable()->after('debtor_code_issued_at');

            $table->index('customer_number');
        });

        // Per-tenant uniqueness: two companies may legitimately both have CUS000001.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX fin_parties_company_customer_number_unique
                           ON fin_parties (company_id, customer_number)
                           WHERE customer_number IS NOT NULL');
        }

        $this->backfillExistingParties();
    }

    /**
     * Existing parties predate the customer number, so give each one now. Ordered
     * by id so the sequence matches the order they were created in.
     */
    private function backfillExistingParties(): void
    {
        if (! Schema::hasTable('fin_parties')) {
            return;
        }

        $companies = DB::table('fin_parties')->select('company_id')->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $n = 0;

            $rows = DB::table('fin_parties')
                ->when($companyId === null,
                    fn ($q) => $q->whereNull('company_id'),
                    fn ($q) => $q->where('company_id', $companyId))
                ->whereNull('customer_number')
                ->orderBy('id')
                ->pluck('id');

            foreach ($rows as $id) {
                $n++;
                DB::table('fin_parties')->where('id', $id)->update([
                    'customer_number' => 'CUS' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                    // These already have a code and live invoices, so they are
                    // already Accounting customers — record them as promoted.
                    'debtor_code_issued_at' => DB::raw('created_at'),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS fin_parties_company_customer_number_unique');
        }

        Schema::table('fin_parties', function (Blueprint $table) {
            $table->dropIndex(['customer_number']);
            $table->dropColumn(['customer_number', 'debtor_code_issued_at', 'created_by_module']);
        });
    }
};
