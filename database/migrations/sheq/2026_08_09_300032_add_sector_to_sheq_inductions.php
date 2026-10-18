<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sector-aware inductions, and a shared library.
 *
 * An induction is often the only safety briefing a visitor or short-term contractor will ever
 * receive on that site, and the one minute of attention they give it is finite. Spending it telling
 * a warehouse visitor about self-contained self-rescuers wastes it; not telling a mine visitor about
 * them is the omission that kills them underground.
 *
 * `company_id` becomes nullable so the module can ship a sector library the way it already ships
 * inspection templates and regulatory packs — a mine starts from a mining induction rather than a
 * blank page, and a tenant who wants a variant publishes their own under the same code, which wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_inductions', function (Blueprint $table) {
            /** MINING, CONSTRUCTION, MANUFACTURING, LOGISTICS, ENERGY, AGRICULTURE, ALL. */
            $table->string('sector', 30)->default('ALL')->index()->after('audience');

            /** The legal basis, so a practitioner can check the content against what it claims. */
            $table->string('basis', 255)->nullable()->after('sector');
        });

        // Postgres refuses to alter a column that a policy references, so the policy comes off
        // before the type change and goes back on after — never leaving the table unprotected in
        // between, because DDL here runs inside the migration's transaction.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON sheq_inductions');
        }

        Schema::table('sheq_inductions', function (Blueprint $table) {
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Shared rows (company_id NULL) must stay readable by every tenant, as with the
            // inspection template library.
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $shared = "{$session} IS NULL OR company_id IS NULL OR company_id = {$session}::bigint";

            DB::statement("CREATE POLICY tenant_isolation ON sheq_inductions USING ({$shared}) WITH CHECK ({$shared})");
        }
    }

    public function down(): void
    {
        Schema::table('sheq_inductions', function (Blueprint $table) {
            $table->dropColumn(['sector', 'basis']);
        });
    }
};
