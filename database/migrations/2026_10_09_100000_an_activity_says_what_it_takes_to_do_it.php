<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A catalogue of the work people come on site to do (CON-15).
 *
 * Access has to answer contractor + worker + site + activity + time, and until now the activity
 * half could not be answered at all. Two things were missing, and the second is the one that
 * matters:
 *
 * **There was no vocabulary.** Activities were free-form uppercase strings compared with
 * `in_array`, so WORKING_AT_HEIGHT, WORK_AT_HEIGHT and HEIGHTS were three different activities.
 * A scanner sending one spelling and a certificate recorded under another silently failed to
 * match, and the failure looked like a pass.
 *
 * **Nothing said what an activity requires.** The relationship ran one way only: a credential
 * listed the activities it authorises. Ask "may this person work at height" and the answer came
 * from the certificates on file — so a worker with NO rigging ticket at all was eligible, because
 * no certificate said otherwise. `AccessPassService::activityEligible()` returned `ok: true` with
 * the note "No competency is recorded specifically for WORKING_AT_HEIGHT", which is exactly
 * backwards: the absence of the ticket is the refusal.
 *
 * So an activity now declares its own requirements, and the check runs from the activity down to
 * the worker rather than from whatever happens to be on file upwards.
 *
 * `required_credentials` holds credential CODES, matched against
 * `sheq_contractor_worker_credentials.code`. Codes rather than ids because a contractor's
 * credentials are supplied by the contractor and a host's activity catalogue is written before
 * any of them exist — an id would mean the catalogue could only name credentials somebody had
 * already filed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /** The code a scanner, a permit or an API sends. Upper case by convention. */
            $table->string('code', 60);
            $table->string('name', 160);
            $table->text('description')->nullable();

            /**
             * Credential codes somebody must hold to do this work, as a JSON list.
             *
             * Empty means the activity needs no particular ticket, which is the honest answer for
             * most work: sweeping a yard requires nothing, and an activity that demanded a
             * credential nobody issues would refuse everybody.
             */
            $table->jsonb('required_credentials')->nullable();

            /*
             * High risk in the Construction Regulations sense — work at height, confined space,
             * hot work, lifting. Carried so a screen can say which activities deserve a second
             * look and a permit, not because the gate treats them differently: the gate treats
             * what the activity REQUIRES, which is the honest mechanism.
             */
            $table->boolean('is_high_risk')->default(false);

            /** Whether a permit to work has to be open before this activity may start. */
            $table->boolean('requires_permit')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active'], 'sheq_activity_active_idx');
        });

        /*
         * One live activity per code per tenant. Partial, so a retired activity does not hold its
         * code hostage: a host who deletes HOT_WORK and later recreates it would otherwise
         * collide with the deleted row for ever, and a plain unique index over a nullable
         * `deleted_at` would not help because Postgres never collides NULLs.
         */
        DB::statement(
            'CREATE UNIQUE INDEX sheq_activity_code_uniq ON sheq_activities '
            .'(company_id, code) WHERE deleted_at IS NULL'
        );

        $this->applyTenantRls(['sheq_activities']);
    }

    public function down(): void
    {
        Schema::dropIfExists('sheq_activities');
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
