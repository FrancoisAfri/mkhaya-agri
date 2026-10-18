<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — the separation becomes absolute.
 *
 * Phases 2a and 2b put `entity_id` on every finance table and scoped every read to it, but left
 * the column nullable, and a row with no business stays visible in all of them. That leniency was
 * deliberate and temporary: while the ledger was half converted, a row that escaped stamping
 * needed to stay visible, because books that silently stop adding up are worse than a row
 * appearing twice.
 *
 * Both halves are now converted, so the leniency can go — and it goes by removing the case rather
 * than the rule.
 *
 * ── Not NOT NULL ─────────────────────────────────────────────────────────────
 *
 * The obvious form, `entity_id NOT NULL`, is wrong here. A few rows belong to nobody: Bad Debts,
 * Retention Receivable and Staff Loans Receivable are installed by migration with no company and
 * no business, and every tenant posts to them. NOT NULL would refuse exactly those.
 *
 * What is actually true is narrower, and is the sentence this whole feature rests on:
 *
 *     A row that belongs to a tenant belongs to one of that tenant's businesses.
 *
 *     CHECK (company_id IS NULL OR entity_id IS NOT NULL)
 *
 * A system row has neither. A tenant's row has both. The state in between — a tenant's row with no
 * business, the one that could leak across them — becomes impossible, so EntityScope can stop
 * making room for it.
 *
 * ── Loud, not silent ─────────────────────────────────────────────────────────
 *
 * From here a write that does not say which business it belongs to fails at the point it is made,
 * naming the table. That is the intended trade: the alternative is a row nobody can see in any
 * business, discovered when a total does not tie.
 */
