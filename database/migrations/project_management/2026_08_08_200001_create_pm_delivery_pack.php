<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery pack: what a construction project actually runs on (EPIC-100).
 *
 * Everything here exists because a contractor cannot use a project module without it, and because
 * each of these is a *record with money or liability attached* rather than a note:
 *
 *  pm_site_diaries       the daily record — who was on site, what plant, what weather, what
 *                        happened. It is the first document produced in a delay claim, and a
 *                        project that did not keep one has no defence.
 *  pm_boq_items          the bill of quantities. Rates × quantities is what the job was priced at,
 *                        and measured-to-date against it is what gets certified for payment.
 *  pm_snags              defects, punch-list items and non-conformances. One table, because they
 *                        differ by severity and consequence, not by shape.
 *  pm_progress_claims    the certificate: work done this period, less retention, less what was
 *                        already certified. This is how a contractor gets paid.
 *  pm_resources          rooms, licences and anything else the tenant schedules that no other
 *                        module owns.
 *
 * ── Retention ───────────────────────────────────────────────────────────────
 *
 * Retention is money the client keeps back as security, released at practical and final
 * completion. It is held on the *claim* rather than the project because the percentage can change
 * between certificates and the amount held is per-certificate — a single project-level figure
 * would be wrong the first time it moved, and wrong in the direction of overstating what is owed.
 */
