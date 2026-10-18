<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2b — the documents and the ledger become an entity's own.
 *
 * Phase 2a separated what a business is set up with: its customers, suppliers, items and chart of
 * accounts. This separates what it does: the quotes it sends, the invoices it raises, the journals
 * those post, the bank it banks into, the stock it holds and the periods it closes.
 *
 * After this a trial balance is one business's trial balance. Before it, Mkhaya Move's revenue was
 * inside Mkhaya MK's income statement and nobody reading either could tell.
 *
 * ── Why one migration and not forty ──────────────────────────────────────────
 *
 * Every table takes the same three steps — nullable column, index, backfill — and they have to
 * land together: a half-converted ledger is one where a journal is scoped and its lines are not,
 * which reads as a set of books that does not balance. The list is grouped so it can be checked
 * against the module a group at a time.
 *
 * ── Two kinds of backfill ────────────────────────────────────────────────────
 *
 * A table that carries `company_id` is filled from that tenant's default entity. Every tenant has
 * exactly one entity at this point — the backfills in 2a made sure of it — so "the default" is
 * "the only one", and nothing is being guessed.
 *
 * A line table has no company of its own; it belongs to whatever its header belongs to. Those are
 * filled from the header, which is both correct now and correct if this migration is ever run
 * against a database that already has several entities.
 */
return new class extends Migration
{
    /**
     * Tables that become an entity's own, grouped as the module is.
     *
     * A value of null means the table carries `company_id` and is filled from the tenant's default
     * entity. An array means it is a line table: [parent table, the column pointing at the parent].
     */
    private const TABLES = [
        // ── Documents ────────────────────────────────────────────────────────
        'fin_quotes'                  => null,
        'fin_quote_lines'             => ['fin_quotes', 'quote_id'],
        'fin_invoices'                => null,
        'fin_invoice_lines'           => ['fin_invoices', 'invoice_id'],
        'fin_sales_orders'            => null,
        'fin_sales_order_lines'       => ['fin_sales_orders', 'order_id'],
        'fin_delivery_notes'          => null,
        'fin_delivery_note_lines'     => ['fin_delivery_notes', 'delivery_note_id'],
        'fin_recurring_invoices'      => null,
        'fin_recurring_invoice_lines' => ['fin_recurring_invoices', 'recurring_id'],
        'fin_contracts'               => null,
        'fin_expenses'                => null,

        // ── The ledger ───────────────────────────────────────────────────────
        'fin_journals'                => null,
        'fin_journal_lines'           => ['fin_journals', 'journal_id'],
        'fin_postings'                => null,
        'fin_payments'                => null,
        'fin_payment_allocations'     => ['fin_payments', 'payment_id'],
        'fin_payment_batches'         => null,
        'fin_payment_batch_lines'     => ['fin_payment_batches', 'batch_id'],

        // ── Banking ──────────────────────────────────────────────────────────
        'fin_bank_accounts'           => null,
        'fin_bank_feeds'              => null,
        'fin_bank_statement_lines'    => ['fin_bank_accounts', 'bank_account_id'],
        'fin_bank_matches'            => null,
        'fin_bank_rules'              => null,
        'fin_bank_transfers'          => null,

        // ── Stock and assets ─────────────────────────────────────────────────
        'fin_warehouses'              => null,
        'fin_stock_items'             => null,
        'fin_stock_balances'          => null,
        'fin_stock_layers'            => null,
        'fin_stock_movements'         => null,
        'fin_assets'                  => null,
        'fin_asset_depreciation'      => ['fin_assets', 'asset_id'],

        // ── Periods, numbering and the rest of the setup ─────────────────────
        'fin_fiscal_years'            => null,
        'fin_fiscal_periods'          => null,
        'fin_number_series'           => null,
        'fin_budgets'                 => null,
        'fin_budget_lines'            => ['fin_budgets', 'budget_id'],
        'fin_document_templates'      => null,
        'fin_posting_limits'          => null,
        'fin_account_mappings'        => null,

        // ── Debtors ──────────────────────────────────────────────────────────
        'fin_ar_snapshots'            => null,
        'fin_collection_activities'   => null,
        'fin_nonpayment_reasons'      => null,
    ];

    /**
     * What deliberately stays tenant-wide.
     *
     * Written down because "it was forgotten" and "it was decided" look identical in a schema.
     *
     *   fin_exchange_rates    Market data. The rand/dollar rate is the same number for every
     *                         business in the country; a copy per entity is the same figure
     *                         maintained several times over, and they would drift.
     *   fin_entities          The list of businesses itself.
     *   fin_settings          Already per entity, from Phase 1.
     *   fin_groups
     *   fin_group_members     Consolidation groups, which exist to span businesses. They group by
     *                         company today; moving them to entities is Phase 4's job, along with
     *                         the consolidated reports they feed.
     *   fin_dashboard_prefs   Which widgets a person hides. Theirs, not a business's.
     *   fin_reports           A saved report definition is a template. It runs against whichever
     *                         business is selected — the tables underneath it are scoped — so
     *                         splitting the definitions would make somebody rebuild each report
     *                         once per business to get the same answer.
     *   finance_approval_steps
     *                         Who approved which step of a request. It follows the document being
     *                         approved, and that document is scoped; splitting the steps as well
     *                         would scope the same thing twice and could disagree with itself.
     *   financial_years       Not this module's. An older table, still empty, that shares the
     *                         prefix — `fin_fiscal_years` is the one Accounting uses.
     */
    private const DELIBERATELY_TENANT_WIDE = [
        'fin_exchange_rates', 'fin_entities', 'fin_settings',
        'fin_groups', 'fin_group_members', 'fin_dashboard_prefs', 'fin_reports',
        'finance_approval_steps', 'financial_years',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $parent) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'entity_id')) {
                $after = Schema::hasColumn($table, 'company_id') ? 'company_id' : 'id';

                Schema::table($table, function (Blueprint $blueprint) use ($after) {
                    $blueprint->foreignId('entity_id')->nullable()->after($after)
                        ->constrained('fin_entities')->nullOnDelete();
                    $blueprint->index('entity_id');
                });
            }

            // Deliberately outside the column guard: a half-applied run leaves the column there and
            // every row unstamped, and skipping on the column would leave that data belonging to
            // no business.
            $parent === null
                ? $this->fillFromDefaultEntity($table)
                : $this->fillFromParent($table, $parent[0], $parent[1]);
        }
    }

    /** A record of the tenant's own: it belongs to the business they have been trading as. */
    private function fillFromDefaultEntity(string $table): void
    {
        if (! Schema::hasColumn($table, 'company_id')) {
            return;
        }

        DB::statement("
            UPDATE {$table} AS t
               SET entity_id = e.id
              FROM fin_entities AS e
             WHERE e.company_id = t.company_id
               AND e.is_default
               AND t.company_id IS NOT NULL
               AND t.entity_id IS NULL
        ");
    }

    /** A line belongs where its document belongs. */
    private function fillFromParent(string $table, string $parentTable, string $foreignKey): void
    {
        if (! Schema::hasTable($parentTable) || ! Schema::hasColumn($table, $foreignKey)) {
            return;
        }

        DB::statement("
            UPDATE {$table} AS t
               SET entity_id = p.entity_id
              FROM {$parentTable} AS p
             WHERE p.id = t.{$foreignKey}
               AND p.entity_id IS NOT NULL
               AND t.entity_id IS NULL
        ");
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::TABLES)) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'entity_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['entity_id']);
                $blueprint->dropIndex(['entity_id']);
                $blueprint->dropColumn('entity_id');
            });
        }
    }
};
