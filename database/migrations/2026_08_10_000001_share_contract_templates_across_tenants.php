<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets `contracts_templates` carry a system-wide library that every tenant can read.
 *
 * The standard tenant policy admits a row only when its company_id equals the session variable.
 * A null company_id is therefore invisible to every tenant — which is fine for tables where a null
 * means "belongs to nobody", but not for a shipped template library that all tenants are meant to
 * share. Without this the library installs cleanly and shows up empty for everybody, which is the
 * worst kind of bug to diagnose.
 *
 * This is the same allowance `sheq_inspection_templates` already carries, and it must match
 * `ContractsTemplate::sharesGlobalRecords()` — the ORM scope and the database policy have to agree
 * or one of them silently filters the shared rows back out.
 *
 * Writes are still constrained: WITH CHECK permits a tenant to write only its own rows, so a
 * tenant cannot create or alter a shared template. Only a context with no company set — the
 * installer, running in console — can write the shared library.
 */
return new class extends Migration
{
    private const READ = "NULLIF(current_setting('app.company_id', true), '') IS NULL "
        ."OR company_id IS NULL "
        ."OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint";

    private const WRITE = "NULLIF(current_setting('app.company_id', true), '') IS NULL "
        ."OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint";

    public function up(): void
    {
        if (! Schema::hasTable('contracts_templates') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON contracts_templates');
        DB::statement('CREATE POLICY tenant_isolation ON contracts_templates '
            .'USING ('.self::READ.') WITH CHECK ('.self::WRITE.')');
    }

    public function down(): void
    {
        if (! Schema::hasTable('contracts_templates') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Back to strict isolation. Any shared templates become invisible to tenants again, so the
        // library should be reinstalled per tenant if this is ever rolled back.
        DB::statement('DROP POLICY IF EXISTS tenant_isolation ON contracts_templates');
        DB::statement('CREATE POLICY tenant_isolation ON contracts_templates '
            .'USING ('.self::WRITE.') WITH CHECK ('.self::WRITE.')');
    }
};
