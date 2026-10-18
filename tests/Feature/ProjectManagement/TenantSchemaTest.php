<?php

namespace Tests\Feature\ProjectManagement;

use Illuminate\Support\Facades\DB;

/**
 * Every pm_ table is isolated in the database, not only in the ORM.
 *
 * ── Why this test has to exist ──────────────────────────────────────────────
 *
 * Tenant isolation in this module has two independent halves. `BelongsToCompany` adds a global
 * scope, which protects anything read through Eloquent. Postgres row-level security protects
 * everything else: a raw `DB::table()`, a report builder, a join written in SQL, a psql session.
 * Only the second half is a guarantee.
 *
 * The trouble is that the RLS half is applied by a private `applyTenantRls()` helper **copied into
 * each migration** — there is no shared base class enforcing it. Forgetting the call is a silent
 * failure with no symptom: the global scope hides the omission from every ordinary test, and
 * `app.company_id` is deliberately left unset in the test environment, so the raw-SQL paths that
 * would expose it are unprotected there too. A new table can ship leaking one tenant's money to
 * another and the suite stays green.
 *
 * So the guarantee is asserted about the *schema* rather than about any one query. Procurement
 * Governance already does this for `procgov_%` (StandaloneAndTenancyTest); this is the same sweep
 * for `pm_%`, and it is the reason a new Project Management table cannot quietly skip the block.
 *
 * A failure here is not a test to adjust. It means a migration is missing `applyTenantRls()`.
 */
class TenantSchemaTest extends ProjectManagementTestCase
{
    /**
     * Tables that are legitimately not tenant-scoped, each with the reason.
     *
     * Keep this list short and argued. "It was awkward to scope" is not a reason — every entry
     * here is a table where a company_id would be actively wrong.
     */
    private const EXEMPT = [
        // Idempotency keys are claimed before the request has resolved a tenant; the key's own
        // uniqueness is the guarantee, and the stored response is only ever replayed to the
        // caller that presented the same key.
        'pm_idempotency_keys',
    ];

    public function test_every_pm_table_carries_a_tenant_column(): void
    {
        $tables = $this->pmTables();

        // A floor, not a count: it fails loudly if the sweep ever matches nothing (a renamed
        // prefix, a schema search-path change) and starts passing vacuously.
        $this->assertGreaterThan(40, $tables->count(), 'The pm_ table sweep found suspiciously few tables.');

        foreach ($tables as $table) {
            $this->assertTrue(
                DB::table('information_schema.columns')
                    ->where('table_schema', 'public')
                    ->where('table_name', $table)
                    ->where('column_name', 'company_id')
                    ->exists(),
                "{$table} has no company_id and therefore cannot be tenant-isolated.",
            );
        }
    }

    public function test_every_pm_table_has_row_level_security_forced(): void
    {
        // FORCE matters as much as ENABLE: without it the table owner bypasses the policy, and
        // the application connects as the owner. ENABLE alone protects nobody here.
        $unprotected = DB::select("
            select c.relname
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public'
              and c.relkind = 'r'
              and c.relname like 'pm\\_%'
              and c.relname <> all(?)
              and (c.relrowsecurity = false or c.relforcerowsecurity = false)
        ", ['{' . implode(',', self::EXEMPT) . '}']);

        $this->assertSame(
            [],
            array_map(fn ($row) => $row->relname, $unprotected),
            'These Project Management tables are missing FORCE row-level security. '
            . 'The migration that created them needs its applyTenantRls() call.',
        );
    }

    public function test_every_pm_table_has_a_tenant_isolation_policy(): void
    {
        // RLS with no policy denies everything rather than isolating anything, so the two
        // assertions are not interchangeable — a table can be FORCEd and still have no rule.
        $missing = DB::select("
            select c.relname
            from pg_class c
            join pg_namespace n on n.oid = c.relnamespace
            where n.nspname = 'public'
              and c.relkind = 'r'
              and c.relname like 'pm\\_%'
              and c.relname <> all(?)
              and not exists (
                  select 1 from pg_policy p
                  where p.polrelid = c.oid and p.polname = 'tenant_isolation'
              )
        ", ['{' . implode(',', self::EXEMPT) . '}']);

        $this->assertSame(
            [],
            array_map(fn ($row) => $row->relname, $missing),
            'These Project Management tables have no tenant_isolation policy.',
        );
    }

    /**
     * The exemption list describes tables that exist.
     *
     * Without this, a table renamed out from under an exemption leaves a permanent hole: the
     * sweep would keep excluding a name nothing matches, and the real table would go unchecked.
     */
    public function test_the_exemption_list_is_current(): void
    {
        $tables = $this->pmTables(includeExempt: true);

        foreach (self::EXEMPT as $exempt) {
            $this->assertTrue(
                $tables->contains($exempt),
                "{$exempt} is exempted from the tenancy sweep but no longer exists. Remove the exemption.",
            );
        }
    }

    /** @return \Illuminate\Support\Collection<int, string> */
    private function pmTables(bool $includeExempt = false): \Illuminate\Support\Collection
    {
        $tables = DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_type', 'BASE TABLE')
            ->where('table_name', 'like', 'pm\_%')
            ->orderBy('table_name')
            ->pluck('table_name');

        return $includeExempt
            ? $tables
            : $tables->reject(fn (string $t) => in_array($t, self::EXEMPT, true))->values();
    }
}
