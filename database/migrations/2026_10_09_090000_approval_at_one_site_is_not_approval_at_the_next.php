<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contractor approval, decided per site (CON-14).
 *
 * A contractor was approved or blocked once, for the whole company. `sheq_contractors.site_id`
 * is one site — "where they are permitted to work" — which cannot say that ABC Construction is
 * cleared at Johannesburg and Durban but blocked at Cape Town. The nearest thing to a per-site
 * decision was a document of type SITE_SPECIFIC, which is evidence somebody supplied, not a
 * decision anybody took, and carries no reason, no approver and no expiry of its own.
 *
 * So: one row per contractor per site, and the absence of a row is not approval. The three states
 * are deliberately distinct, because they are three different things to a guard:
 *
 * - **APPROVED** — somebody decided, and it may lapse on `valid_until`.
 * - **BLOCKED** — somebody decided the other way. This refuses entry whatever the company-wide
 *   status says, because a site that has banned a contractor has usually banned them for a reason
 *   the company-wide file knows nothing about.
 * - **No row at all** — nobody has decided. Whether that refuses entry is the tenant's existing
 *   `MISSING_SITE_APPROVAL` blocking rule, which ships advisory. Making absence refuse by default
 *   would block every contractor at every site the moment this migration ran.
 *
 * `conditions` is free text on purpose: "escorted at all times", "daylight hours only", "not in
 * the tank farm". It prints on the gate verdict. A structured vocabulary here would be a
 * vocabulary somebody has to maintain before they can write down the one condition they actually
 * imposed, and the condition would go unrecorded instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_contractor_site_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('contractor_id')->constrained('sheq_contractors')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sheq_sites')->cascadeOnDelete();

            $table->string('status', 20)->default('APPROVED');

            /*
             * Why, for both decisions. A block with no reason is a block nobody can lift, because
             * the next person to look has no way of knowing whether the cause has been dealt with.
             */
            $table->text('reason')->nullable();

            /** Conditions of entry, printed on the gate verdict when they are approved. */
            $table->text('conditions')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();

            /*
             * An approval that lapses. Null means it does not, which is the honest default: most
             * sites approve a contractor for the duration of the work and review it when the
             * safety file is renewed, not on a date of their own.
             */
            $table->date('valid_until')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'contractor_id', 'status'], 'sheq_csa_contractor_idx');
            $table->index(['company_id', 'site_id', 'status'], 'sheq_csa_site_idx');
            $table->index(['company_id', 'valid_until'], 'sheq_csa_expiry_idx');
        });

        /*
         * One live decision per contractor per site.
         *
         * Partial, because a soft-deleted row must not hold the slot: a site that revoked an
         * approval and later granted a new one would otherwise collide with the old row forever.
         * A plain unique index over a nullable `deleted_at` would not do it either — Postgres
         * never collides NULLs, so every deleted row would still be unique against the live one.
         */
        DB::statement(
            'CREATE UNIQUE INDEX sheq_csa_live_uniq ON sheq_contractor_site_approvals '
            .'(company_id, contractor_id, site_id) WHERE deleted_at IS NULL'
        );

        $this->applyTenantRls(['sheq_contractor_site_approvals']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_contractor_site_approvals');
    }

    /**
     * The same row-level security every other SHEQ table carries.
     *
     * Not optional for this one in particular: it decides who may walk onto a site, so a query
     * that forgets to scope would answer another tenant's question. The policy allows an unset
     * `app.company_id` so seeders, console commands and the platform admin still work — see the
     * SHEQ foundation migration, which this copies verbatim rather than inventing a variant of.
     */
    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
