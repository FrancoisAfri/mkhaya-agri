<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POPIA data protection for SHEQ (§9).
 *
 * Two things, and the second is the one that actually needs thought.
 *
 * **Special personal information.** `sheq_incident_people` holds `injury_nature`, `body_part`,
 * `statement` and `external_contact`. Injury details about an identifiable person are health
 * information — POPIA s26 special personal information, the category with the highest bar for
 * processing and the one a regulator looks at first. It was sitting in plaintext, readable by
 * anyone with a database connection: a support engineer running a query, a restored backup on a
 * laptop, a read replica pointed at a BI tool. The columns widen here because encrypted values are
 * several times longer than what they replace.
 *
 * These are safe to encrypt because they are not aggregated anywhere and are not carried by the
 * offline sync merge — child tables are excluded from it. That second point mattered: Laravel's
 * encryption is randomised, so an encrypted field would compare unequal on every single sync and
 * the merger would report a conflict on every health field of every record, forever.
 *
 * **Data subject requests.** POPIA gives a person the right to see what you hold (s23) and to ask
 * for deletion (s24). SHEQ is the module where the second right collides with another law: the Mine
 * Health and Safety Act requires exposure and medical records to be kept for forty years, precisely
 * because occupational lung disease presents decades after the exposure that caused it. An employer
 * who honours an erasure request over that retention duty commits an offence, and destroys the
 * evidence a future claimant would need to prove their own case.
 *
 * So the table records the request, the decision, and — when refused — the specific statutory
 * instrument that compels retention. "We cannot delete this" is not an acceptable answer to a data
 * subject; "s13 of the Mine Health and Safety Act requires this record until 2066" is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheq_incident_people', function (Blueprint $table) {
            $table->text('injury_nature')->nullable()->change();
            $table->text('body_part')->nullable()->change();
            $table->text('external_contact')->nullable()->change();
        });

        Schema::create('sheq_data_subject_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            /** ACCESS (s23), ERASURE (s24), PORTABILITY, OBJECTION. */
            $table->string('request_type', 20)->index();

            // Who is asking. An external claimant may have no user account at all, which is why
            // the identifying details are free text rather than a foreign key alone.
            $table->unsignedBigInteger('hr_person_id')->nullable()->index();
            $table->string('subject_name', 255);
            $table->string('subject_identifier', 255)->nullable();
            $table->string('subject_email', 255)->nullable();

            $table->string('status', 20)->default('RECEIVED')->index();

            /**
             * POPIA gives 30 days. Stamped at receipt rather than computed on read, so a change to
             * the statutory period later does not silently rewrite the deadline every open request
             * was accepted under.
             */
            $table->timestamp('received_at');
            $table->timestamp('due_at');
            $table->timestamp('responded_at')->nullable();

            /** What was found, and what was done about it. */
            $table->jsonb('findings')->nullable();
            $table->jsonb('refusals')->nullable();
            $table->unsignedInteger('records_found')->default(0);
            $table->unsignedInteger('records_erased')->default(0);
            $table->unsignedInteger('records_retained')->default(0);

            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('handled_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status', 'due_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            $table = 'sheq_data_subject_requests';

            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_data_subject_requests');

        Schema::table('sheq_incident_people', function (Blueprint $table) {
            $table->string('injury_nature', 255)->nullable()->change();
            $table->string('body_part', 255)->nullable()->change();
            $table->string('external_contact', 255)->nullable()->change();
        });
    }
};
