<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accounting's rows are kept to their organisation by the database, as invoices already were.
 *
 * These tables relied on the application's company scope alone: a query that forgot it — a report,
 * a raw join, a console job run inside one organisation — could read another organisation's
 * customers, journals or payments. Each now has the same row-level policy as the rest of the
 * platform: with an organisation in session, only its rows (and the shared rows that belong to no
 * organisation, such as the standard chart of accounts and its default mappings) are visible or
 * writable; with none in session (migrations, the platform console) nothing is narrowed.
 *
 * fin_group_members is left out on purpose: a group lists companies other than the one viewing it.
 * Consolidation reads each member's books inside that member's organisation (ConsolidationService).
 */
return new class extends Migration
{
    private const TABLES = [
        'fin_account_mappings', 'fin_accounts', 'fin_ar_snapshots', 'fin_assets', 'fin_bank_accounts', 'fin_bank_rules', 'fin_bank_transfers', 'fin_budgets',
        'fin_contracts', 'fin_delivery_notes', 'fin_entities', 'fin_expenses', 'fin_fiscal_periods', 'fin_fiscal_years', 'fin_items', 'fin_journal_lines',
        'fin_journals', 'fin_number_series', 'fin_parties', 'fin_payment_batches', 'fin_payments', 'fin_posting_limits', 'fin_price_lists', 'fin_quotes',
        'fin_recurring_invoices', 'fin_reports', 'fin_sales_orders', 'fin_settings', 'fin_stock_items', 'fin_tax_codes',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id IS NULL OR company_id = {$session}::bigint";
        foreach (self::TABLES as $t) {
            $exists = DB::selectOne("SELECT 1 AS ok FROM information_schema.columns WHERE table_name = ? AND column_name = 'company_id'", [$t]);
            if (! $exists) {
                continue;
            }
            DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::TABLES as $t) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
            DB::statement("ALTER TABLE {$t} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$t} DISABLE ROW LEVEL SECURITY");
        }
    }
};
