<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Check-in and check-out, and the question an access log could never answer (CON-16).
 *
 * `sheq_access_events` has recorded every decision since the module shipped, and it has a
 * `direction` column documented as ENTRY, EXIT, CLOCK_IN, CLOCK_OUT. Nothing ever paired them.
 * The scanned pass wrote VERIFY for every read; the turnstile wrote whatever the hardware said
 * and never looked at it again. So the one question the log exists for — **who is on site right
 * now** — had no answer for contractor workers, and on the morning of an evacuation that is the
 * only question anybody asks. Visitors had it, through `sheq_visits.signed_in_at/signed_out_at`;
 * the people doing the dangerous work did not.
 *
 * Two changes, and the first is the smaller one.
 *
 * **The event gains what CON-16 says it must record.** It already held site, date and time,
 * result and reason, and a device reference. It did not hold the contractor — `subject_id` is
 * the worker, and "which employer had people on site when this happened" meant a join nobody
 * wrote — nor the method, nor the activity being asked about. Method matters because "scanned"
 * and "a guard wrote it down" are different grades of evidence, and an audit that cannot tell
 * them apart cannot weigh either.
 *
 * **And the pairing gets a table of its own.** `sheq_site_attendance` holds one row per person
 * per stay: in at this time, out at that time, by these two events. Derived at write time rather
 * than computed from the log on demand, because the roll-call has to be instant and correct
 * during an evacuation, and a window function over a table that grows by a million rows a year
 * is neither.
 *
 * One open stay per person, enforced by a partial unique index. A person is in one place: an
 * entry somewhere else closes the previous stay rather than leaving them apparently in two, and
 * says it did so in `exit_reason`. The alternative — allowing both — produces a roll-call that
 * over-counts, which during an evacuation sends people back into a building to look for somebody
 * who left an hour ago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_access_events', function (Blueprint $table) {
            /*
             * The employer. `subject_id` is the worker, so "which contractors had people on site
             * on the day of the incident" was a join through sheq_contractor_workers that no
             * report ever made. Nullable: an employee or a visitor has no contractor.
             */
            $table->unsignedBigInteger('contractor_id')->nullable()->after('subject_id');

            /*
             * How the decision was taken. QR, CARD, BIOMETRIC, MANUAL, API.
             *
             * An audit weighs these differently and must be able to: a scan is the system
             * recognising a credential, MANUAL is a person at a desk saying it happened. Both
             * are legitimate — a flat battery is not a reason to leave somebody unrecorded — but
             * a log that cannot tell them apart cannot weigh either.
             */
            $table->string('method', 20)->nullable()->after('direction');

            /** What they were here to do, where the reader said (CON-15). */
            $table->string('activity', 60)->nullable()->after('method');

            $table->index(['company_id', 'contractor_id', 'occurred_at'], 'sheq_ae_contractor_idx');
        });

        Schema::create('sheq_site_attendance', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('site_id')->nullable();

            /** EMPLOYEE, CONTRACTOR_WORKER or VISITOR — the same vocabulary the events use. */
            $table->string('subject_type', 30);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();

            /*
             * Copied, not joined.
             *
             * A roll-call is read while a building is being evacuated. It must not depend on
             * three tables still agreeing, and it must still name the person after they have
             * been taken off the roster — the whole point of the list is the people who are
             * unaccounted for, and those are exactly the records somebody is about to tidy up.
             */
            $table->string('person_name', 160)->nullable();
            $table->string('contractor_name', 160)->nullable();

            $table->timestampTz('entered_at');
            $table->unsignedBigInteger('entry_event_id')->nullable();
            $table->string('entry_method', 20)->nullable();
            $table->string('activity', 60)->nullable();

            $table->timestampTz('exited_at')->nullable();
            $table->unsignedBigInteger('exit_event_id')->nullable();
            $table->string('exit_method', 20)->nullable();

            /*
             * Why the stay ended. SCANNED_OUT, MANUAL, ENTERED_ELSEWHERE, NOT_SIGNED_OUT.
             *
             * The last one is the honest record of somebody who went home without scanning,
             * closed by the overnight sweep. Leaving it indistinguishable from a real exit would
             * make the next morning's roll-call look clean when it was not, which is the failure
             * this whole table exists to prevent.
             */
            $table->string('exit_reason', 24)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'site_id', 'entered_at'], 'sheq_att_site_idx');
            $table->index(['company_id', 'contractor_id', 'entered_at'], 'sheq_att_contractor_idx');
            $table->index(['company_id', 'subject_type', 'subject_id'], 'sheq_att_subject_idx');
        });

        /*
         * One open stay per person. Partial on `exited_at IS NULL`, so closed stays do not
         * collide — somebody who comes on site every day for a year has 365 rows and one of them
         * may be open.
         *
         * A plain unique index including `exited_at` would not do it: Postgres never collides
         * NULLs, so every open row would be unique against every other and the constraint would
         * enforce nothing at all.
         */
        DB::statement(
            'CREATE UNIQUE INDEX sheq_att_open_uniq ON sheq_site_attendance '
            .'(company_id, subject_type, subject_id) WHERE exited_at IS NULL'
        );

        /* The roll-call query, which runs while a building is being emptied. */
        DB::statement(
            'CREATE INDEX sheq_att_open_idx ON sheq_site_attendance '
            .'(company_id, site_id) WHERE exited_at IS NULL'
        );

        $this->applyTenantRls(['sheq_site_attendance']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_site_attendance');

        Schema::table('sheq_access_events', function (Blueprint $table) {
            $table->dropIndex('sheq_ae_contractor_idx');
            $table->dropColumn(['contractor_id', 'method', 'activity']);
        });
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
