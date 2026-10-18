<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal register (§5.10, SHEQ-LEG-1..4).
 *
 * A legal register is the document an inspector asks for first: which law applies to us, who owns
 * it, and what proves we are doing it. Most implementations are a spreadsheet with a self-declared
 * "Compliant" column, which is worth nothing — SHEQ-LEG-3 exists precisely to forbid that. Here the
 * status is *derived* from linked evidence and recomputed; a line with nothing linked reports
 * `NOT_ASSESSED`, never compliant.
 *
 * Three structural choices carry that:
 *
 * - **Lines are seeded from the pack, not typed in.** They carry `regulatory_pack_id` and the
 *   version they were seeded from, so SHEQ-LEG-4 can tell which lines a pack update touched.
 * - **Evidence links are a separate table.** One clause is usually satisfied by several things at
 *   once (GSR 3 needs an inspection, a competency *and* an appointment), and each must be checked
 *   on its own terms. A single foreign key could not express that.
 * - **Tenant-owned fields are kept apart from pack-owned ones.** `responsible_person_id`, `notes`
 *   and the evidence links belong to the tenant and survive a pack upgrade; the clause text belongs
 *   to the pack and is replaced by it. Mixing them would make an upgrade destroy local work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_legal_register_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // ── Pack-owned. Replaced wholesale when a pack version changes the clause.
            $table->string('regulatory_pack_id', 60);
            $table->string('seeded_from_version', 40);
            $table->string('instrument');                       // "Occupational Health and Safety Act 85 of 1993"
            $table->string('clause', 60);                       // "s16(2)", "GSR 3"
            $table->text('requirement');                        // plain language, per SHEQ-LEG-1
            $table->string('applies_to', 60)->default('ALL');   // ALL|EMPLOYEES_GTE_20|SITE_TYPE_PROJECT…
            $table->string('responsible_role', 60)->nullable();
            $table->jsonb('evidence_types');                    // ["INSPECTION","COMPETENCY"]
            $table->string('review_frequency', 20)->nullable(); // ISO-8601 duration, P1Y

            // ── Tenant-owned. Survives a pack upgrade.
            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->unsignedBigInteger('responsible_person_id')->nullable();   // hr_people
            $table->text('notes')->nullable();
            $table->boolean('is_applicable')->default(true);    // a tenant may rule a clause out…
            $table->text('not_applicable_reason')->nullable();  // …but must say why (SHEQ-LEG-3)

            /**
             * COMPLIANT|PARTIAL|NON_COMPLIANT|NOT_ASSESSED|NOT_APPLICABLE|REVIEW_REQUIRED
             *
             * Never written by a user. The only route in is the status computation, which is why
             * there is no "declared_status" column anywhere in this table — the absence is the
             * control.
             */
            $table->string('status', 20)->default('NOT_ASSESSED');
            $table->timestampTz('status_computed_at')->nullable();
            $table->jsonb('status_detail')->nullable();         // per-evidence findings, for the UI
            $table->date('review_due')->nullable();
            $table->date('last_reviewed_on')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One line per clause per tenant per site. A group-level line (null site) coexists with
            // site-specific ones; PostgreSQL treats NULLs as distinct, which is the behaviour wanted
            // here, so the group line is enforced separately below.
            $table->unique(['company_id', 'regulatory_pack_id', 'clause', 'site_id'],
                'sheq_legal_line_uniq');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'review_due']);
        });

        Schema::create('sheq_legal_register_evidence', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('sheq_legal_register_lines')->cascadeOnDelete();

            // DOCUMENT|INSPECTION_SCHEDULE|COMPETENCY|APPOINTMENT|ACTION|RISK_ASSESSMENT|OBLIGATION
            $table->string('evidence_type', 40);
            $table->unsignedBigInteger('evidence_id');
            $table->string('evidence_label')->nullable();       // denormalised for the register view

            // What this particular link contributes, evaluated on its own terms.
            $table->string('status', 20)->default('NOT_ASSESSED');
            $table->text('status_detail')->nullable();
            $table->timestampTz('status_computed_at')->nullable();

            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'line_id', 'evidence_type', 'evidence_id'],
                'sheq_legal_evidence_uniq');
            $table->index(['company_id', 'evidence_type', 'evidence_id']);
        });

        /**
         * SHEQ-LEG-4: what a pack update did to a line, and whether anyone has looked at it.
         *
         * Kept as a history rather than a flag on the line, because a clause can change twice before
         * anybody reviews it and the second diff must not erase the first.
         */
        Schema::create('sheq_legal_register_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('sheq_legal_register_lines')->cascadeOnDelete();

            $table->string('from_version', 40)->nullable();
            $table->string('to_version', 40);
            $table->string('change_type', 20);                  // ADDED|AMENDED|REMOVED
            $table->jsonb('diff');                              // field => {from, to}

            $table->timestampTz('flagged_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'line_id']);
            $table->index(['company_id', 'reviewed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // The group-level line: exactly one per clause per tenant when site_id is null. The
            // composite unique above cannot enforce this because NULL never equals NULL.
            DB::statement('CREATE UNIQUE INDEX sheq_legal_line_group_uniq
                ON sheq_legal_register_lines (company_id, regulatory_pack_id, clause)
                WHERE site_id IS NULL AND deleted_at IS NULL');
        }

        $this->applyTenantRls([
            'sheq_legal_register_lines', 'sheq_legal_register_evidence', 'sheq_legal_register_changes',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_legal_register_changes');
        Schema::dropIfExists('sheq_legal_register_evidence');
        Schema::dropIfExists('sheq_legal_register_lines');
    }

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
