<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-002/003/004: the warehouse and its locations, built on Finance's stock tables rather than
 * beside them.
 *
 * A WHMI warehouse IS a `fin_warehouses` row and a WHMI location IS a `fin_stock_bins` row. The
 * stock ledger (MFG-008) already records which bin every position sits in; a second location table
 * would give every unit of stock two addresses that drift apart within a month.
 *
 * The hierarchy Zone → Aisle → Rack → Shelf → Bin is one self-referencing table. Only a BIN holds
 * stock: the levels above it are structure, and putting stock "in aisle 3" is the kind of address
 * a picker cannot walk to. Docks, staging lanes, quarantine cages and the in-transit location are
 * bins of a different kind, not a different table.
 *
 * Warehouses are separate records (WHMI decision 4). One may optionally be linked to a SHEQ site
 * and, while `sync_from_site` is on, takes its address and timezone from it — the link is a
 * convenience for a company that already maintains its sites there, never a dependency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fin_warehouses', function (Blueprint $t) {
            $t->string('warehouse_type', 24)->default('GENERAL');
            $t->string('timezone', 64)->default('Africa/Johannesburg');
            $t->foreignId('work_calendar_id')->nullable()->constrained('attendance_work_calendars')->nullOnDelete();
            $t->foreignId('manager_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('sheq_site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $t->boolean('sync_from_site')->default(false);
            $t->timestampTz('site_synced_at')->nullable();
            $t->string('capacity_basis', 12)->nullable(); // UNITS|WEIGHT|VOLUME|PALLETS
            $t->decimal('capacity_value', 18, 4)->nullable();
            $t->time('opening_time')->nullable();
            $t->time('closing_time')->nullable();
            $t->time('dispatch_cutoff_time')->nullable();
            $t->jsonb('permitted_categories')->nullable();
            $t->jsonb('policies')->nullable();
            /*
             * The switch behind WHMI decision 3. While false the warehouse behaves exactly as it
             * did before WHMI existed — an invoice relieves its stock. Once true, stock leaves at
             * dispatch, so an invoice must not relieve it a second time.
             */
            $t->boolean('is_wms_managed')->default(false);
        });

        Schema::table('fin_stock_bins', function (Blueprint $t) {
            $t->foreignId('parent_id')->nullable()->constrained('fin_stock_bins')->cascadeOnDelete();
            $t->string('level', 8)->default('BIN'); // ZONE|AISLE|RACK|SHELF|BIN
            $t->string('barcode', 64)->nullable();
            $t->string('status', 12)->default('OPEN'); // OPEN|BLOCKED|RESTRICTED
            $t->text('status_reason')->nullable();
            $t->timestampTz('restricted_until')->nullable();
            $t->decimal('max_units', 18, 4)->nullable();
            $t->decimal('max_weight_kg', 12, 3)->nullable();
            $t->decimal('max_volume_m3', 12, 4)->nullable();
            $t->unsignedInteger('max_pallets')->nullable();
            $t->decimal('length_cm', 10, 2)->nullable();
            $t->decimal('width_cm', 10, 2)->nullable();
            $t->decimal('height_cm', 10, 2)->nullable();
            $t->boolean('requires_temperature_monitoring')->default(false);
            $t->decimal('temperature_min_c', 6, 2)->nullable();
            $t->decimal('temperature_max_c', 6, 2)->nullable();
            $t->boolean('hazmat_allowed')->default(false);
            $t->text('handling_notes')->nullable();
            $t->unsignedInteger('pick_sequence')->nullable();
        });

        // A scanned label must resolve to exactly one location in the company.
        DB::statement('CREATE UNIQUE INDEX fin_bins_barcode_uniq ON fin_stock_bins (company_id, barcode) WHERE barcode IS NOT NULL');
        DB::statement('CREATE INDEX fin_bins_parent_idx ON fin_stock_bins (parent_id)');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fin_stock_bins ADD CONSTRAINT fin_bins_level_chk CHECK (level IN ('ZONE','AISLE','RACK','SHELF','BIN'))");
            DB::statement("ALTER TABLE fin_stock_bins ADD CONSTRAINT fin_bins_status_chk CHECK (status IN ('OPEN','BLOCKED','RESTRICTED'))");
        }

        /*
         * WMS-004 "record all changes to location configuration". Append-only: a history that can
         * be edited is a history nobody can rely on when the question is who unblocked the bin the
         * damaged pallet went into.
         */
        Schema::create('wms_location_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $t->foreignId('bin_id')->nullable()->constrained('fin_stock_bins')->nullOnDelete();
            $t->string('action', 16); // CREATED|UPDATED|BLOCKED|RESTRICTED|REOPENED|DEACTIVATED|SYNCED
            $t->jsonb('changes')->nullable(); // {field: [before, after]}
            $t->text('reason')->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['company_id', 'warehouse_id', 'created_at'], 'wms_loc_changes_wh_idx');
            $t->index(['bin_id', 'created_at'], 'wms_loc_changes_bin_idx');
        });

        /*
         * WMS-003 "assigned staff, shifts and permitted activities". Who works in which warehouse,
         * on which shift, doing what. Shifts are Time & Attendance's; this only points at one.
         */
        Schema::create('wms_warehouse_staff', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('shift_id')->nullable()->constrained('attendance_shifts')->nullOnDelete();
            $t->jsonb('activities'); // RECEIVE|PUTAWAY|PICK|PACK|DISPATCH|COUNT|REPLENISH|RETURNS|SUPERVISE
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->unique(['warehouse_id', 'user_id'], 'wms_staff_one_row_per_person');
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (['wms_location_changes', 'wms_warehouse_staff'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }

            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION wms_refuse_history_edit() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Warehouse history is append-only: % on % is refused.', TG_OP, TG_TABLE_NAME;
                END;
                $$ LANGUAGE plpgsql;
            SQL);
            DB::unprepared('CREATE TRIGGER wms_location_changes_append_only BEFORE UPDATE OR DELETE ON wms_location_changes '
                .'FOR EACH ROW EXECUTE FUNCTION wms_refuse_history_edit()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS wms_location_changes_append_only ON wms_location_changes');
        }
        Schema::dropIfExists('wms_warehouse_staff');
        Schema::dropIfExists('wms_location_changes');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS wms_refuse_history_edit()');
            DB::statement('ALTER TABLE fin_stock_bins DROP CONSTRAINT IF EXISTS fin_bins_level_chk');
            DB::statement('ALTER TABLE fin_stock_bins DROP CONSTRAINT IF EXISTS fin_bins_status_chk');
        }
        DB::statement('DROP INDEX IF EXISTS fin_bins_barcode_uniq');
        DB::statement('DROP INDEX IF EXISTS fin_bins_parent_idx');

        Schema::table('fin_stock_bins', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_id');
            $t->dropColumn(['level', 'barcode', 'status', 'status_reason', 'restricted_until', 'max_units',
                'max_weight_kg', 'max_volume_m3', 'max_pallets', 'length_cm', 'width_cm', 'height_cm',
                'requires_temperature_monitoring', 'temperature_min_c', 'temperature_max_c',
                'hazmat_allowed', 'handling_notes', 'pick_sequence']);
        });
        Schema::table('fin_warehouses', function (Blueprint $t) {
            foreach (['work_calendar_id', 'manager_user_id', 'sheq_site_id'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['warehouse_type', 'timezone', 'sync_from_site', 'site_synced_at', 'capacity_basis',
                'capacity_value', 'opening_time', 'closing_time', 'dispatch_cutoff_time',
                'permitted_categories', 'policies', 'is_wms_managed']);
        });
    }
};
