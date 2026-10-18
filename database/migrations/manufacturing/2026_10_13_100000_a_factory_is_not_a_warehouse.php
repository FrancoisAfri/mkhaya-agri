<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MFG-001 — the manufacturing environment a company configures before it can plan anything.
 *
 * The spec is explicit that factory, warehouse, production line and work centre are separate
 * concepts, and they are modelled that way rather than collapsed into one "location" table. They
 * answer different questions: a factory is a site with a calendar and a currency, a warehouse is
 * where stock sits (and already exists as fin_warehouses), a production line is a route through a
 * factory, and a work centre is a costed, capacity-bearing place where an operation happens.
 * Conflating them is what makes capacity planning impossible later, because a warehouse has no
 * capacity and a work centre holds no stock.
 *
 * Units of measure are per company rather than a shared global library. A NULL company_id row is
 * invisible to a tenant-stamped connection unless the RLS policy is widened to allow it, and
 * widening it for a table tenants also write to means one tenant's "pack of 12" can surface in
 * another's dropdown. ManufacturingReferenceData installs the standard set per company instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Sites ─────────────────────────────────────────────────────────────────────────────
        Schema::create('mfg_factories', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('address')->nullable();

            /*
             * A factory keeps its own timezone, currency and measurement system (MFG-001.08).
             * Not inherited from the company: a group with a plant in two countries costs and
             * schedules each one locally, and a shift that starts at 06:00 starts at 06:00 there.
             */
            $table->string('timezone', 64)->default('Africa/Johannesburg');
            $table->string('currency', 3)->default('ZAR');
            $table->string('measurement_system', 16)->default('METRIC');

            /*
             * Where this factory's stock lands by default. A link to the existing finance
             * warehouse rather than a warehouse of its own — MFG-002 says reuse the item master,
             * and the same reasoning applies to the places stock is held.
             */
            $table->foreignId('default_warehouse_id')->nullable()
                ->constrained('fin_warehouses')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active'], 'mfg_fact_active_idx');
        });

        // Codes are unique inside a tenant, not globally: two companies may both call a site "JHB1".
        $this->tenantUnique('mfg_factories', ['company_id', 'code'], 'mfg_fact_code_uniq');

        // ── Departments ───────────────────────────────────────────────────────────────────────
        Schema::create('mfg_departments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories')->cascadeOnDelete();

            /*
             * Optional link to the HR unit of the same name. A production department is not an HR
             * department — "Extrusion" is a place work happens, and it may map to one HR unit, to
             * several, or to none at all on a plant that staffs from a pool. Optional so the
             * module works on a tenant that has not modelled its org chart.
             */
            $table->foreignId('company_unit_id')->nullable()
                ->constrained('company_units')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'factory_id'], 'mfg_dept_factory_idx');
        });
        $this->tenantUnique('mfg_departments', ['company_id', 'factory_id', 'code'], 'mfg_dept_code_uniq');

        // ── Production lines ──────────────────────────────────────────────────────────────────
        Schema::create('mfg_production_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'factory_id'], 'mfg_line_factory_idx');
        });
        $this->tenantUnique('mfg_production_lines', ['company_id', 'factory_id', 'code'], 'mfg_line_code_uniq');

        // ── Work centres ──────────────────────────────────────────────────────────────────────
        Schema::create('mfg_work_centres', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories')->cascadeOnDelete();
            $table->foreignId('production_line_id')->nullable()
                ->constrained('mfg_production_lines')->nullOnDelete();
            $table->foreignId('department_id')->nullable()
                ->constrained('mfg_departments')->nullOnDelete();

            $table->string('code', 32);
            $table->string('name');

            /*
             * Capacity and cost, which is what makes this a work centre rather than a label.
             * Both nullable: a newly created centre has not been rated yet, and a NULL rate is
             * honest where 0.00 would silently cost production at nothing.
             */
            $table->decimal('capacity_per_hour', 18, 4)->nullable();
            $table->foreignId('capacity_uom_id')->nullable();
            $table->decimal('cost_per_hour', 18, 4)->nullable();
            $table->unsignedInteger('setup_minutes')->default(0);
            $table->unsignedSmallInteger('concurrent_jobs')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'factory_id'], 'mfg_wc_factory_idx');
        });
        $this->tenantUnique('mfg_work_centres', ['company_id', 'factory_id', 'code'], 'mfg_wc_code_uniq');

        Schema::create('mfg_workstations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('work_centre_id')->constrained('mfg_work_centres')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'work_centre_id'], 'mfg_ws_centre_idx');
        });
        $this->tenantUnique('mfg_workstations', ['company_id', 'work_centre_id', 'code'], 'mfg_ws_code_uniq');

        /*
         * Machines are assets, not a manufacturing table. MFG-001.03 asks for work centres to be
         * associated with machines, and the asset register already holds the serial number, the
         * warranty and the maintenance history — a second machine list would immediately disagree
         * with it.
         */
        Schema::create('mfg_work_centre_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('work_centre_id')->constrained('mfg_work_centres')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['work_centre_id', 'asset_id'], 'mfg_wcm_uniq');
        });

        // ── Time: shifts, calendars, shutdowns ────────────────────────────────────────────────
        Schema::create('mfg_shifts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');

            /*
             * A night shift ends before it starts by the clock. Stored as a flag rather than
             * worked out by comparing the two times, because 22:00-06:00 and 06:00-22:00 are
             * indistinguishable to that comparison when a shift runs exactly twelve hours.
             */
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('break_minutes')->default(0);

            /** ISO-8601 weekday numbers, 1 = Monday. */
            $table->jsonb('days_of_week');

            $table->decimal('overtime_after_minutes', 8, 0)->nullable();
            $table->decimal('overtime_multiplier', 6, 3)->default(1.5);
            $table->boolean('observes_public_holidays')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'factory_id'], 'mfg_shift_factory_idx');
        });
        $this->tenantUnique('mfg_shifts', ['company_id', 'factory_id', 'code'], 'mfg_shift_code_uniq');

        Schema::create('mfg_shutdowns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->constrained('mfg_factories')->cascadeOnDelete();

            /** NULL means the whole factory stops, not "no line is affected". */
            $table->foreignId('production_line_id')->nullable()
                ->constrained('mfg_production_lines')->nullOnDelete();

            $table->string('reason');
            $table->string('kind', 24)->default('PLANNED'); // PLANNED | BREAKDOWN | STATUTORY
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'factory_id', 'starts_at'], 'mfg_shut_window_idx');
        });

        // ── Units of measure ──────────────────────────────────────────────────────────────────
        Schema::create('mfg_uoms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 16);
            $table->string('name');

            /*
             * The dimension is what makes a conversion legal. kg converts to g because both are
             * MASS; kg to litres is refused, because a conversion that depends on what the thing
             * is belongs on the item, not on the unit.
             */
            $table->string('dimension', 16); // MASS | VOLUME | LENGTH | AREA | COUNT | TIME

            /*
             * Every unit states its size relative to its dimension's base unit, so any two units
             * of one dimension convert without a conversion row existing. 1 kg = 1000 g means
             * factor_to_base = 1000 when the base is the gram.
             */
            $table->boolean('is_base')->default(false);
            $table->decimal('factor_to_base', 24, 9)->default(1);

            /** Rounding for quantities expressed in this unit — 3 for kg, 0 for units. */
            $table->unsignedSmallInteger('decimal_places')->default(3);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'dimension'], 'mfg_uom_dim_idx');
        });
        $this->tenantUnique('mfg_uoms', ['company_id', 'code'], 'mfg_uom_code_uniq');

        // Exactly one base unit per dimension per company, or "convert to base" has no answer.
        DB::statement(
            'CREATE UNIQUE INDEX mfg_uom_one_base_per_dim ON mfg_uoms (company_id, dimension) '
            .'WHERE is_base IS TRUE'
        );

        /*
         * Conversions that the dimension cannot derive: a pack of twelve, a pallet of forty bags,
         * a drum that holds 210 litres. These are properties of a particular item, so item_id is
         * required — a conversion with no item would be a second, competing answer to a question
         * factor_to_base already answers for real units.
         */
        Schema::create('mfg_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $table->foreignId('from_uom_id')->constrained('mfg_uoms')->cascadeOnDelete();
            $table->foreignId('to_uom_id')->constrained('mfg_uoms')->cascadeOnDelete();
            $table->decimal('factor', 24, 9);
            $table->timestamps();

            $table->unique(['stock_item_id', 'from_uom_id', 'to_uom_id'], 'mfg_uomc_uniq');
        });

        // ── Document numbering ────────────────────────────────────────────────────────────────
        Schema::create('mfg_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /** NULL means the sequence is company-wide rather than per factory. */
            $table->foreignId('factory_id')->nullable()->constrained('mfg_factories')->cascadeOnDelete();

            $table->string('document_type', 48); // PRODUCTION_ORDER | WORK_ORDER | JOB_CARD | ...
            $table->string('prefix', 16)->default('');
            $table->string('suffix', 16)->default('');
            $table->unsignedSmallInteger('padding')->default(5);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->string('reset_period', 12)->default('NONE'); // NONE | YEAR | MONTH
            $table->string('period_key', 12)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /*
         * One sequence per document type per scope. Postgres never collides two NULLs, so a plain
         * unique key on a nullable factory_id would happily allow two company-wide sequences for
         * the same document type and then issue the same number from both.
         */
        DB::statement(
            'CREATE UNIQUE INDEX mfg_seq_scope_uniq ON mfg_number_sequences '
            ."(company_id, document_type, COALESCE(factory_id, 0))"
        );

        // ── Policies, tolerances and escalation ───────────────────────────────────────────────
        Schema::create('mfg_factory_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            /** NULL is the company default that factories inherit when they define nothing. */
            $table->foreignId('factory_id')->nullable()->constrained('mfg_factories')->cascadeOnDelete();

            /*
             * Tolerances, as percentages. A production order finishing inside tolerance is closed
             * without comment; outside it, somebody is asked why. NULL means "no tolerance set",
             * which is deliberately different from 0 ("must be exact").
             */
            $table->decimal('scrap_tolerance_percent', 8, 4)->nullable();
            $table->decimal('yield_tolerance_percent', 8, 4)->nullable();
            $table->decimal('cost_variance_tolerance_percent', 8, 4)->nullable();

            $table->boolean('require_qc_before_putaway')->default(true);
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('allow_over_issue')->default(false);
            $table->boolean('require_bom_approval')->default(true);
            $table->unsignedSmallInteger('escalate_after_hours')->default(24);
            $table->timestamps();
        });
        DB::statement(
            'CREATE UNIQUE INDEX mfg_policy_scope_uniq ON mfg_factory_policies '
            .'(company_id, COALESCE(factory_id, 0))'
        );

        Schema::create('mfg_approval_limits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('factory_id')->nullable()->constrained('mfg_factories')->cascadeOnDelete();
            $table->string('document_type', 48);

            /*
             * A band, not a ceiling. Two rows with min 0 / max 50000 and min 50000 / max NULL say
             * who signs what; NULL max means "and everything above".
             */
            $table->decimal('min_amount', 18, 2)->default(0);
            $table->decimal('max_amount', 18, 2)->nullable();

            /** The permission the approver must hold — not a role, so tenants keep their own roles. */
            $table->string('required_permission', 128);
            $table->unsignedSmallInteger('escalate_after_hours')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'document_type'], 'mfg_limit_doc_idx');
        });

        $this->applyTenantRls([
            'mfg_factories', 'mfg_departments', 'mfg_production_lines', 'mfg_work_centres',
            'mfg_workstations', 'mfg_work_centre_machines', 'mfg_shifts', 'mfg_shutdowns',
            'mfg_uoms', 'mfg_uom_conversions', 'mfg_number_sequences', 'mfg_factory_policies',
            'mfg_approval_limits',
        ]);
    }

    public function down(): void
    {
        foreach ([
            'mfg_approval_limits', 'mfg_factory_policies', 'mfg_number_sequences',
            'mfg_uom_conversions', 'mfg_uoms', 'mfg_shutdowns', 'mfg_shifts',
            'mfg_work_centre_machines', 'mfg_workstations', 'mfg_work_centres',
            'mfg_production_lines', 'mfg_departments', 'mfg_factories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** A code is unique inside a tenant, never across the platform. */
    private function tenantUnique(string $table, array $columns, string $name): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name) {
            $blueprint->unique($columns, $name);
        });
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
