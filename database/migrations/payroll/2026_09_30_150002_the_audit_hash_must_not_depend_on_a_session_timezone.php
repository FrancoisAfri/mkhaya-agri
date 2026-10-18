<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A tamper alarm that fires on a row nobody touched.
 *
 * The chain hashed `created_at AT TIME ZONE 'UTC'`, and `created_at` is `timestamp without time
 * zone`. On a naked timestamp that expression does not normalise anything — it *reinterprets* the
 * value as UTC and then renders it in the session's timezone. So the hash depended on the timezone
 * of whichever connection performed the insert, and the same untouched row verified from one
 * session and failed from another.
 *
 * The construct is correct where the column is `timestamptz` — sheq_audit_log.occurred_at is, and
 * is left alone. It is wrong here, and it was proven wrong: the same row, same created_at,
 * inserted from a UTC session and an Africa/Johannesburg session, produced two different hashes.
 *
 * A false alarm on a tamper-evident trail is worse than no alarm. It sends somebody hunting a
 * breach that never happened, and the second time it cries wolf the trail stops being believed —
 * which is the whole asset.
 *
 * ── Why no rehash ────────────────────────────────────────────────────────────
 *
 * With the session on UTC the old expression and the new one render the same string, so every hash
 * written by an installation that has always run UTC — which `DB_TIMEZONE` defaults to — stays
 * valid. An installation that ran with a non-UTC DB_TIMEZONE has rows whose hashes already
 * disagree with each other; this does not create that, it stops adding to it, and the verifier
 * will now say so honestly rather than at random.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->defineHashTrigger("to_char(NEW.created_at, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')");
    }

    public function down(): void
    {
        // Deliberately restores the faulty expression: a migration that rolls back to something
        // other than what was there is not a rollback.
        $this->defineHashTrigger("to_char(NEW.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')");
    }

    private function defineHashTrigger(string $timestampExpression): void
    {
        $table = 'payroll_audit_logs';

        DB::statement("
            CREATE OR REPLACE FUNCTION {$table}_chain() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                previous CHAR(64);
                canonical TEXT;
            BEGIN
                SELECT hash INTO previous
                FROM {$table}
                WHERE company_id IS NOT DISTINCT FROM NEW.company_id
                ORDER BY id DESC
                LIMIT 1;

                NEW.prev_hash := previous;

                canonical := concat_ws('|',
                    COALESCE(previous, ''),
                    COALESCE(NEW.company_id::text, ''),
                    COALESCE({$timestampExpression}, ''),
                    COALESCE(NEW.user_id::text, ''),
                    NEW.action,
                    COALESCE(NEW.subject_type, ''),
                    COALESCE(NEW.subject_id::text, ''),
                    COALESCE(NEW.description, ''),
                    COALESCE(NEW.old_values::text, ''),
                    COALESCE(NEW.new_values::text, '')
                );

                NEW.hash := encode(digest(canonical, 'sha256'), 'hex');
                RETURN NEW;
            END;
            $$
        ");
    }
};
