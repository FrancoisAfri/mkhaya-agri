<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §15.2: audit records cannot be altered or erased through the application.
 *
 * The database refuses an UPDATE of an activity_log row, except the one maintenance change the
 * platform makes (filling in a missing company_id), and refuses a DELETE unless the session has
 * explicitly declared a retention purge (SET LOCAL app.audit_purge = 'on'), which only the
 * scheduled retention job does. A user, a bug or a compromised screen cannot rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION protect_activity_log() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF coalesce(current_setting('app.audit_purge', true), '') = 'on' THEN
            RETURN OLD;
        END IF;
        RAISE EXCEPTION 'Audit records cannot be deleted (activity_log %).', OLD.id USING ERRCODE = '42501';
    END IF;
    -- UPDATE: only filling in a missing company_id is allowed.
    IF OLD.company_id IS NULL AND NEW.company_id IS NOT NULL
       AND (to_jsonb(NEW) - 'company_id' - 'updated_at') = (to_jsonb(OLD) - 'company_id' - 'updated_at') THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'Audit records cannot be changed (activity_log %).', OLD.id USING ERRCODE = '42501';
END;
$$;
DROP TRIGGER IF EXISTS activity_log_protect ON activity_log;
CREATE TRIGGER activity_log_protect BEFORE UPDATE OR DELETE ON activity_log FOR EACH ROW EXECUTE FUNCTION protect_activity_log();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_protect ON activity_log; DROP FUNCTION IF EXISTS protect_activity_log();');
    }
};
