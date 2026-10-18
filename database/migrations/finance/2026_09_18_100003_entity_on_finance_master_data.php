<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 2a — the master data and the chart of accounts become an entity's own.
 *
 * Decided 18 September: a tenant's trading entities are completely separate. A customer of Mkhaya
 * MK is not a customer of Mkhaya Move until Mkhaya Move is given one, and each entity keeps its
 * own chart of accounts. That is how Sage treats a company file.
 *
 * This migration takes the master data. The documents and the ledger follow in 2b, deliberately
 * split: this half can be verified on its own, and a migration that rewrites every finance table
 * in one pass is a migration nobody can review.
 *
 * ── Still reversible ─────────────────────────────────────────────────────────
 *
 * The column is nullable and nothing reads it as required yet. `down()` drops it and the data is
 * exactly as it was. The NOT NULL constraint waits until the end of Phase 2.
 */
return new class extends Migration
{
    /**
     * Master data, and the chart the ledger will hang from.
     *
     * `fin_exchange_rates` is deliberately absent: a rate is market data, the same number for
     * every business in the country, and duplicating it per entity would mean maintaining the
     * same figures several times over.
     */
    private const TABLES = [
        'fin_parties',
        'fin_items',
        'fin_price_lists',
        'fin_price_list_items',
        'fin_tax_codes',
        'fin_accounts',
    ];

    /**
     * The unique keys that assumed one business per tenant.
     *
     * Mkhaya MK and Mkhaya Move both want account 1000 "Bank" and both want customer CUST001. A
     * key on (company_id, code) says one of them cannot have it. Each of these moves out to
     * include the entity.
     *
     * `COALESCE(entity_id, 0)` rather than a plain column: in Postgres two NULLs never collide, so
     * a bare (company_id, entity_id, code) would stop guarding rows that have no entity yet. The
     * backfill above stamps every existing row, but a row created from the console before the
     * entity context is set would still arrive with NULL, and those must not silently duplicate.
     */
    private const UNIQUE_KEYS = [
        [
            'table'   => 'fin_parties',
            'old'     => 'fin_parties_company_id_code_unique',
            'new'     => 'fin_parties_company_entity_code_unique',
            'columns' => 'code',
            'where'   => null,
        ],
        [
            'table'   => 'fin_parties',
            'old'     => 'fin_parties_company_customer_number_unique',
            'new'     => 'fin_parties_company_entity_customer_number_unique',
            'columns' => 'customer_number',
            'where'   => 'customer_number IS NOT NULL',
        ],
        [
            'table'   => 'fin_items',
            'old'     => 'fin_items_company_id_code_unique',
            'new'     => 'fin_items_company_entity_code_unique',
            'columns' => 'code',
            'where'   => null,
        ],
        [
            'table'   => 'fin_tax_codes',
            'old'     => 'fin_tax_codes_company_id_code_unique',
            'new'     => 'fin_tax_codes_company_entity_code_unique',
            'columns' => 'code',
            'where'   => null,
        ],
        [
            'table'   => 'fin_accounts',
            'old'     => 'fin_accounts_company_id_code_unique',
            'new'     => 'fin_accounts_company_entity_code_unique',
            'columns' => 'code',
            'where'   => null,
        ],
    ];

    public function up(): void
    {
        $this->ensureEveryTenantHasADefaultEntity();

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Only the column add is skipped when it is already there — never the backfill below.
            // A half-applied run leaves the column present and every row unstamped, and skipping
            // on the column would then leave that tenant's data belonging to no entity, which
            // reads as an empty module.
            if (! Schema::hasColumn($table, 'entity_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->foreignId('entity_id')->nullable()->after('company_id')
                        ->constrained('fin_entities')->nullOnDelete();
                    $blueprint->index('entity_id');
                });
            }

            /*
             * Everything a tenant already has belongs to the entity they have been trading as.
             *
             * Rows with no company — the shared, pre-tenancy library rows some of these tables
             * still carry — are left alone: they belong to everybody, and claiming them for one
             * tenant's entity would take them away from the rest.
             */
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

        $this->widenUniqueKeysToTheEntity();
    }

    /**
     * Give each entity its own code space.
     */
    private function widenUniqueKeysToTheEntity(): void
    {
        foreach (self::UNIQUE_KEYS as $key) {
            if (! Schema::hasTable($key['table']) || ! Schema::hasColumn($key['table'], $key['columns'])) {
                continue;
            }

            $where = $key['where'] ? " WHERE {$key['where']}" : '';

            $this->dropUniqueKey($key['table'], $key['old']);

            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$key['new']} ON {$key['table']} "
                ."(company_id, COALESCE(entity_id, 0), {$key['columns']}){$where}"
            );
        }
    }

    /**
     * Remove a unique key, whichever of the two things Postgres made it.
     *
     * `$table->unique()` in a Laravel migration creates a table *constraint*, and the index behind
     * it cannot be dropped on its own — DROP INDEX fails with "dependent objects still exist" and
     * names the constraint. A partial unique (`... WHERE customer_number IS NOT NULL`) cannot be a
     * constraint at all, so it really is a plain index. Both shapes are in this list.
     */
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

    /**
     * A tenant with finance data but no entity yet.
     *
     * The Phase 1 backfill created an entity for every tenant that had a settings row. A tenant
     * that never opened the settings screen still has parties and accounts, and they need
     * somewhere to belong before the backfill above can find them.
     */
    private function ensureEveryTenantHasADefaultEntity(): void
    {
        $withData = collect();

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $withData = $withData->merge(
                    DB::table($table)->whereNotNull('company_id')->distinct()->pluck('company_id')
                );
            }
        }

        $covered = DB::table('fin_entities')->whereNotNull('company_id')->pluck('company_id')->all();

        foreach ($withData->unique()->diff($covered) as $companyId) {
            $name = DB::table('companies')->where('id', $companyId)->value('name') ?: 'Default';

            DB::table('fin_entities')->insert([
                'uuid'       => (string) Str::uuid(),
                'company_id' => $companyId,
                'name'       => Str::limit($name, 150, ''),
                'slug'       => Str::slug(Str::limit($name, 50, '')) ?: 'default',
                'is_default' => true,
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Going back means one code space per tenant again.
     *
     * If two entities have each taken the same code, the old key cannot be restored without
     * throwing one of them away. This refuses instead of choosing: master data is a tenant's own
     * record, not something a rollback gets to delete. Merge or renumber the codes first.
     */
    public function down(): void
    {
        foreach (self::UNIQUE_KEYS as $key) {
            if (! Schema::hasTable($key['table']) || ! Schema::hasColumn($key['table'], $key['columns'])) {
                continue;
            }

            $where = $key['where'] ? "WHERE {$key['where']}" : '';

            $clashes = DB::selectOne("
                SELECT count(*) AS n FROM (
                    SELECT 1 FROM {$key['table']} {$where}
                     GROUP BY company_id, {$key['columns']}
                    HAVING count(*) > 1
                ) AS d
            ");

            if ((int) ($clashes->n ?? 0) > 0) {
                throw new RuntimeException(
                    "Cannot roll back: {$key['table']} has {$clashes->n} {$key['columns']} value(s) "
                    .'used by more than one entity in the same tenant. Renumber them first.'
                );
            }

            $this->dropUniqueKey($key['table'], $key['new']);

            DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$key['old']} ON {$key['table']} "
                ."(company_id, {$key['columns']}) ".$where
            );
        }

        foreach (self::TABLES as $table) {
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
