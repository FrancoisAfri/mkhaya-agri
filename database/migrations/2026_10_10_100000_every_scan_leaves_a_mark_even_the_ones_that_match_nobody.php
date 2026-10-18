<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every QR scan, recorded (CON-23).
 *
 * Scans that resolved to somebody were already recorded, as access events with method QR. The
 * ones that resolved to NOBODY were not recorded at all: `verify()` returned `['found' => false]`
 * before reaching anything that writes.
 *
 * Those are the interesting ones. A code that matches nothing is a card that was replaced after a
 * loss, a card from a different site, a photograph of somebody else's pass forwarded on WhatsApp,
 * or somebody trying codes at a boom to see what opens. Every one of those is a thing a safety
 * manager would want to know about, and none of them left a trace — so "nine unknown codes were
 * scanned at the north gate last night" was not a sentence this system could produce.
 *
 * Separate from `sheq_access_events` rather than bolted onto it, for a reason that is structural
 * rather than tidy: an access event is about a PERSON — it has a subject, it opens and closes a
 * stay on site, it feeds the roll-call used in an evacuation. A scan is about a CODE. A scan that
 * matches nobody has no subject to hang on, and inventing one would corrupt the register that an
 * evacuation depends on.
 *
 * `company_id` is nullable, which is unusual here and deliberate. A scan is checked before
 * anybody knows whose tenant it belongs to; where the reader says which site it is at, the
 * company follows from the site, and where it does not and the code matches nothing, there is
 * genuinely nobody to attribute it to. A NOT NULL column would force a guess, and the honest
 * answer is that it is unattributable.
 *
 * The token itself is never stored — only a hash. An access log holding live credentials turns a
 * database read into a working card, which is the same reasoning that keeps ID numbers out of the
 * worker register.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheq_pass_scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            /** Null where the code matched nothing and the reader did not say where it was. */
            $table->foreignId('company_id')->nullable()
                ->constrained('companies')->nullOnDelete();

            /**
             * The code, hashed.
             *
             * Hashed so the log cannot be used to open a gate, and kept at all so the same
             * unknown code appearing eleven times in one night is visibly one code rather than
             * eleven unrelated events — which is the difference between a lost card and somebody
             * standing at the boom trying things.
             */
            $table->char('token_hash', 64);

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /** Who it turned out to be, where it turned out to be anybody. */
            $table->string('subject_type', 30)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete();
            $table->string('person_name', 160)->nullable();

            /**
             * The result, in the engine's own vocabulary plus one of this table's own.
             *
             * ALLOWED, DENIED, REVIEW_REQUIRED and UNKNOWN come from the compliance decision.
             * UNKNOWN_PASS is this table's: the code resolved to nobody, so no decision was ever
             * made. Collapsing it into DENIED would be a lie a guard acts on — it sends somebody
             * to argue about their compliance when what they need is a new card.
             */
            $table->string('outcome', 20);

            /** Machine-readable reasons (COM-2), so refusals at a gate can be counted. */
            $table->jsonb('codes')->nullable();

            /** And the sentences, as shown to whoever was holding the phone. */
            $table->jsonb('reasons')->nullable();

            $table->string('activity', 60)->nullable();

            /** The device, and the signed-in user where there was one. */
            $table->string('device_ref', 120)->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();

            $table->timestampTz('scanned_at')->index();
            $table->timestamps();

            $table->index(['company_id', 'scanned_at'], 'sheq_pass_scan_company_idx');
            // Unknown codes are looked up by hash: "has this one been seen before, and how often".
            $table->index(['token_hash', 'scanned_at'], 'sheq_pass_scan_token_idx');
            $table->index(['company_id', 'outcome'], 'sheq_pass_scan_outcome_idx');
        });

        $this->applyTenantRls(['sheq_pass_scans']);

        /*
         * Append-only, like the audit log. A scan trail that can be edited answers nothing in the
         * one circumstance anybody reads it: after an incident, when somebody has a reason to
         * wish a particular scan had not happened.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sheq_pass_scan_append_only() RETURNS TRIGGER AS $$
            BEGIN
                RAISE EXCEPTION 'sheq_pass_scans is append-only (CON-23): % rejected', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER sheq_pass_scan_no_update BEFORE UPDATE ON sheq_pass_scans
                       FOR EACH ROW EXECUTE FUNCTION sheq_pass_scan_append_only()');
        DB::statement('CREATE TRIGGER sheq_pass_scan_no_delete BEFORE DELETE ON sheq_pass_scans
                       FOR EACH ROW EXECUTE FUNCTION sheq_pass_scan_append_only()');
        // TRUNCATE bypasses row triggers entirely, so it needs its own statement-level guard.
        DB::statement('CREATE TRIGGER sheq_pass_scan_no_truncate BEFORE TRUNCATE ON sheq_pass_scans
                       FOR EACH STATEMENT EXECUTE FUNCTION sheq_pass_scan_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sheq_pass_scan_no_update ON sheq_pass_scans');
        DB::statement('DROP TRIGGER IF EXISTS sheq_pass_scan_no_delete ON sheq_pass_scans');
        DB::statement('DROP TRIGGER IF EXISTS sheq_pass_scan_no_truncate ON sheq_pass_scans');
        DB::statement('DROP FUNCTION IF EXISTS sheq_pass_scan_append_only()');

        Schema::dropIfExists('sheq_pass_scans');
    }

    /**
     * The same row-level security every other SHEQ table carries.
     *
     * Note what the policy does with a NULL `company_id`: the row matches neither branch when a
     * tenant session is set, so an unattributable scan is invisible to every tenant and visible
     * only to an unstamped connection. That is the correct reading — a scan nobody can attribute
     * belongs to the operator, not to a customer who might merely have been nearby.
     */
    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
