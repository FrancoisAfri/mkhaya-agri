<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user's theme is theirs, not the tenant's.
 *
 * `user_theme_settings` was swept up in the tenant-scope-everything pass, but its primary key is
 * `user_id` — one row per person, for all time. Those two facts cannot both hold: the row is
 * unique per user, yet row-level security hides it whenever the session is looking at a company
 * the row does not belong to.
 *
 * What that produced is a 500 rather than a quiet miss. `updateOrCreate` runs a SELECT, RLS
 * returns nothing, so it INSERTs — and the insert collides with the primary key it could not see:
 *
 *     duplicate key value violates unique constraint "user_theme_settings_pkey"
 *
 * A platform admin who saved a theme with no company selected, then switched to viewing a tenant,
 * hit this on every attempt to change a colour.
 *
 * The fix is to stop pretending the row is tenant data. The policy now admits rows with no
 * company, the column default no longer stamps one, and the rows that were stamped are cleared —
 * so there is exactly one theme per user and everybody can always see their own.
 *
 * The column is left in place. It is harmless, and dropping it would put this table out of step
 * with the schema test that expects `company_id` on tenant-swept tables.
 */
return new class extends Migration
{
    private const TABLE = 'user_theme_settings';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        // One row per user means a stamped company is noise at best and a lockout at worst.
        DB::statement('UPDATE ' . self::TABLE . ' SET company_id = NULL WHERE company_id IS NOT NULL');
        DB::statement('ALTER TABLE ' . self::TABLE . ' ALTER COLUMN company_id DROP DEFAULT');

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        // The shared-row shape used elsewhere in this codebase: a NULL company belongs to nobody
        // in particular, so it is visible to everybody rather than to no one.
        $policy = "company_id IS NULL OR {$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON ' . self::TABLE);
        DB::statement("CREATE POLICY tenant_isolation ON " . self::TABLE . " USING ({$policy}) WITH CHECK ({$policy})");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable(self::TABLE)) {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        DB::statement("ALTER TABLE " . self::TABLE . " ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON ' . self::TABLE);
        DB::statement("CREATE POLICY tenant_isolation ON " . self::TABLE . " USING ({$policy}) WITH CHECK ({$policy})");
    }
};
