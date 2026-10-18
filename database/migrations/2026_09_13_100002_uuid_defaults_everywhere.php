<?php

use App\Shared\Database\OptionalStatement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every NOT NULL `uuid` column gets a database default.
 *
 * Models generate their uuid in a `creating` hook, and for ordinary application writes that works.
 * It is the only thing holding the column up, though, and it stops working whenever a row is
 * written by anything other than a saved model:
 *
 *   - `DB::table(...)->insert(...)`, which seeders and imports reach for by preference
 *   - `Model::insert()`, which is the query builder wearing a model's name and fires no events
 *   - `Event::fake()` in a test, which replaces the dispatcher — and Eloquent's `creating` hook is
 *     dispatched through it, so faking events silently disables uuid generation
 *
 * Each of those produces the same thing: `null value in column "uuid" violates not-null
 * constraint`, from a stack trace that points at the insert and says nothing about why the model
 * did not fill it in. 23 tests were failing this way, and the same shape has bitten
 * `migrate:fresh --seed` before.
 *
 * A column default makes the guarantee belong to the column. The model hooks stay — they cost
 * nothing, and they keep the uuid available on the instance before it is saved.
 *
 * ── gen_random_uuid() ────────────────────────────────────────────────────────
 *
 * Built into PostgreSQL 13 and later, so no extension is needed. Nine of these columns are
 * `varchar` rather than `uuid`; those get the same call cast to text.
 *
 * Each table is set independently through OptionalStatement, so a table that refuses — a view, a
 * permission we do not hold — costs only itself rather than aborting the transaction and silently
 * discarding every table that came before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;     // gen_random_uuid() is Postgres; other drivers keep the model hooks
        }

        $columns = DB::select(
            'select table_name, data_type from information_schema.columns'
            . ' where column_name = ? and is_nullable = ? and column_default is null'
            . ' and table_schema = current_schema()'
            . ' order by table_name',
            ['uuid', 'NO'],
        );

        $done = 0;
        $skipped = [];

        foreach ($columns as $column) {
            $table = $column->table_name;

            // A uuid column typed as text needs the cast; a real uuid column must not have one.
            $expression = $column->data_type === 'uuid'
                ? 'gen_random_uuid()'
                : 'gen_random_uuid()::text';

            $ok = OptionalStatement::attempt(
                "set the uuid default on {$table}",
                fn () => DB::statement(
                    'ALTER TABLE "' . $table . '" ALTER COLUMN "uuid" SET DEFAULT ' . $expression,
                ),
                'rows written outside a model will fail the NOT NULL constraint',
            );

            $ok ? $done++ : $skipped[] = $table;
        }

        logger()->info('uuid defaults applied', [
            'tables' => $done,
            'skipped' => $skipped,
        ]);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * Only the defaults this migration is responsible for.
         *
         * The HelpDesk tables were given theirs by an earlier migration and are its to remove —
         * dropping them here would leave that migration recorded as applied over a database where
         * its work is gone, which is the exact confusion this codebase has already paid for once.
         */
        $helpdesk = DB::select(
            "select table_name from information_schema.columns"
            . " where column_name = 'uuid' and table_schema = current_schema()"
            . " and table_name like 'hd\\_%'",
        );
        $exclude = array_column($helpdesk, 'table_name');

        $columns = DB::select(
            "select table_name from information_schema.columns"
            . " where column_name = 'uuid' and is_nullable = 'NO'"
            . " and column_default like 'gen_random_uuid()%'"
            . ' and table_schema = current_schema()',
        );

        foreach ($columns as $column) {
            if (in_array($column->table_name, $exclude, true)) {
                continue;
            }

            OptionalStatement::attempt(
                "drop the uuid default on {$column->table_name}",
                fn () => DB::statement(
                    'ALTER TABLE "' . $column->table_name . '" ALTER COLUMN "uuid" DROP DEFAULT',
                ),
            );
        }
    }
};
