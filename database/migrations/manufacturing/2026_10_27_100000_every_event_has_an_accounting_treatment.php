<?php

use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-021 -- financial integration and accounting controls.
 *
 * mfg_accounting_rules says, per company, what each manufacturing event does to the books: posts a
 * journal (and between which accounts), is kept in the manufacturing cost ledger only (memo), or
 * has no accounting effect at all. A rule is approved before manufacturing relies on it; a
 * suspended rule stops the event. The default for each event describes what this installation's
 * stock ledger actually does -- not every stock movement is a journal: a warehouse transfer moves
 * value between places, not accounts, and labour is already expensed by payroll.
 *
 * mfg_cost_records is the manufacturing cost subledger: one immutable row per accounting-relevant
 * event, carrying its source document (stock movement, order, journal). Corrections are new rows
 * that point at what they reverse. mfg_accounting_exceptions is the queue reconciliation fills.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_accounting_rules', 'mfg_cost_records', 'mfg_accounting_exceptions'];

    public function up(): void
    {
        Schema::create('mfg_accounting_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('treatment', 8);                   // POST|MEMO|NONE
            $table->string('debit_account_code', 20)->nullable();
            $table->string('credit_account_code', 20)->nullable();
            $table->text('description');
            $table->string('status', 12)->default('DRAFT');   // DRAFT|APPROVED|SUSPENDED
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'event_type'], 'mfg_accounting_rule_uq');
        });

        Schema::create('mfg_cost_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('production_order_id')->nullable()->constrained('mfg_production_orders')->nullOnDelete();
            $table->string('event_type', 32);
            $table->string('treatment', 8);
            $table->decimal('qty', 18, 6)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('basis')->nullable();
            $table->foreignId('stock_movement_id')->nullable()->constrained('fin_stock_movements')->nullOnDelete();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('source_type', 32)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('mfg_cost_records')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('booked_on');
            $table->string('idempotency_key');
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'mfg_cost_record_key_uq');
            $table->index(['company_id', 'production_order_id'], 'mfg_cost_record_order_idx');
            $table->index(['company_id', 'event_type', 'booked_on'], 'mfg_cost_record_type_idx');
        });

        Schema::create('mfg_accounting_exceptions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('severity', 8);                    // HIGH|MEDIUM|LOW
            $table->foreignId('production_order_id')->nullable()->constrained('mfg_production_orders')->nullOnDelete();
            $table->string('reference')->nullable();
            $table->text('message');
            $table->decimal('amount', 18, 2)->nullable();
            $table->jsonb('detail')->nullable();
            $table->string('status', 10)->default('OPEN');    // OPEN|RESOLVED|ACCEPTED
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->string('dedupe_key');
            $table->timestampTz('last_seen_at');
            $table->timestamps();

            $table->index(['company_id', 'status'], 'mfg_acct_exc_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE mfg_accounting_rules ADD CONSTRAINT mfg_accounting_rule_treatment_chk CHECK (treatment IN ('POST','MEMO','NONE'))");
            DB::statement("ALTER TABLE mfg_accounting_rules ADD CONSTRAINT mfg_accounting_rule_accounts_chk CHECK (treatment <> 'POST' OR (debit_account_code IS NOT NULL AND credit_account_code IS NOT NULL))");
            // One open exception per problem.
            DB::statement("CREATE UNIQUE INDEX mfg_acct_exc_open_uq ON mfg_accounting_exceptions (company_id, dedupe_key) WHERE status = 'OPEN'");

            // The subledger is a record: corrections are new rows, never edits.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION mfg_cost_records_immutable() RETURNS trigger AS $$
                BEGIN
                    IF pg_trigger_depth() > 1 THEN
                        RETURN COALESCE(NEW, OLD);
                    END IF;
                    RAISE EXCEPTION 'Manufacturing cost records cannot be changed or deleted; record a reversal instead.';
                END;
                $$ LANGUAGE plpgsql;
                DROP TRIGGER IF EXISTS mfg_cost_records_immutable ON mfg_cost_records;
                CREATE TRIGGER mfg_cost_records_immutable BEFORE UPDATE OR DELETE ON mfg_cost_records
                    FOR EACH ROW EXECUTE FUNCTION mfg_cost_records_immutable();
            SQL);

            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TABLES as $t) {
                DB::statement("ALTER TABLE {$t} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$t}");
                DB::statement("CREATE POLICY tenant_isolation ON {$t} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }

        (new ManufacturingModuleSeeder)->run();
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_cost_records_immutable()');
        }
    }
};
