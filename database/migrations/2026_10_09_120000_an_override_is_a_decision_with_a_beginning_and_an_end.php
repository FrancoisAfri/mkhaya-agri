<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Temporary access, as a decision rather than four columns on a contractor (CON-17).
 *
 * What was there: `access_overridden`, `access_override_reason`, `access_overridden_by` and
 * `access_override_expires` on `sheq_contractors`. It worked, and almost everything CON-17 asks
 * for was missing from it.
 *
 * **It never expired.** Nothing in the codebase ever set `access_overridden` back to false. The
 * only expiry was three date comparisons at read time, which means the cached `access_status`
 * column — the one the turnstile reads — kept saying EXPIRING after the override lapsed, until
 * something unrelated happened to recompute it. An override that outlives its own end date is
 * the one failure this feature cannot have.
 *
 * **There was no start.** Only an end date, so an override could not be arranged in advance for
 * tomorrow morning; somebody had to be at a desk at 06:00 to grant it.
 *
 * **There was no approver.** `access_overridden_by` is whoever clicked. CON-17 asks for an
 * approver as a separate fact, because the person typing is not always the person authorising,
 * and an audit of "who let them in" has to name the latter.
 *
 * **There were no conditions**, no scope, and no history: one override per contractor, each one
 * overwriting the last. "Has this contractor been overridden before" had no answer at all.
 *
 * And it could only ever cover a whole contractor. A single worker whose medical lapsed on the
 * day of a shutdown meant overriding their entire employer, which admits forty people to fix a
 * problem with one.
 *
 * So: a row per decision, with a beginning, an end, a scope, an approver and a reason; the old
 * columns backfilled into it and then dropped, because two places to look is how they disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_access_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /** CONTRACTOR or CONTRACTOR_WORKER — a whole firm, or one person. */
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id');

            /*
             * Where and what it covers. Null is "everywhere" and "all work", which is the
             * honest default for a shutdown — but a site or an activity narrows it, so an
             * override granted for one afternoon at the depot does not quietly admit the same
             * people to the refinery.
             */
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('activity', 60)->nullable();

            /** Why. Never optional: an override with no reason is one nobody can review. */
            $table->text('reason');

            /** What they must do while it lasts. Printed on the gate verdict. */
            $table->text('conditions')->nullable();

            /*
             * Two people, deliberately. `issued_by` is whoever typed it; `approved_by` is the
             * person who authorised it, which CON-17 asks for by name. On a small site they are
             * the same person and that is recorded honestly rather than hidden.
             */
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();

            /*
             * A window, not a deadline. Times rather than dates because a shutdown override runs
             * 06:00 to 18:00, and a date-only end would leave the gate open all night.
             */
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            /*
             * Lifecycle, stamped rather than inferred.
             *
             * `expired_at` is written by the sweep when the window closes, so "it expired" is a
             * recorded fact with a time on it rather than a comparison somebody has to redo. It
             * is also what makes the cached access status safe to refresh exactly once.
             */
            $table->timestampTz('expired_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'subject_id'], 'sheq_ovr_subject_idx');
            $table->index(['company_id', 'ends_at'], 'sheq_ovr_window_idx');
            $table->index(['company_id', 'site_id'], 'sheq_ovr_site_idx');
        });

        /*
         * One outstanding override per subject per scope.
         *
         * Two live overrides for the same contractor at the same site is not a stricter control,
         * it is two people each believing they granted the only one — and revoking either leaves
         * the gate open. Granting a second means revoking the first, which is a decision
         * somebody makes rather than one the database makes for them.
         *
         * COALESCE in the index because NULL means "everywhere"/"all work" rather than "unknown",
         * and Postgres would otherwise never collide two estate-wide overrides at all.
         */
        DB::statement(
            "CREATE UNIQUE INDEX sheq_ovr_live_uniq ON sheq_access_overrides "
            ."(company_id, subject_type, subject_id, COALESCE(site_id, 0), COALESCE(activity, '')) "
            .'WHERE revoked_at IS NULL AND expired_at IS NULL'
        );

        $this->applyTenantRls(['sheq_access_overrides']);

        $this->carryForwardExistingOverrides();

        /*
         * The old columns go.
         *
         * Left in place they would be a second answer to "is this contractor overridden", and
         * the two would disagree the first time somebody revoked one through the new screen.
         * Everything that read them has been moved to the table in the same change.
         */
        Schema::table('sheq_contractors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('access_overridden_by');
            $table->dropColumn(['access_overridden', 'access_override_reason',
                'access_override_expires']);
        });
    }

    public function down(): void
    {
        Schema::table('sheq_contractors', function (Blueprint $table) {
            $table->boolean('access_overridden')->default(false);
            $table->text('access_override_reason')->nullable();
            $table->foreignId('access_overridden_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->date('access_override_expires')->nullable();
        });

        Schema::dropIfExists('sheq_access_overrides');
    }

    /**
     * Move the overrides that are already in force into the new table.
     *
     * A live override admits people to a site today. Dropping the columns without carrying it
     * would shut a gate on somebody tomorrow morning with no warning and no record of why it
     * ever opened — which is precisely the kind of silent change a migration must not make.
     *
     * The ones that have already lapsed come across too, closed, because the history is the
     * answer to "has this contractor been overridden before" and it has never been answerable.
     */
    private function carryForwardExistingOverrides(): void
    {
        if (! Schema::hasColumn('sheq_contractors', 'access_overridden')) {
            return;
        }

        $rows = DB::table('sheq_contractors')
            ->where('access_overridden', true)->whereNull('deleted_at')
            ->get(['id', 'company_id', 'access_override_reason', 'access_overridden_by',
                'access_override_expires', 'updated_at']);

        foreach ($rows as $row) {
            /*
             * The old column was a date, and an indefinite override had none at all. CON-17 says
             * an override must expire, so one without an end gets ninety days from when it was
             * last touched — long enough not to strand anybody mid-shutdown, short enough that
             * somebody has to look at it again. The reason says so, in the record.
             */
            $started = $row->updated_at ?? now();

            $ends = $row->access_override_expires !== null
                ? \Illuminate\Support\Carbon::parse($row->access_override_expires)->endOfDay()
                : \Illuminate\Support\Carbon::parse($started)->addDays(90);

            $reason = trim((string) ($row->access_override_reason ?? ''));

            DB::table('sheq_access_overrides')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $row->company_id,
                'subject_type' => 'CONTRACTOR',
                'subject_id' => $row->id,
                'site_id' => null,
                'activity' => null,
                'reason' => $reason === ''
                    ? 'Carried forward from the previous override, which recorded no reason.'
                    : $reason,
                'conditions' => $row->access_override_expires === null
                    ? 'Carried forward with no end date; given 90 days so it expires rather than '
                        .'running for ever. Review it.'
                    : null,
                'issued_by' => $row->access_overridden_by,
                'approved_by' => $row->access_overridden_by,
                'approved_at' => $started,
                'starts_at' => $started,
                'ends_at' => $ends,
                // Already lapsed ones come across closed, so they read as history rather than
                // as a gate somebody left open.
                'expired_at' => $ends->isPast() ? $ends : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** The same row-level security every other SHEQ table carries. */
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