return new class extends Migration
{
    /**
     * Every finance table that carries both columns.
     *
     * Discovered rather than listed: the list would be a fourth copy of one that already exists in
     * three migrations, and the copy that drifts is the one that silently stops protecting a table.
     *
     * Found by the foreign key, not by the column name. "entity_id" is an ordinary name and other
     * modules use it for their own unrelated things — `ai_anomaly_flags.entity_id` is a uuid
     * naming whatever record a flag is about. Only a column that actually points at `fin_entities`
     * is a trading business, and only those tables are this migration's to constrain.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $rows = DB::select("
            SELECT DISTINCT src.relname AS table_name
              FROM pg_constraint AS fk
              JOIN pg_class AS src ON src.oid = fk.conrelid
              JOIN pg_class AS ref ON ref.oid = fk.confrelid
              JOIN pg_attribute AS col
                ON col.attrelid = fk.conrelid AND col.attnum = fk.conkey[1]
             WHERE fk.contype = 'f'
               AND ref.relname = 'fin_entities'
               AND col.attname = 'entity_id'
               AND EXISTS (
                     SELECT 1 FROM pg_attribute AS company
                      WHERE company.attrelid = fk.conrelid
                        AND company.attname = 'company_id'
                        AND company.attnum > 0
                        AND NOT company.attisdropped
                   )
             ORDER BY src.relname
        ");

        return array_map(fn ($r) => $r->table_name, $rows);
    }

    /**
     * A line's business is its header's, and headers must be settled first.
     *
     * This ran down the tables in alphabetical order and gave every unstamped row its tenant's
     * default business. On a clean database that is harmless. On a real one it failed outright,
     * and the reason is worth keeping: `fin_payment_allocations` sorts before `fin_payments`, so
     * a line was stamped while its header was still unstamped — and Phase 2b's composite key ties
     * (payment_id, entity_id) to (id, entity_id) on the header. The header held NULL, the line was
     * handed the default, and nothing matched:
     *
     *     insert or update on table "fin_payment_allocations" violates foreign key constraint
     *     "fin_payment_allocations_entity_matches_header"
     *
     * What produced an unstamped header is ordinary history: payments written before Finance was
     * tenanted have no `company_id`, so the 2b backfill had no tenant to read a business from and
     * correctly left them alone. Their allocations were written later and do have one.
     *
     * So the passes are ordered by what can answer for what. Headers take the tenant default.
     * Lines then take whatever their header ended up with — never the default, because the default
     * is exactly the value the composite key rejects when the header differs.
     */
    public function up(): void
    {
        $lines = $this->lineTables();
        $tables = $this->tables();

        // 1 — headers, and anything that is nobody's line.
        foreach ($tables as $table) {
            if (isset($lines[$table])) {
                continue;
            }

            $this->fillFromDefaultEntity($table);

            /*
             * A tenant with finance data but still no business at all. Rare — 2a made one for
             * every tenant that had any — but a tenant created since then and never opened in
             * Accounting would have none, and the constraint must not fail on their empty tables.
             */
            if ($this->unstamped($table) > 0) {
                $this->giveThoseTenantsABusiness($table);
            }
        }

        // 2 — lines, from the header they belong to.
        foreach ($tables as $table) {
            if (! isset($lines[$table])) {
                continue;
            }

            [$parent, $foreignKey] = $lines[$table];

            DB::statement("
                UPDATE {$table} AS t
                   SET entity_id = p.entity_id
                  FROM {$parent} AS p
                 WHERE p.id = t.{$foreignKey}
                   AND p.entity_id IS NOT NULL
                   AND t.entity_id IS NULL
            ");
        }

        // 3 — the rule itself.
        foreach ($tables as $table) {
            $this->requireABusiness($table, $this->unstamped($table));
        }
    }

    /** Rows that name a tenant and no business — what the rule is about. */
    private function unstamped(string $table): int
    {
        $row = DB::selectOne("
            SELECT count(*) AS n FROM {$table}
             WHERE company_id IS NOT NULL AND entity_id IS NULL
        ");

        return (int) ($row->n ?? 0);
    }

    private function fillFromDefaultEntity(string $table): void
    {
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

    /**
     * Put the rule on, and refuse to fail a deployment over history.
     *
     * A line can be left with no business through no fault of its own: its header may have been
     * deleted, or may predate tenancy and so have no tenant to take a business from. Those rows
     * cannot satisfy both this rule and the composite key at once, and a migration is the wrong
     * place to decide what to do with somebody's accounting records.
     *
     * So where any remain the rule goes on NOT VALID: every write from here is checked, and the
     * rows already there are left as they are rather than blocking the release. `finance:parity`
     * is where they should surface; nothing silently repairs them.
     */
    private function requireABusiness(string $table, int $unreconciled): void
    {
        $constraint = "{$table}_tenant_row_has_a_business";

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        DB::statement("
            ALTER TABLE {$table}
              ADD CONSTRAINT {$constraint}
              CHECK (company_id IS NULL OR entity_id IS NOT NULL)
              ".($unreconciled > 0 ? 'NOT VALID' : '')."
        ");

        if ($unreconciled > 0) {
            Log::warning('A finance table kept rows that belong to a tenant and to no business.', [
                'table' => $table,
                'rows'  => $unreconciled,
                'why'   => 'Their header is missing, or predates tenancy and has no company to '
                          .'take a business from. New writes are checked; these are not.',
            ]);
        }
    }

    /**
     * Which tables are lines, and of what — read from the composite keys themselves.
     *
     * Phase 2b named them `<table>_entity_matches_header`, and each one already records the child,
     * the header and the column between them. Taking the answer from there means this cannot drift
     * out of step with the keys it has to satisfy.
     *
     * @return array<string, array{0: string, 1: string}> child => [header, foreign key]
     */
    private function lineTables(): array
    {
        $rows = DB::select("
            SELECT src.relname AS child, ref.relname AS parent, col.attname AS fk
              FROM pg_constraint AS c
              JOIN pg_class AS src ON src.oid = c.conrelid
              JOIN pg_class AS ref ON ref.oid = c.confrelid
              JOIN pg_attribute AS col
                ON col.attrelid = c.conrelid AND col.attnum = c.conkey[1]
             WHERE c.contype = 'f'
               AND c.conname LIKE '%\\_entity\\_matches\\_header'
        ");

        $lines = [];

        foreach ($rows as $row) {
            $lines[$row->child] = [$row->parent, $row->fk];
        }

        return $lines;
    }

    private function giveThoseTenantsABusiness(string $table): void
    {
        $companies = DB::table($table)
            ->whereNotNull('company_id')->whereNull('entity_id')
            ->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $existing = DB::table('fin_entities')->where('company_id', $companyId)->orderBy('id')->first();

            $entityId = $existing->id ?? DB::table('fin_entities')->insertGetId([
                'uuid'       => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $companyId,
                'name'       => \Illuminate\Support\Str::limit(
                    DB::table('companies')->where('id', $companyId)->value('name') ?: 'Default', 150, ''
                ),
                'slug'       => 'default-'.$companyId,
                'is_default' => true,
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table($table)->where('company_id', $companyId)->whereNull('entity_id')
                ->update(['entity_id' => $entityId]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_tenant_row_has_a_business");
        }
    }
};
