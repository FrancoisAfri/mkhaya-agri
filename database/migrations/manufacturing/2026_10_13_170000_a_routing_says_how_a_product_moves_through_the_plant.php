<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-003 -- routing and production operations.
 *
 * A BOM says what a product is made of; a routing says how it is made: which operations, in what
 * order, at which work centres, taking how long, checked where. Versioned and approved exactly like
 * a BOM, for the same reason -- a production order released against a routing must keep the
 * routing it was planned and costed with -- so an approved routing is immutable by trigger, and a
 * change is a new revision.
 *
 * Order is by `sequence`. Operations that share a `parallel_group` run at the same time; everything
 * with a lower sequence outside the group must be finished first. That one rule is what the routing
 * engine enforces on the shop floor: "the next mandatory operation cannot be completed until
 * required preceding operations and quality gates have passed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfg_routings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('mfg_items')->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->string('name')->nullable();
            $table->string('status', 16)->default('DRAFT'); // DRAFT|APPROVED|SUPERSEDED|OBSOLETE
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();

            /*
             * Batch limits. A mixer that takes 500 kg cannot run 501, and one that needs 50 kg to
             * cover its blades cannot run 40. NULL is "no limit", not zero.
             */
            $table->decimal('min_batch_size', 18, 6)->nullable();
            $table->decimal('max_batch_size', 18, 6)->nullable();

            /** Good output as a share of what is started, and how far below it is still acceptable. */
            $table->decimal('standard_yield_percent', 8, 4)->default(100);
            $table->decimal('yield_tolerance_percent', 8, 4)->nullable();

            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('superseded_by_id')->nullable()->constrained('mfg_routings')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'item_id', 'status'], 'mfg_rt_status_idx');
        });
        DB::statement('CREATE UNIQUE INDEX mfg_rt_rev_uniq ON mfg_routings (company_id, item_id, revision)');
        DB::statement(
            'CREATE UNIQUE INDEX mfg_rt_one_default ON mfg_routings (company_id, item_id) '
            ."WHERE is_default IS TRUE AND status = 'APPROVED' AND deleted_at IS NULL"
        );

        Schema::create('mfg_routing_operations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('routing_id')->constrained('mfg_routings')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('code', 32)->nullable();
            $table->string('name');
            $table->text('instructions')->nullable();

            /*
             * Where it happens: a specific work centre, or a kind of machine any centre that has one
             * may run. One or the other -- unless the operation is subcontracted, when it happens
             * at the subcontractor.
             */
            $table->foreignId('work_centre_id')->nullable()->constrained('mfg_work_centres')->nullOnDelete();
            $table->string('machine_type', 64)->nullable();

            // Time. Setup is paid once per batch; run time per unit or per batch, as the process works.
            $table->decimal('setup_minutes', 12, 2)->default(0);
            $table->decimal('run_minutes', 12, 4)->default(0);
            $table->string('run_basis', 12)->default('PER_UNIT'); // PER_UNIT|PER_BATCH
            $table->decimal('move_minutes', 12, 2)->default(0);   // queue and move time to the next operation

            // Labour: how many people, for how much of the operation's time.
            $table->decimal('labour_headcount', 8, 2)->default(1);
            $table->decimal('labour_percent_of_time', 8, 4)->default(100);

            /** Good output expected from this operation as a share of its input. */
            $table->decimal('expected_yield_percent', 8, 4)->default(100);

            /** Operations sharing a group run concurrently. NULL: strictly after everything before it. */
            $table->string('parallel_group', 16)->nullable();

            // Quality and control.
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_inspection_point')->default(false);
            $table->string('inspection_plan_reference')->nullable();
            /** A gate stops the order until somebody holding the permission signs it off. */
            $table->boolean('is_approval_gate')->default(false);
            $table->string('gate_permission', 128)->nullable();

            // Subcontracted operations happen at a supplier, take calendar days, and cost a price.
            $table->boolean('is_subcontracted')->default(false);
            $table->unsignedBigInteger('subcontractor_supplier_id')->nullable();
            $table->unsignedSmallInteger('subcontract_lead_days')->nullable();
            $table->decimal('subcontract_cost_per_unit', 18, 4)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'routing_id'], 'mfg_rto_routing_idx');
            $table->index(['company_id', 'work_centre_id'], 'mfg_rto_wc_idx');
        });
        DB::statement('CREATE UNIQUE INDEX mfg_rto_seq_uniq ON mfg_routing_operations (routing_id, sequence)');

        /** Permitted alternative work centres, ranked, optionally with their own run time. */
        Schema::create('mfg_routing_operation_alternates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('operation_id')->constrained('mfg_routing_operations')->cascadeOnDelete();
            $table->foreignId('work_centre_id')->constrained('mfg_work_centres')->cascadeOnDelete();
            $table->unsignedSmallInteger('priority')->default(1);
            $table->decimal('run_minutes', 12, 4)->nullable();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX mfg_rtoa_uniq ON mfg_routing_operation_alternates (operation_id, work_centre_id)');

        Schema::create('mfg_routing_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('routing_id')->constrained('mfg_routings')->cascadeOnDelete();
            $table->string('action', 16);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('acted_at');
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        $this->applyTenantRls(['mfg_routings', 'mfg_routing_operations', 'mfg_routing_operation_alternates', 'mfg_routing_approvals']);
        $this->guardApprovedRoutings();
    }

    /** As for BOMs: an approved routing is a record, and the database refuses to rewrite it. */
    private function guardApprovedRoutings(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mfg_refuse_approved_routing_op() RETURNS trigger AS $$
            DECLARE parent_status text; parent_id bigint;
            BEGIN
                parent_id := COALESCE(NEW.routing_id, OLD.routing_id);
                SELECT status INTO parent_status FROM mfg_routings WHERE id = parent_id;
                IF parent_status = 'APPROVED' THEN
                    RAISE EXCEPTION 'Routing % is approved; create a new revision instead of changing its operations', parent_id;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END; $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION mfg_refuse_approved_routing_alt() RETURNS trigger AS $$
            DECLARE parent_status text; op_id bigint;
            BEGIN
                op_id := COALESCE(NEW.operation_id, OLD.operation_id);
                SELECT r.status INTO parent_status FROM mfg_routing_operations o JOIN mfg_routings r ON r.id = o.routing_id WHERE o.id = op_id;
                IF parent_status = 'APPROVED' THEN
                    RAISE EXCEPTION 'The routing behind operation % is approved; create a new revision instead', op_id;
                END IF;
                RETURN COALESCE(NEW, OLD);
            END; $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION mfg_refuse_approved_routing_edit() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'APPROVED' AND (
                       NEW.item_id IS DISTINCT FROM OLD.item_id
                    OR NEW.revision IS DISTINCT FROM OLD.revision
                    OR NEW.min_batch_size IS DISTINCT FROM OLD.min_batch_size
                    OR NEW.max_batch_size IS DISTINCT FROM OLD.max_batch_size
                    OR NEW.standard_yield_percent IS DISTINCT FROM OLD.standard_yield_percent
                    OR NEW.yield_tolerance_percent IS DISTINCT FROM OLD.yield_tolerance_percent
                    OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                ) THEN
                    RAISE EXCEPTION 'Routing % is approved; it cannot be edited, only superseded', OLD.id;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql;

            CREATE TRIGGER mfg_routing_ops_immutable BEFORE INSERT OR UPDATE OR DELETE ON mfg_routing_operations
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_approved_routing_op();
            CREATE TRIGGER mfg_routing_alts_immutable BEFORE INSERT OR UPDATE OR DELETE ON mfg_routing_operation_alternates
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_approved_routing_alt();
            CREATE TRIGGER mfg_routings_immutable BEFORE UPDATE ON mfg_routings
                FOR EACH ROW EXECUTE FUNCTION mfg_refuse_approved_routing_edit();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_routing_ops_immutable ON mfg_routing_operations');
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_routing_alts_immutable ON mfg_routing_operation_alternates');
            DB::unprepared('DROP TRIGGER IF EXISTS mfg_routings_immutable ON mfg_routings');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_approved_routing_op()');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_approved_routing_alt()');
            DB::unprepared('DROP FUNCTION IF EXISTS mfg_refuse_approved_routing_edit()');
        }

        foreach (['mfg_routing_approvals', 'mfg_routing_operation_alternates', 'mfg_routing_operations', 'mfg_routings'] as $t) {
            Schema::dropIfExists($t);
        }
    }

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
