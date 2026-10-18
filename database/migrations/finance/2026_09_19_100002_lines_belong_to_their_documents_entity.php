<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A line belongs to the same business as the document it is on.
 *
 * The rule this makes unbreakable: every line of a journal shares one entity, and the same for
 * every invoice, quote, order and batch. A journal whose lines sit in two businesses is a journal
 * that balances in neither, and it would pass every application-level check written to look at one
 * row at a time.
 *
 * ── A composite foreign key, not a trigger ───────────────────────────────────
 *
 * The parent gets a unique key on (id, entity_id) — free, since `id` is already unique — and the
 * line points at both columns at once. Postgres then refuses a line whose entity does not match
 * its header, on every insert and every update, with no trigger to maintain and no per-row cost
 * beyond the index lookup the plain foreign key already does.
 *
 * ON UPDATE CASCADE so that moving a document between businesses moves its lines with it, rather
 * than failing halfway and leaving the document in one business and its lines in another.
 *
 * ── While entity_id is nullable ──────────────────────────────────────────────
 *
 * A foreign key over several columns is not checked at all when any of them is null (the SQL
 * default, MATCH SIMPLE). So a line with no entity is allowed through today, exactly as the
 * EntityScope lets it be read. The NOT NULL constraint at the end of Phase 2 closes both at once —
 * this constraint is already written to be absolute the moment it does.
 */
return new class extends Migration
{
    /** line table => [parent table, the column pointing at the parent] */
    private const PAIRS = [
        'fin_journal_lines'           => ['fin_journals', 'journal_id'],
        'fin_invoice_lines'           => ['fin_invoices', 'invoice_id'],
        'fin_quote_lines'             => ['fin_quotes', 'quote_id'],
        'fin_sales_order_lines'       => ['fin_sales_orders', 'order_id'],
        'fin_delivery_note_lines'     => ['fin_delivery_notes', 'delivery_note_id'],
        'fin_recurring_invoice_lines' => ['fin_recurring_invoices', 'recurring_id'],
        'fin_payment_allocations'     => ['fin_payments', 'payment_id'],
        'fin_payment_batch_lines'     => ['fin_payment_batches', 'batch_id'],
        'fin_budget_lines'            => ['fin_budgets', 'budget_id'],
        'fin_asset_depreciation'      => ['fin_assets', 'asset_id'],
    ];

    public function up(): void
    {
        foreach (self::PAIRS as $lines => [$parent, $foreignKey]) {
            if (! $this->bothReady($lines, $parent, $foreignKey)) {
                continue;
            }

            /*
             * Any line already disagreeing with its header is corrected before the constraint goes
             * on, otherwise the migration fails on data it is being added to protect. The header is
             * taken as the truth: a document says which business issued it.
             */
            DB::statement("
                UPDATE {$lines} AS l
                   SET entity_id = p.entity_id
                  FROM {$parent} AS p
                 WHERE p.id = l.{$foreignKey}
                   AND l.entity_id IS DISTINCT FROM p.entity_id
            ");

            $unique = "{$parent}_id_entity_unique";
            $fk     = "{$lines}_entity_matches_header";

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$unique} ON {$parent} (id, entity_id)");
            DB::statement("ALTER TABLE {$lines} DROP CONSTRAINT IF EXISTS {$fk}");
            DB::statement("
                ALTER TABLE {$lines}
                  ADD CONSTRAINT {$fk}
                  FOREIGN KEY ({$foreignKey}, entity_id)
                  REFERENCES {$parent} (id, entity_id)
                  ON UPDATE CASCADE
            ");
        }
    }

    private function bothReady(string $lines, string $parent, string $foreignKey): bool
    {
        return Schema::hasTable($lines)
            && Schema::hasTable($parent)
            && Schema::hasColumn($lines, 'entity_id')
            && Schema::hasColumn($parent, 'entity_id')
            && Schema::hasColumn($lines, $foreignKey);
    }

    public function down(): void
    {
        foreach (self::PAIRS as $lines => [$parent, $foreignKey]) {
            DB::statement("ALTER TABLE IF EXISTS {$lines} DROP CONSTRAINT IF EXISTS {$lines}_entity_matches_header");
            DB::statement("DROP INDEX IF EXISTS {$parent}_id_entity_unique");
        }
    }
};
