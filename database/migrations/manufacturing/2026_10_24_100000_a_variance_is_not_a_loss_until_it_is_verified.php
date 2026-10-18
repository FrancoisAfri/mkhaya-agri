<?php

use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use Database\Seeders\ManufacturingModuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-012 -- waste and loss prevention.
 *
 * A finding is what a detector saw: an actual value against a baseline, the transactions behind
 * it, and an estimate of what it cost. That is a detected variance, nothing more. It becomes a
 * verified loss only when an investigator confirms it with evidence; it is dismissed when it turns
 * out to be explained. A saving from a corrective action is recorded as realised only with the
 * evidence for it -- the estimate stays an estimate. Three money columns, never added together.
 *
 * Every step is written to mfg_loss_events, which the database will not let anybody edit or delete.
 */
return new class extends Migration
{
    private const TABLES = ['mfg_loss_rules', 'mfg_loss_findings', 'mfg_loss_events'];

    public function up(): void
    {
        Schema::create('mfg_loss_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('category', 32);
            $table->boolean('is_active')->default(true);
            $table->decimal('threshold', 12, 4);                 // meaning depends on the category: a percent, a count, weeks
            $table->unsignedInteger('min_count')->default(1);
            $table->decimal('min_impact', 18, 2)->default(0);
            $table->unsignedSmallInteger('lookback_days')->default(30);
            $table->decimal('approval_over', 18, 2)->nullable(); // verified loss at or above this needs management approval
            $table->unsignedSmallInteger('due_days')->default(14);
            $table->timestamps();

            $table->unique(['company_id', 'category'], 'mfg_loss_rule_uq');
        });

        Schema::create('mfg_loss_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('category', 32);
            $table->string('status', 16)->default('DETECTED');   // DETECTED|INVESTIGATING|VERIFIED|DISMISSED|ACTIONED|CLOSED
            $table->foreignId('factory_id')->nullable()->constrained('mfg_factories')->nullOnDelete();

            // What it is about.
            $table->string('subject_type', 20);                  // PRODUCT|ORDER|LOT|ASSET|WORK_CENTRE|SUPPLIER|STOCK_ITEM
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            // What was seen, against what.
            $table->string('measure');
            $table->string('unit', 16)->nullable();
            $table->decimal('actual_value', 18, 4);
            $table->decimal('baseline_value', 18, 4)->nullable();
            $table->string('baseline_basis');
            $table->jsonb('evidence');
            $table->decimal('estimated_impact', 18, 2)->default(0);
            $table->string('confidence', 8);                     // HIGH|MEDIUM|LOW
            $table->text('impact_basis');
            $table->text('recommended_action');

            // Who looks into it, by when.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('escalation_level')->default(0);
            $table->timestampTz('escalated_at')->nullable();

            // What was found. Each amount stands alone: detected, verified, realised.
            $table->text('investigation_notes')->nullable();
            $table->decimal('verified_loss', 18, 2)->nullable();
            $table->text('verification_evidence')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->text('dismissal_reason')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('result_after_action')->nullable();
            $table->decimal('realised_saving', 18, 2)->nullable();
            $table->text('saving_evidence')->nullable();

            $table->boolean('requires_approval')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('approval_notes')->nullable();

            $table->string('dedupe_key');
            $table->timestampTz('detected_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number'], 'mfg_loss_number_uq');
            $table->index(['company_id', 'dedupe_key'], 'mfg_loss_dedupe_idx');
            $table->index(['company_id', 'status'], 'mfg_loss_status_idx');
        });

        Schema::create('mfg_loss_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('finding_id')->constrained('mfg_loss_findings')->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->jsonb('detail')->nullable();
            $table->timestampTz('acted_at');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE mfg_loss_findings ADD CONSTRAINT mfg_loss_confidence_chk CHECK (confidence IN ('HIGH','MEDIUM','LOW'))");
            // A realised saving needs its evidence; a verified loss needs its evidence.
            DB::statement('ALTER TABLE mfg_loss_findings ADD CONSTRAINT mfg_loss_saving_evidence_chk CHECK (realised_saving IS NULL OR saving_evidence IS NOT NULL)');
            DB::statement('ALTER TABLE mfg_loss_findings ADD CONSTRAINT mfg_loss_verified_evidence_chk CHECK (verified_loss IS NULL OR verification_evidence IS NOT NULL)');

            // The audit history is history: nobody rewrites it (a company cascade still clears it).
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION mfg_loss_events_append_only() RETURNS trigger AS $$
                BEGIN
                    IF pg_trigger_depth() > 1 THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'Loss investigation history cannot be changed or deleted.';
                END;
                $$ LANGUAGE plpgsql;
                DROP TRIGGER IF EXISTS mfg_loss_events_append_only ON mfg_loss_events;
                CREATE TRIGGER mfg_loss_events_append_only BEFORE UPDATE OR DELETE ON mfg_loss_events
                    FOR EACH ROW EXECUTE FUNCTION mfg_loss_events_append_only();
                -- Nor is a finding deleted: deleting it would take its history with it. Dismiss it instead.
                DROP TRIGGER IF EXISTS mfg_loss_findings_no_delete ON mfg_loss_findings;
                CREATE TRIGGER mfg_loss_findings_no_delete BEFORE DELETE ON mfg_loss_findings
                    FOR EACH ROW EXECUTE FUNCTION mfg_loss_events_append_only();
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
        $installer = app(ManufacturingReferenceData::class);
        foreach (DB::table('mfg_number_sequences')->whereNotNull('company_id')->distinct()->pluck('company_id') as $companyId) {
            $installer->installFor((int) $companyId);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $t) {
            Schema::dropIfExists($t);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_loss_events_append_only()');
        }
    }
};
