<?php

namespace App\Modules\Operations\ProjectManagement\Support\Providers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reading another module's tables, defensively.
 *
 * Integrated providers query tables PM does not own. Two things follow from that, and both are
 * mistakes worth making impossible rather than remembering:
 *
 *  1. **The table may not be there.** A tenant can hold a module through an entitlement while the
 *     deployment has never run its migrations, and a provider that assumed otherwise would turn a
 *     missing table into a 500 on a page that has nothing to do with that module. `ready()` is the
 *     guard, and every provider calls it before touching anything.
 *
 *  2. **The columns may not be the ones we expect.** Another team renames a column and PM's page
 *     breaks in production with a SQL error. `ready()` takes the columns the caller actually reads,
 *     so a rename degrades to "integration unavailable" — visible on the setup page as *ready*
 *     rather than *live* — instead of a stack trace.
 *
 * Tenancy is not handled here and must not be: every one of these tables carries `company_id` under
 * FORCE ROW LEVEL SECURITY, so the database filters them. Adding a `where company_id` on top would
 * be a second, weaker copy of a rule that is already enforced.
 */
final class ModuleTable
{
    /**
     * Is this table present, with the columns the caller is about to read?
     *
     * @param  list<string>  $columns
     */
    public static function ready(string $table, array $columns = []): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return $columns === [] || Schema::hasColumns($table, $columns);
    }

    /**
     * Match a record by whichever id shape the caller happens to hold.
     *
     * PM addresses everything by uuid (PM-ADR-003); other modules use bigint keys and only
     * sometimes carry a uuid as well. So an id arriving here may be either, and the wrong one must
     * never reach the wrong column: Postgres does not quietly fail to match a uuid against a
     * bigint, it raises `22P02` and **aborts the surrounding transaction**. That is the difference
     * between "this record was not found" and a 500 on a page.
     *
     * So the id's shape decides which column is compared, and a column that is not there is not
     * compared at all.
     */
    public static function whereId(Builder $query, string $table, string $id): Builder
    {
        $numeric = ctype_digit($id);
        $uuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id);

        if ($uuid && Schema::hasColumn($table, 'uuid')) {
            return $query->where('uuid', $id);
        }

        if ($numeric) {
            return $query->where('id', (int) $id);
        }

        // Neither shape matches anything this table can hold. `whereRaw('1 = 0')` rather than
        // letting it through: an unconstrained query would return the first row of somebody else's
        // table as if it were the record asked for.
        return $query->whereRaw('1 = 0');
    }

    /** A query on another module's table, or null when it is not safe to build one. */
    public static function query(string $table, array $columns = []): ?Builder
    {
        if (! self::ready($table, $columns)) {
            return null;
        }

        $query = DB::table($table);

        // Nearly every table on this platform soft-deletes. Excluding removed rows is what the
        // owning module's own screens do, and a provider that ignored it would show a project
        // linked to an asset somebody scrapped last year.
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query;
    }
}
