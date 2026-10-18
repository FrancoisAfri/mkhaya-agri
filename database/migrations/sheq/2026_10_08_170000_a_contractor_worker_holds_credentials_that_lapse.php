<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A contractor's worker holds tickets and medicals that lapse (CON-10, CON-13).
 *
 * The competency ledger is keyed on `hr_person_id` — an employee of the host. A contractor's welder
 * has no personnel record here and never will, which is why the permit gate could only report them
 * as unverifiable: there was nowhere to put the ticket they do hold.
 *
 * So the credential lives against the worker. Deliberately a small table and not a parallel
 * competency engine: these are claims a contractor makes about their own people, evidenced by a
 * certificate, with an expiry. They are not the host's competency records and must not be mixed in
 * with them — a host's ticket is issued under the host's training governance, and conflating the
 * two would let a contractor's self-declared certificate satisfy a requirement the host assessed.
 *
 * `status` carries the same SUPPLIED / ACCEPTED / REJECTED distinction as a contractor document,
 * for the same reason: a certificate that has arrived and not been checked must not satisfy
 * anything, or the gate is reading the contractor's word for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sheq_contractor_workers')) {
            return;
        }

        if (! Schema::hasTable('sheq_contractor_worker_credentials')) {
            Schema::create('sheq_contractor_worker_credentials', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->foreignId('worker_id')
                    ->constrained('sheq_contractor_workers')->cascadeOnDelete();

                // COMPETENCY or MEDICAL. Kept open rather than enumerated in the database: a
                // tenant recording something else about a worker should not need a migration.
                $table->string('kind', 20);

                // The contractor's own code for it — "WAH", "FIRST_AID", "AUDIO". Free text,
                // because this is their vocabulary, not the host's.
                $table->string('code', 60)->nullable();
                $table->string('title', 200);

                $table->date('issued_on')->nullable();
                $table->date('expires_on')->nullable();
                $table->string('issuing_body', 200)->nullable();

                // Which activities it authorises. Null means it says nothing about activities, so
                // it cannot be the thing that authorises one.
                $table->jsonb('activities')->nullable();

                $table->string('status', 20)->default('SUPPLIED');
                $table->text('decision_reason')->nullable();

                // The certificate itself, in the module's one file store.
                $table->foreignId('file_id')->nullable()
                    ->constrained('sheq_files')->nullOnDelete();

                $table->timestamps();
                $table->softDeletes();

                $table->index(['company_id', 'worker_id', 'kind']);
                $table->index(['company_id', 'expires_on']);
            });

            DB::statement("ALTER TABLE sheq_contractor_worker_credentials ALTER COLUMN company_id
                SET DEFAULT NULLIF(current_setting('app.company_id', true), '')::bigint");
            DB::statement('ALTER TABLE sheq_contractor_worker_credentials ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE sheq_contractor_worker_credentials FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON sheq_contractor_worker_credentials USING (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
            ) WITH CHECK (
                NULLIF(current_setting('app.company_id', true), '') IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::bigint
            )");
        }

        /*
         * Which conditions block access, per tenant (CON-10).
         *
         * "Where configured" is the whole requirement: a mine blocks on an expired medical and an
         * office park does not, and a system that hard-codes either is wrong for the other. Null
         * means the shipped defaults apply, which are the rules that were already being enforced
         * before this column existed — upgrading must not change who is allowed on site.
         */
        if (Schema::hasTable('sheq_settings')
            && ! Schema::hasColumn('sheq_settings', 'contractor_blocking_rules')) {
            Schema::table('sheq_settings', function (Blueprint $table) {
                $table->jsonb('contractor_blocking_rules')->nullable()
                    ->comment('Which conditions automatically block contractor site access.');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_contractor_worker_credentials');

        if (Schema::hasColumn('sheq_settings', 'contractor_blocking_rules')) {
            Schema::table('sheq_settings', function (Blueprint $table) {
                $table->dropColumn('contractor_blocking_rules');
            });
        }
    }
};