return new class extends Migration
{
    private const TABLES = ['pm_site_diaries', 'pm_boq_items', 'pm_snags', 'pm_progress_claims', 'pm_resources'];

    public function up(): void
    {
        // ── The daily site record ─────────────────────────────────────────────
        Schema::create('pm_site_diaries', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->date('entry_date');
            // Weather is not decoration: a rained-off day is the evidence behind an extension of
            // time, and it has to have been recorded on the day to be worth anything.
            $t->string('weather', 40)->nullable();
            $t->decimal('temperature_c', 5, 1)->nullable();
            $t->unsignedSmallInteger('rain_hours')->nullable();
            $t->unsignedSmallInteger('workers_on_site')->nullable();
            $t->text('work_done')->nullable();
            $t->text('plant_on_site')->nullable();
            $t->text('deliveries')->nullable();
            $t->text('visitors')->nullable();
            $t->text('delays')->nullable();
            $t->text('instructions')->nullable();
            $t->text('safety_notes')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'project_id', 'entry_date']);
        });

        // One diary per site per day. Two entries for the same day is two accounts of what
        // happened, and a claim built on the wrong one is a claim that falls apart.
        $this->partialUnique('pm_site_diaries', ['company_id', 'project_id', 'entry_date'], 'pm_site_diaries_day_unique');

        // ── Bill of quantities ────────────────────────────────────────────────
        Schema::create('pm_boq_items', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('task_id')->nullable();     // measured work often maps to a task
            $t->string('item_no', 40)->nullable();             // 1.2.3 — the reference on the priced BOQ
            $t->string('section', 120)->nullable();
            $t->string('description', 500);
            $t->string('unit', 20);                            // m, m2, m3, kg, sum, item
            $t->decimal('quantity', 18, 3);
            $t->decimal('rate', 18, 2);
            $t->decimal('amount', 18, 2);                      // quantity × rate, stored
            // Measured to date. Cumulative, because a claim certifies work *to date* less what was
            // certified before — period-by-period figures would have to be summed to mean anything
            // and would drift the first time one was corrected.
            $t->decimal('measured_quantity', 18, 3)->default(0);
            $t->boolean('is_provisional')->default(false);     // provisional sums are not certain work
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('task_id')->references('id')->on('pm_tasks')->nullOnDelete();
            $t->index(['company_id', 'project_id', 'sort_order']);
        });

        // ── Snags, defects and non-conformances ───────────────────────────────
        Schema::create('pm_snags', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('task_id')->nullable();
            $t->string('reference', 40)->nullable();
            // snag | defect | ncr — the same shape, different consequence. An NCR is a formal
            // quality failure that has to be closed out with evidence; a snag is a paint scuff.
            $t->string('kind', 20)->default('snag');
            $t->string('title', 255);
            $t->text('description')->nullable();
            $t->string('location', 190)->nullable();
            $t->string('severity', 20)->default('minor');      // minor | major | critical
            $t->string('status', 20)->default('open');         // open | in_progress | ready | closed | rejected
            $t->unsignedBigInteger('raised_by')->nullable();
            $t->unsignedBigInteger('assigned_member_id')->nullable();
            $t->unsignedBigInteger('contractor_ref_id')->nullable();   // whose defect it is
            $t->date('due_date')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->text('closure_note')->nullable();
            // Photographs, before and after. A defect closed with no evidence is one somebody has
            // to walk out and check again.
            $t->string('photo_before_id', 190)->nullable();
            $t->string('photo_after_id', 190)->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('task_id')->references('id')->on('pm_tasks')->nullOnDelete();
            $t->foreign('assigned_member_id')->references('id')->on('pm_project_members')->nullOnDelete();
            $t->foreign('contractor_ref_id')->references('id')->on('pm_external_refs')->nullOnDelete();
            $t->index(['company_id', 'project_id', 'status', 'severity']);
        });

        // ── Progress claims / payment certificates ────────────────────────────
        Schema::create('pm_progress_claims', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedInteger('claim_number');
            $t->date('period_start');
            $t->date('period_end');
            // Work measured to date, gross. Everything else is derived from it and from the
            // previous certificate, which is how a payment certificate is actually built.
            $t->decimal('work_to_date', 18, 2)->default(0);
            $t->decimal('materials_on_site', 18, 2)->default(0);
            $t->decimal('variations', 18, 2)->default(0);
            $t->decimal('previously_certified', 18, 2)->default(0);
            $t->decimal('retention_pct', 5, 2)->default(0);
            $t->decimal('retention_held', 18, 2)->default(0);
            $t->decimal('retention_released', 18, 2)->default(0);
            $t->decimal('net_due', 18, 2)->default(0);
            $t->string('currency', 3);
            // A milestone claim: some contracts pay on milestones rather than on measure.
            $t->unsignedBigInteger('milestone_task_id')->nullable();
            $t->string('status', 20)->default('draft');   // draft | submitted | certified | paid | rejected
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('certified_at')->nullable();
            $t->unsignedBigInteger('certified_by')->nullable();
            $t->text('notes')->nullable();
            $t->string('document_id', 190)->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('milestone_task_id')->references('id')->on('pm_tasks')->nullOnDelete();
            $t->index(['company_id', 'project_id', 'status']);
        });

        // Claim numbers are quoted on invoices and referenced in contracts: one per number per
        // project, among live rows.
        $this->partialUnique('pm_progress_claims', ['company_id', 'project_id', 'claim_number'], 'pm_progress_claims_number_unique');

        // ── Resources PM owns ─────────────────────────────────────────────────
        // Rooms, software licences and any plant not in the Asset Register. Kept here rather than
        // forced into another module's table: a meeting room is not an asset the finance team
        // depreciates, and inventing an asset record for one would pollute their register.
        Schema::create('pm_resources', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('kind', 20);                        // room | licence | plant | other
            $t->string('name', 190);
            $t->string('identifier', 90)->nullable();      // room number, licence key reference
            $t->unsignedSmallInteger('capacity')->nullable();   // seats, or concurrent licences
            $t->decimal('rate_per_day', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->date('available_from')->nullable();
            $t->date('available_to')->nullable();          // licence expiry
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'kind', 'is_active']);
        });

        // ── Documents gain a version chain ────────────────────────────────────
        Schema::table('pm_document_blobs', function (Blueprint $t) {
            // Version control by supersession rather than mutation, for the same reason the
            // evidence ledger is append-only: revision C of a drawing does not delete revision B,
            // and somebody built to B.
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('supersedes_id')->nullable()->index();
            $t->timestamp('superseded_at')->nullable();
            $t->string('revision', 20)->nullable();        // the contract revision: A, B, C
            // The e-sign envelope, when a document has been sent for signature.
            $t->string('esign_envelope_uuid', 40)->nullable()->index();
            $t->string('signature_status', 20)->nullable();
        });

        // ── Milestones can carry a payment ────────────────────────────────────
        Schema::table('pm_tasks', function (Blueprint $t) {
            // Only meaningful on a milestone. Kept on the task rather than a side table because a
            // milestone payment *is* an attribute of that milestone, and a join would invite two
            // payments against one.
            $t->decimal('payment_amount', 18, 2)->nullable();
            $t->boolean('payment_claimed')->default(false);
        });

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        Schema::table('pm_tasks', fn (Blueprint $t) => $t->dropColumn(['payment_amount', 'payment_claimed']));
        Schema::table('pm_document_blobs', fn (Blueprint $t) => $t->dropColumn([
            'version', 'supersedes_id', 'superseded_at', 'revision', 'esign_envelope_uuid', 'signature_status',
        ]));

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function partialUnique(string $table, array $columns, string $name): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX ' . $name . ' ON ' . $table . ' (' . implode(', ', $columns) . ') WHERE deleted_at IS NULL');
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
