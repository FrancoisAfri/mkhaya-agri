<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the construction pack, and meetings (§23, §18).
 *
 *  pm_site_instructions   an instruction from the engineer or client on site. It is the single most
 *                         common source of a variation claim, and the reason contractors keep a
 *                         numbered file of them: "you told us to" is only an argument if there is a
 *                         reference and a date.
 *  pm_material_tests      concrete cubes, compaction, welds. A test has a *sample date* and a
 *                         *result date* days or weeks apart, which is why it cannot be a snag: the
 *                         record has to exist while the answer is still unknown.
 *  pm_inspection_requests the formal "come and look before I cover this up" notice. Missing one is
 *                         what turns finished work into work that has to be opened again.
 *  pm_completion_records  practical and final completion, with the defects liability period between
 *                         them. This is what releases retention, so it is a record with money on it.
 *  pm_meetings            agenda, minutes, attendance and actions in one place, because minutes
 *                         without attendance prove nothing and actions without minutes get lost.
 *
 * Meeting actions are deliberately *not* a new table: they are tasks on the project, flagged with
 * `meeting_id`. An action item that lives only in minutes is one nobody works, and giving them a
 * separate register would mean two lists of things to do that never agree.
 */
return new class extends Migration
{
    private const TABLES = [
        'pm_site_instructions', 'pm_material_tests', 'pm_inspection_requests',
        'pm_completion_records', 'pm_meetings', 'pm_meeting_attendees',
    ];

    public function up(): void
    {
        Schema::create('pm_site_instructions', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();

            $t->string('number', 20);                    // SI-001
            $t->date('issued_on');
            $t->string('issued_by', 190)->nullable();    // the engineer or client rep, often not a user
            $t->string('title', 190);
            $t->text('instruction');
            // Whether the contractor considers it a change. Their view, recorded at the time —
            // deciding it later, after the work is done, is how variation claims are lost.
            $t->boolean('claimed_as_variation')->default(false);
            $t->unsignedBigInteger('change_request_id')->nullable()->index();
            $t->string('status', 20)->default('open');   // open | actioned | closed | disputed
            $t->text('response')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'issued_on']);
        });

        Schema::create('pm_material_tests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();

            $t->string('reference', 40);                 // cube set number, lab reference
            // concrete_cube | compaction | weld | soil | asphalt | steel | other
            $t->string('kind', 30)->default('concrete_cube');
            $t->string('element', 190)->nullable();      // "Ground floor slab, bay 3"
            $t->date('sampled_on');
            // The result arrives days or weeks later. Nullable is the normal state of this row for
            // most of its life, which is exactly why a test is not a snag.
            $t->date('result_on')->nullable();
            $t->decimal('specified_value', 12, 3)->nullable();
            $t->decimal('result_value', 12, 3)->nullable();
            $t->string('unit', 20)->nullable();          // MPa, %, kN
            $t->string('result', 20)->default('pending'); // pending | pass | fail
            $t->string('lab', 190)->nullable();
            $t->text('notes')->nullable();
            // A failed test becomes a non-conformance; the link proves which one.
            $t->unsignedBigInteger('snag_id')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'result']);
        });

        Schema::create('pm_inspection_requests', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();

            $t->string('number', 20);                    // IR-001
            $t->string('title', 190);
            $t->string('element', 190)->nullable();
            $t->unsignedBigInteger('task_id')->nullable()->index();
            // Notice period matters: an inspection requested for this afternoon is not a notice.
            $t->timestamp('requested_for');
            $t->timestamp('requested_at')->nullable();
            $t->string('inspector', 190)->nullable();
            $t->string('status', 20)->default('requested'); // requested | passed | failed | cancelled
            $t->timestamp('inspected_at')->nullable();
            $t->text('outcome_note')->nullable();
            $t->unsignedBigInteger('snag_id')->nullable()->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'status']);
        });

        Schema::create('pm_completion_records', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();

            // practical | sectional | final — sectional because large jobs hand over in pieces and
            // each piece starts its own defects period.
            $t->string('kind', 20)->default('practical');
            $t->string('section', 190)->nullable();
            $t->date('certified_on');
            $t->string('certified_by', 190)->nullable();
            $t->text('outstanding_work')->nullable();    // the list attached to the certificate
            // The defects liability period runs from practical completion, and its end is when the
            // rest of the retention is released. Stored, not computed, because contracts differ.
            $t->unsignedSmallInteger('defects_period_days')->nullable();
            $t->date('defects_period_ends')->nullable();
            $t->decimal('retention_released', 18, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->uuid('document_uuid')->nullable();       // the signed certificate itself
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pm_meetings', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->nullable()->index();

            $t->string('title', 190);
            // site | progress | technical | client | safety | kickoff | review | other
            $t->string('kind', 20)->default('progress');
            $t->timestamp('starts_at');
            $t->unsignedSmallInteger('duration_minutes')->default(60);
            $t->string('location', 190)->nullable();
            $t->string('meeting_url', 500)->nullable();
            $t->text('agenda')->nullable();
            $t->text('minutes')->nullable();
            $t->string('status', 20)->default('scheduled'); // scheduled | held | cancelled
            $t->timestamp('minutes_circulated_at')->nullable();
            $t->unsignedBigInteger('chaired_by')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'starts_at']);
        });

        Schema::create('pm_meeting_attendees', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('meeting_id')->index();
            // A user where they have an account, a name where they do not — a client's engineer
            // attends the meeting whether or not anybody gave them a login.
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('name', 190);
            $t->string('organisation', 190)->nullable();
            $t->string('attendance', 20)->default('invited'); // invited | present | apology | absent
            $t->timestamps();

            $t->unique(['meeting_id', 'name'], 'pm_meeting_attendees_unique');
        });

        // Actions live on the task list, not in a second register nobody works.
        Schema::table('pm_tasks', function (Blueprint $t) {
            $t->unsignedBigInteger('meeting_id')->nullable()->index();
        });

        foreach ([
            'pm_site_instructions'   => 'number',
            'pm_inspection_requests' => 'number',
            'pm_material_tests'      => 'reference',
        ] as $table => $column) {
            $this->partialUnique($table, ['company_id', 'project_id', $column], $table . '_ref_unique');
        }

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        Schema::table('pm_tasks', fn (Blueprint $t) => $t->dropColumn(['meeting_id']));

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
