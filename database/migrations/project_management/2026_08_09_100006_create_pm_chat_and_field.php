<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project chat (§19) and the field records that were still missing (§22).
 *
 * ── Chat is per project, not per task ───────────────────────────────────────
 *
 * Task comments already exist and are the right place for "is this pour ready". Chat is for the
 * conversation that has no task yet — which is most of what happens on a job before somebody writes
 * it down. Keeping them separate means neither has to pretend to be the other, and the task's
 * comment thread stays about the task.
 *
 * ── Equipment inspections are their own record ──────────────────────────────
 *
 * Not a snag and not a material test: a pre-use inspection is a *scheduled, repeating* check on a
 * machine that mostly passes, and its value is the unbroken run of records rather than any one of
 * them. Filing them as snags would fill the defect register with things that were fine.
 */
return new class extends Migration
{
    private const TABLES = ['pm_chat_messages', 'pm_equipment_inspections'];

    public function up(): void
    {
        Schema::create('pm_chat_messages', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('author_name', 190);              // snapshotted, so a removed user still reads
            $t->text('body');
            // A message can point at something. Kept as a loose reference rather than a foreign key
            // because the thing referred to may be deleted and the conversation still makes sense.
            $t->string('about_type', 30)->nullable();
            $t->uuid('about_uuid')->nullable();
            $t->uuid('attachment_uuid')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'created_at']);
        });

        Schema::create('pm_equipment_inspections', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->index();

            // What was inspected. A linked asset or fleet vehicle where those modules hold it, and
            // a typed name where they do not — a hired excavator has no asset record here.
            $t->uuid('external_ref_uuid')->nullable();
            $t->string('equipment', 190);
            // Read off the machine. The reason the field app wants a scanner at all.
            $t->string('identifier', 90)->nullable();

            $t->date('inspected_on');
            $t->string('inspector', 190)->nullable();
            $t->string('result', 20)->default('pass');   // pass | fail | conditional
            $t->jsonb('checklist')->nullable();          // [{item, ok, note}]
            $t->text('defects')->nullable();
            $t->unsignedBigInteger('snag_id')->nullable();
            $t->date('next_due')->nullable();
            // Typed name, time and address — the same shape as a document signature, because it is
            // the same claim: this person says they checked this machine.
            $t->string('signed_by', 190)->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->string('ip_address', 45)->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'inspected_on']);
        });

        $this->applyTenantRls(self::TABLES);
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
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
