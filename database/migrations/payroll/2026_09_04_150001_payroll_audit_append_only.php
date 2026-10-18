<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the payroll audit trail impossible to edit after the fact.
 *
 * Project Management, SHEQ, Procurement Governance and Supplier Due Diligence all keep append-only,
 * hash-chained audit logs enforced by database triggers. Payroll — the module where the money
 * actually moves — kept an ordinary table. Anything holding an UPDATE grant could rewrite who
 * released a run, or DELETE the row saying a payslip was changed after approval, and nothing in the
 * data would show it had happened.
 *
 * That is the weakest link precisely where the stakes are highest. An employee disputing their pay,
 * a CCMA referral, a SARS audit and a fraud investigation all end at the same question — what
 * happened, in what order, and who did it — and an audit trail that can be quietly edited cannot
 * answer it. Worse, it looks like it can.
 *
 * ── The two mechanisms, and why both ────────────────────────────────────────
 *
 * The triggers stop UPDATE, DELETE and TRUNCATE outright, which handles anybody working through the
 * application or an ordinary SQL client.
 *
 * The hash chain handles the case the triggers cannot: somebody with rights to drop the trigger.
 * Each row's hash covers the previous row's hash, so removing or altering any row breaks every hash
 * after it, and a verification pass says exactly where. It does not prevent tampering by a database
 * owner — nothing at this layer can — it makes tampering detectable, which is what an auditor needs.
 *
 * Chained per company, so one tenant's writes cannot renumber another's chain, and so a tenant's
 * trail can be verified on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One table for both engines: BureauAuditService writes to payroll_audit_logs too, because
        // a compliance question is asked about a company without caring which engine paid it.
        $table = 'payroll_audit_logs';

        $this->addChainColumns($table);
        $this->addChainTrigger($table);
        $this->addAppendOnlyTriggers($table);
    }

    private function addChainColumns(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'hash')) {
                $blueprint->char('hash', 64)->nullable();
            }
            if (! Schema::hasColumn($table, 'prev_hash')) {
                $blueprint->char('prev_hash', 64)->nullable();
            }
        });
    }

    /**
     * Hash each row over the one before it.
     *
     * pgcrypto's digest() is used the same way SHEQ does. The canonical string is built field by
     * field with a separator rather than by casting the row, because a row cast changes shape when
     * a column is added and every historical hash would stop verifying — which reads exactly like
     * tampering.
     */
    private function addChainTrigger(string $table): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        $function = "{$table}_chain";

        DB::statement("
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS $$
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
                    COALESCE(to_char(NEW.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'), ''),
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

        DB::statement("DROP TRIGGER IF EXISTS {$table}_chain_trg ON {$table}");
        DB::statement("CREATE TRIGGER {$table}_chain_trg BEFORE INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION {$function}()");
    }

    /** UPDATE, DELETE and TRUNCATE all refused, the way every other module's trail refuses them. */
    private function addAppendOnlyTriggers(string $table): void
    {
        $function = "{$table}_append_only";

        DB::statement("
            CREATE OR REPLACE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '{$table} is append-only: % rejected', TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$
        ");

        foreach (['update', 'delete'] as $operation) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_no_{$operation} ON {$table}");
            DB::statement("
                CREATE TRIGGER {$table}_no_{$operation}
                BEFORE " . strtoupper($operation) . " ON {$table}
                FOR EACH ROW EXECUTE FUNCTION {$function}()
            ");
        }

        DB::statement("DROP TRIGGER IF EXISTS {$table}_no_truncate ON {$table}");
        DB::statement("
            CREATE TRIGGER {$table}_no_truncate
            BEFORE TRUNCATE ON {$table}
            EXECUTE FUNCTION {$function}()
        ");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $table = 'payroll_audit_logs';

        foreach (['no_update', 'no_delete', 'no_truncate', 'chain_trg'] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_{$trigger} ON {$table}");
        }

        DB::statement("DROP FUNCTION IF EXISTS {$table}_append_only()");
        DB::statement("DROP FUNCTION IF EXISTS {$table}_chain()");

        // The hashes stay. Dropping them would destroy the evidence this migration exists to
        // create, and a rollback is not a reason to lose the trail of what already happened.
    }
};
