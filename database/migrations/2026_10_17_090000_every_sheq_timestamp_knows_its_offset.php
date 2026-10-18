<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert every SHEQ `timestamp` to `timestamptz` (REG-FIX-6, the structural half).
 *
 * 352 columns across 124 tables carried `timestamp without time zone`, beside 111 that already
 * carried the offset. A naive timestamp has no offset, so what it means depends entirely on who
 * wrote it and who is reading — and in a module that computes statutory deadlines, access
 * expiries and injury rates, "depends on who is reading" is not a property anybody wants.
 *
 * ── The dangerous part, named ────────────────────────────────────────────────
 *
 * `ALTER COLUMN x TYPE timestamptz` with no USING clause interprets the stored naive value in the
 * SESSION's timezone. This installation makes that a live hazard rather than a theoretical one:
 *
 *   - Laravel's connection is pinned to `+00:00` (config/database.php, SHEQ-ADR-005)
 *   - a raw `psql` session on the same database runs Africa/Johannesburg
 *
 * So the same migration run through artisan and run through psql would produce values two hours
 * apart, and the wrong one would be silent — every historic timestamp shifted, no error, nothing
 * to notice until somebody disputed a seven-day statutory deadline. Hence
 * `USING col AT TIME ZONE 'UTC'` on every single column: the interpretation is written down
 * rather than inherited, and it is the same whoever runs this.
 *
 * UTC is the correct interpretation because `app.timezone` is UTC and Laravel binds a formatted
 * local string — so every naive value in these columns is UTC wall-clock, whatever the session
 * was doing at the time.
 *
 * ── Why this reads the catalogue instead of listing columns ──────────────────
 *
 * 352 names in a migration is 352 chances to mistype one, and a column missed is a column that
 * stays naive while everything around it does not — which is worse than the state before, because
 * then the inconsistency is unpredictable instead of uniform. The catalogue cannot be out of date.
 *
 * Idempotent: a column already carrying an offset is skipped, so this can be re-run after a
 * partial failure without converting anything twice.
 */
return new class extends Migration
{
    /**
     * Tables the conversion leaves alone.
     *
     * `sheq_audit_log` is here because it is hash-chained by a trigger, and altering a column
     * under a verification trigger is a thing to do deliberately with the chain re-verified
     * afterwards rather than inside a sweep across 124 tables.
     *
     * As it turns out it needed nothing: `occurred_at` was already `timestamp with time zone`,
     * so the chain was never at risk and this entry changed nothing. Kept rather than deleted,
     * because the reasoning holds for whatever is added to that table next.
     *
     * @var list<string>
     */
    private const SKIP = ['sheq_audit_log'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->columns() as $column) {
            [$table, $name] = [$column->table_name, $column->column_name];

            /*
             * Each column in its own statement, and each one named.
             *
             * `USING ... AT TIME ZONE 'UTC'` is the whole point: without it the session's zone
             * decides, and the two sessions that reach this database disagree by two hours.
             */
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE timestamptz USING %s AT TIME ZONE \'UTC\'',
                $table, $name, $name,
            ));
        }
    }

    /**
     * Back to naive, reading the values as UTC.
     *
     * Symmetric with `up()` and lossy in the same way the original schema was: the offset is
     * discarded and the wall-clock kept, which is exactly the state these columns were in before.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columns = DB::select("
            select c.table_name, c.column_name
            from information_schema.columns c
            join information_schema.tables t
              on t.table_schema = c.table_schema and t.table_name = c.table_name
            where c.table_schema = current_schema()
              and c.table_name like 'sheq\\_%'
              and c.data_type = 'timestamp with time zone'
              and t.table_type = 'BASE TABLE'
              and c.is_generated = 'NEVER'
            order by c.table_name, c.column_name
        ");

        foreach ($columns as $column) {
            if (in_array($column->table_name, self::SKIP, true)) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN %s TYPE timestamp USING %s AT TIME ZONE \'UTC\'',
                $column->table_name, $column->column_name, $column->column_name,
            ));
        }
    }

    /**
     * The naive SHEQ timestamp columns, from the catalogue.
     *
     * Base tables only — a view cannot be altered and does not need to be, it takes the type of
     * what it selects. Generated columns excluded: Postgres refuses to alter one, and the module
     * has none of this type in any case.
     *
     * @return list<object>
     */
    private function columns(): array
    {
        $rows = DB::select("
            select c.table_name, c.column_name
            from information_schema.columns c
            join information_schema.tables t
              on t.table_schema = c.table_schema and t.table_name = c.table_name
            where c.table_schema = current_schema()
              and c.table_name like 'sheq\\_%'
              and c.data_type = 'timestamp without time zone'
              and t.table_type = 'BASE TABLE'
              and c.is_generated = 'NEVER'
            order by c.table_name, c.column_name
        ");

        return array_values(array_filter($rows,
            fn (object $row) => ! in_array($row->table_name, self::SKIP, true)
                && Schema::hasTable($row->table_name)));
    }
};
