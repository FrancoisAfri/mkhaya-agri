<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happens *around* a task (PM-1121, PM-1122).
 *
 *  pm_task_checklist_items  the sub-steps that are not worth being tasks
 *  pm_task_comments         the conversation, threaded, with @mentions
 *  pm_task_watchers         who hears about it
 *  pm_task_attachments      files, held by whichever DocumentStore is installed
 *  pm_task_recurrences      the rule that keeps producing "inspect the scaffold, every Monday"
 *
 * Comments and checklist items are edited in place rather than versioned: this is a working
 * conversation, not the evidence ledger. Anything that must survive an argument about what happened
 * belongs in pm_evidence_events, which is append-only for exactly that reason (PM-ADR-002).
 */
return new class extends Migration
{
    private const TABLES = [
        'pm_task_checklist_items',
        'pm_task_comments',
        'pm_task_watchers',
        'pm_task_attachments',
        'pm_task_recurrences',
    ];

    public function up(): void
    {
        Schema::create('pm_task_checklist_items', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('task_id');
            $t->string('text', 500);
            $t->integer('position')->default(0);
            $t->boolean('is_done')->default(false);
            $t->unsignedBigInteger('done_by')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('task_id')->references('id')->on('pm_tasks')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'task_id', 'position']);
        });

        Schema::create('pm_task_comments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('task_id');
            // Threading is one level deep: a reply to a reply reads as a reply to the same comment.
            // Arbitrary nesting looks powerful and reads as a maze on a phone in the field.
            $t->unsignedBigInteger('parent_comment_id')->nullable();
            $t->unsignedBigInteger('author_id')->nullable();
            $t->text('body');
            // Resolved at write time so a rename never silently re-points a mention at someone else.
            $t->json('mentions')->default(DB::raw("'[]'::json"));
            $t->timestamp('edited_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('task_id')->references('id')->on('pm_tasks')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->foreign('parent_comment_id')->references('id')->on('pm_task_comments')->nullOnDelete();
            $t->index(['company_id', 'task_id', 'created_at']);
        });

        Schema::create('pm_task_watchers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('task_id');
            $t->unsignedBigInteger('user_id');
            // How they got here: manual | creator | assignee | mention. Kept because an automatic
            // subscription should be removable without the system silently re-adding it.
            $t->string('source', 20)->default('manual');
            $t->boolean('is_muted')->default(false);
            $t->timestamps();

            $t->foreign('task_id')->references('id')->on('pm_tasks')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'task_id']);
            $t->index(['company_id', 'user_id']);
        });

        Schema::create('pm_task_attachments', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('task_id');
            // The id the DocumentStore gave back — a pm_external_refs uuid locally, or the
            // Documents module's own id when that is installed. PM never holds the bytes itself.
            $t->string('document_id', 64);
            $t->string('filename', 255);
            $t->string('mime_type', 128)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('task_id')->references('id')->on('pm_tasks')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'task_id']);
        });

        // ── Recurrence (PM-1122) ──────────────────────────────────────────────
        // The template task is never scheduled or reported on; it exists to be copied. Occurrences
        // are real tasks with occurrence_of_id pointing back, so completing one is an ordinary
        // completion and the ledger needs no special case.
        Schema::create('pm_task_recurrences', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id');
            $t->unsignedBigInteger('template_task_id');
            $t->string('rrule', 500);                 // RFC 5545 RRULE, the subset in Support\Recurrence\Rrule
            $t->string('timezone', 64);               // the site's clock, not the server's
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();     // hard stop regardless of UNTIL/COUNT
            // Materialise this far ahead and no further: a daily rule with no end would otherwise
            // generate to the heat death of the schedule.
            $t->unsignedSmallInteger('horizon_days')->default(60);
            $t->timestamp('last_generated_at')->nullable();
            $t->timestamp('last_occurrence_at')->nullable();
            $t->unsignedInteger('generated_count')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->foreign('template_task_id')->references('id')->on('pm_tasks')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('pm_projects')->cascadeOnDelete();
            $t->index(['company_id', 'is_active']);
        });

        Schema::table('pm_tasks', function (Blueprint $t) {
            // Set on generated tasks; null on everything else. Also marks the template itself via
            // is_recurrence_template so list views can exclude it.
            $t->unsignedBigInteger('occurrence_of_id')->nullable()->after('parent_task_id');
            $t->timestamp('occurrence_date')->nullable()->after('occurrence_of_id');
            $t->boolean('is_recurrence_template')->default(false)->after('is_milestone');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX pm_task_watchers_unique ON pm_task_watchers (company_id, task_id, user_id)');
            DB::statement("ALTER TABLE pm_task_watchers ADD CONSTRAINT pm_task_watchers_source_chk CHECK (source IN ('manual','creator','assignee','mention'))");
            // The generator's idempotency guarantee: re-running for the same window cannot produce
            // the same occurrence twice, no matter how the run was triggered.
            DB::statement('CREATE UNIQUE INDEX pm_tasks_occurrence_unique ON pm_tasks (occurrence_of_id, occurrence_date) WHERE occurrence_of_id IS NOT NULL AND deleted_at IS NULL');

            $this->applyTenantRls(self::TABLES);
        }
    }

    public function down(): void
    {
        Schema::table('pm_tasks', function (Blueprint $t) {
            $t->dropColumn(['occurrence_of_id', 'occurrence_date', 'is_recurrence_template']);
        });

        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function applyTenantRls(array $tables): void
    {
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
