<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The keys that still said one business per tenant.
 *
 * The same correction 2a made for customer and account codes, for what Phase 2b moved. Left as
 * they were, the second business cannot have a warehouse called MAIN, cannot map its own accounts,
 * and — the one that stops everything — cannot have its own document numbering, so the first quote
 * it raises fails on a duplicate key.
 *
 * `COALESCE(entity_id, 0)` for the same reason as before: two nulls never collide in Postgres, so
 * a bare three-column key would stop guarding rows that have no entity yet.
 */
return new class extends Migration
{
    /**
     * @var array<int, array{table: string, old: string, new: string, columns: string}>
     */
    private const KEYS = [
        [
            // The one that blocks a second business outright: its first quote, invoice and journal
            // all need a series row of their own, and this key allowed one per company.
            'table' => 'fin_number_series', 'columns' => 'doc_type',
            'old' => 'fin_number_series_company_id_doc_type_unique',
            'new' => 'fin_number_series_company_entity_doc_type_unique',
        ],
        [
            'table' => 'fin_warehouses', 'columns' => 'code',
            'old' => 'fin_warehouses_company_id_code_unique',
            'new' => 'fin_warehouses_company_entity_code_unique',
        ],
        [
            'table' => 'fin_stock_items', 'columns' => 'code',
            'old' => 'fin_stock_items_company_id_code_unique',
            'new' => 'fin_stock_items_company_entity_code_unique',
        ],
        [
            // Which account each module posts to. Each business posts to its own chart, so each
            // needs its own mapping.
            'table' => 'fin_account_mappings', 'columns' => 'source_module, key',
            'old' => 'fin_account_map_unique',
            'new' => 'fin_account_map_entity_unique',
        ],
        [
            // How much one person may post by hand. A limit that made sense for the business
            // turning over millions should not follow them into the one that turns over thousands.
            'table' => 'fin_posting_limits', 'columns' => 'user_id',
            'old' => 'fin_posting_limits_user_id_company_id_unique',
            'new' => 'fin_posting_limits_company_entity_user_unique',
        ],
        [
            // One ageing snapshot per business per date, not one per tenant per date.
            'table' => 'fin_ar_snapshots', 'columns' => 'as_of',
            'old' => 'fin_ar_snapshots_company_id_as_of_unique',
            'new' => 'fin_ar_snapshots_company_entity_as_of_unique',
        ],
    ];

    /**
     * Deliberately left alone: `fin_periods_year_pno_unique` on
     * (company_id, fiscal_year_id, period_no).
     *
     * It is already per business, through the year. Two businesses each with a period 1 of FY2026
     * have two different `fiscal_year_id` values — because `fin_fiscal_years` is itself per entity
     * now — so they cannot collide, and adding the entity would only restate what the year already
     * says.
     */
    public function up(): void
    {
        foreach (self::KEYS as $key) {
            if (! $this->ready($key)) {
                continue;
            }

            $this->dropUniqueKey($key['table'], $key['old']);

            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$key['new']} ON {$key['table']} "
                ."(company_id, COALESCE(entity_id, 0), {$key['columns']})"
            );
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $key) {
            if (! $this->ready($key)) {
                continue;
            }

            /*
             * Refuses rather than deletes, as in 2a: if two businesses have each taken the same
             * warehouse code or account mapping, restoring the old key means throwing one of them
             * away, and that is not a rollback's decision to make.
             */
            $columns = $key['columns'];

            $clashes = DB::selectOne("
                SELECT count(*) AS n FROM (
                    SELECT 1 FROM {$key['table']}
                     GROUP BY company_id, {$columns}
                    HAVING count(*) > 1
                ) AS d
            ");

            if ((int) ($clashes->n ?? 0) > 0) {
                throw new RuntimeException(
                    "Cannot roll back: {$key['table']} has {$clashes->n} value(s) of ({$columns}) "
                    .'used by more than one business in the same tenant. Merge them first.'
                );
            }

            $this->dropUniqueKey($key['table'], $key['new']);

            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$key['old']} ON {$key['table']} "
                ."(company_id, {$columns})"
            );
        }
    }

    private function ready(array $key): bool
    {
        return Schema::hasTable($key['table']) && Schema::hasColumn($key['table'], 'entity_id');
    }

    /** A unique key is a constraint or a plain index depending on how it was made; see 2a. */
    private function dropUniqueKey(string $table, string $name): void
    {
        $isConstraint = DB::selectOne(
            'SELECT 1 AS hit FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [$name, $table]
        );

        if ($isConstraint) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");

            return;
        }

        DB::statement("DROP INDEX IF EXISTS {$name}");
    }
};
