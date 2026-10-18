<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telling the whole project something (EPIC-031).
 *
 * A task comment reaches the people watching that task; a status change reaches whoever reads the
 * dashboard. Neither reaches "everyone on this job", which is what a shutdown, a revised access
 * route or a change of site rules needs — and which is currently done on WhatsApp, where there is
 * no record of who was told.
 *
 * `pm_announcement_reads` is what makes it more than a notice board: for anything with a safety or
 * contractual consequence, the useful question is not "was it posted" but "who has seen it", and
 * that has to be a row per person rather than a flag on the announcement.
 *
 * A null `project_id` means the whole portfolio — company-wide notices go through the same
 * mechanism rather than a second one that would have to be kept in step.
 */
return new class extends Migration
{
    private const TABLES = ['pm_announcements', 'pm_announcement_reads'];

    public function up(): void
    {
        Schema::create('pm_announcements', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('project_id')->nullable()->index();

            $t->string('title', 190);
            $t->text('body');
            $t->string('severity', 20)->default('info');   // info | important | urgent
            $t->boolean('is_pinned')->default(false);
            // Acknowledgement is opt-in per notice: asking for it on everything trains people to
            // click it without reading, which destroys its value on the notice that mattered.
            $t->boolean('requires_ack')->default(false);
            // Urgent notices may go out by SMS. Recorded so the cost is attributable and so nobody
            // wonders later why one notice was texted and another was not.
            $t->boolean('sent_sms')->default(false);

            $t->timestamp('published_at')->nullable()->index();
            $t->timestamp('expires_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();

            $t->timestamps();
            $t->softDeletes();

            $t->index(['company_id', 'project_id', 'published_at']);
        });

        Schema::create('pm_announcement_reads', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->unsignedBigInteger('announcement_id');
            $t->unsignedBigInteger('user_id');
            $t->timestamp('read_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps();

            // One row per person per notice. Without this, a double-click records being told twice.
            $t->unique(['announcement_id', 'user_id'], 'pm_announcement_reads_unique');
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
