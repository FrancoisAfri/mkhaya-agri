<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-level security backstop (Phase G). Postgres enforces tenant isolation on
 * fin_invoices directly, behind the app-level CompanyScope, so a bug (or a raw
 * query that forgets to scope) still cannot leak across tenants.
 *
 * The policy allows all rows when `app.company_id` is unset — that keeps console,
 * seeders, tests and the platform admin (who set no tenant) fully functional; the
 * ResolveCompany middleware sets it for authenticated tenant requests.
 *
 * fin_invoices is the template; extend to other tenant tables the same way.
 */
return new class extends Migration
{
    private array $tables = ['fin_invoices'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("
                CREATE POLICY tenant_isolation ON {$table}
                USING (
                    NULLIF(current_setting('app.company_id', true), '') IS NULL
                    OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
                )
                WITH CHECK (
                    NULLIF(current_setting('app.company_id', true), '') IS NULL
                    OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
                )
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
