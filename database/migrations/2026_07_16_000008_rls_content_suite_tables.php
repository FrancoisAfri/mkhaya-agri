<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Row-level security for the AI Content Marketing & SEO Suite tables (created in
 * 2026_07_16_000007, i.e. after the bulk tenant-scope retrofit ran). Applies the
 * exact same policy as every other tenant table: DEFAULT company_id from the
 * app.company_id session var so any insert auto-tags the tenant, and FORCE RLS so
 * rows are visible/writable only for the current tenant — and ALL rows when the
 * session var is unset (console, seeders, tests, self-hosted single-company edition).
 * Idempotent; Postgres-only.
 */
return new class extends Migration
{
    /** @var array<string> */
    private array $tables = [
        'content_websites', 'content_marketing_settings',
        'content_articles', 'content_brand_memory', 'content_keywords',
        'content_competitors', 'content_research_briefs', 'content_connectors',
    ];

    private function sessionExpr(): string
    {
        $q = chr(39); // single quote

        return "NULLIF(current_setting({$q}app.company_id{$q}, true), {$q}{$q})";
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $default = $this->sessionExpr() . '::bigint';
        $policy = $this->sessionExpr() . ' IS NULL OR company_id = ' . $this->sessionExpr() . '::bigint';

        foreach ($this->tables as $t) {
            if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'company_id')) {
                continue;
            }
            DB::statement("ALTER TABLE \"{$t}\" ALTER COLUMN company_id SET DEFAULT {$default}");
            DB::statement("ALTER TABLE \"{$t}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE \"{$t}\" FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$t}\"");
            DB::statement("CREATE POLICY tenant_isolation ON \"{$t}\" USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $t) {
            if (! Schema::hasTable($t)) {
                continue;
            }
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON \"{$t}\"");
            DB::statement("ALTER TABLE \"{$t}\" NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE \"{$t}\" DISABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE \"{$t}\" ALTER COLUMN company_id DROP DEFAULT");
        }
    }
};
