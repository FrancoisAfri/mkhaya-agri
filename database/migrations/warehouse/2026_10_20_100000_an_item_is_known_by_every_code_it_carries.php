<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-010 to WMS-013: the product master, extended rather than duplicated.
 *
 * The SKU is Finance's `fin_stock_items` row; weight, dimensions, shelf life, storage and handling,
 * and lot/serial control are Manufacturing's `mfg_items` profile; units and conversions are
 * `mfg_uoms` / `mfg_uom_conversions`; substitutes are `mfg_item_substitutes`. What is added here
 * is only what nothing else holds: lifecycle status (enforced by the ledger), the codes an item is
 * known by, its barcodes and packaging levels, bundles, and the warehouse-only settings.
 *
 * Every movement also learns the unit it was transacted in (WMS-011): "3 cartons" stays on the
 * record next to the 36 units the balance moved by, so a later change to the carton size can never
 * rewrite what a past receipt meant.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'wms_item_profiles', 'wms_item_identifiers', 'wms_item_barcodes', 'wms_item_packaging',
        'wms_item_bundles', 'wms_item_events',
    ];

    public function up(): void
    {
        Schema::table('fin_stock_items', function (Blueprint $t) {
            $t->string('status', 16)->default('ACTIVE');
            $t->text('status_reason')->nullable();
            $t->timestampTz('status_changed_at')->nullable();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE fin_stock_items ADD CONSTRAINT fin_items_status_chk CHECK (status IN "
                ."('ACTIVE','DISCONTINUED','BLOCKED','RECALLED','QUARANTINED','OBSOLETE'))");
        }

        Schema::table('fin_stock_movements', function (Blueprint $t) {
            $t->foreignId('txn_uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();
            $t->decimal('txn_qty', 18, 4)->nullable();
            $t->string('reason_code', 32)->nullable();
            $t->timestampTz('occurred_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('approval_reference', 120)->nullable();
            $t->string('handling_unit', 64)->nullable();
        });

        Schema::create('wms_item_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('stock_item_id')->unique()->constrained('fin_stock_items')->cascadeOnDelete();
            $t->text('description')->nullable();
            $t->string('brand', 120)->nullable();
            $t->foreignId('purchase_uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();
            $t->foreignId('storage_uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();
            $t->foreignId('issue_uom_id')->nullable()->constrained('mfg_uoms')->nullOnDelete();
            $t->boolean('is_variable_weight')->default(false);
            $t->decimal('safety_stock', 18, 4)->nullable();
            $t->decimal('max_stock', 18, 4)->nullable();
            $t->decimal('reorder_qty', 18, 4)->nullable();
            $t->unsignedInteger('lead_time_days')->nullable();
            /** A receipt with less shelf life left than this is refused or sent to inspection. */
            $t->unsignedInteger('min_remaining_shelf_life_days')->nullable();
            $t->unsignedInteger('expiry_warning_days')->nullable();
            $t->decimal('temperature_min_c', 6, 2)->nullable();
            $t->decimal('temperature_max_c', 6, 2)->nullable();
            $t->string('hazard_class', 16)->nullable();
            $t->boolean('requires_inspection')->default(false);
            $t->timestampsTz();
        });

        Schema::create('wms_item_identifiers', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->string('kind', 16); // SUPPLIER|CUSTOMER|MANUFACTURER|EXTERNAL
            $t->string('code', 120);
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->string('party_name', 160)->nullable();
            $t->string('description')->nullable();
            $t->timestampsTz();
            $t->index(['company_id', 'code'], 'wms_ident_code_idx');
        });
        // One supplier calls one of its products by one code. Without a supplier the code is
        // unique within its kind.
        DB::statement('CREATE UNIQUE INDEX wms_ident_uniq ON wms_item_identifiers (company_id, kind, COALESCE(supplier_id, 0), code)');

        Schema::create('wms_item_packaging', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->string('level', 16); // EACH|INNER|CARTON|CASE|LAYER|PALLET|OTHER
            $t->string('name', 80)->nullable();
            $t->foreignId('uom_id')->constrained('mfg_uoms');
            $t->decimal('qty_in_base', 18, 4);
            $t->string('gtin', 14)->nullable();
            $t->decimal('length_cm', 10, 2)->nullable();
            $t->decimal('width_cm', 10, 2)->nullable();
            $t->decimal('height_cm', 10, 2)->nullable();
            $t->decimal('gross_weight_kg', 12, 3)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestampsTz();
            $t->unique(['stock_item_id', 'uom_id'], 'wms_pack_one_per_unit');
        });

        Schema::create('wms_item_barcodes', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->string('barcode', 64);
            $t->string('symbology', 16); // EAN13|EAN8|UPCA|GTIN14|CODE128|GS1_128|QR|DATAMATRIX|INTERNAL
            $t->string('source', 12)->default('SUPPLIER'); // SUPPLIER|CUSTOMER|MANUFACTURER|INTERNAL
            $t->foreignId('packaging_id')->nullable()->constrained('wms_item_packaging')->nullOnDelete();
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->boolean('is_primary')->default(false);
            $t->boolean('is_active')->default(true);
            /** A controlled exception: the same code knowingly registered against two items. */
            $t->text('duplicate_reason')->nullable();
            $t->foreignId('duplicate_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampsTz();
            $t->index(['company_id', 'barcode'], 'wms_barcode_lookup_idx');
        });
        // The same code twice on the same item is a typo, never an exception.
        DB::statement('CREATE UNIQUE INDEX wms_barcode_item_uniq ON wms_item_barcodes (stock_item_id, barcode)');

        Schema::create('wms_item_bundles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('bundle_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->foreignId('component_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->decimal('qty', 18, 4);
            $t->timestampsTz();
            $t->unique(['bundle_item_id', 'component_item_id'], 'wms_bundle_line_uniq');
        });

        Schema::create('wms_item_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('stock_item_id')->constrained('fin_stock_items')->cascadeOnDelete();
            $t->string('action', 24);
            $t->jsonb('changes')->nullable();
            $t->text('reason')->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['stock_item_id', 'created_at'], 'wms_item_events_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wms_item_packaging ADD CONSTRAINT wms_pack_qty_positive CHECK (qty_in_base > 0)");
            DB::statement("ALTER TABLE wms_item_bundles ADD CONSTRAINT wms_bundle_qty_positive CHECK (qty > 0)");
            DB::statement("ALTER TABLE wms_item_bundles ADD CONSTRAINT wms_bundle_not_itself CHECK (bundle_item_id <> component_item_id)");

            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach (self::TENANT_TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
            DB::unprepared('CREATE TRIGGER wms_item_events_append_only BEFORE UPDATE OR DELETE ON wms_item_events '
                .'FOR EACH ROW EXECUTE FUNCTION wms_refuse_history_edit()');

            /*
             * The ledger's own trigger already refuses edits to quantity, value, item and place.
             * What a movement was transacted as, why, and who approved it are as much a part of
             * the posted record, so they get the same protection -- in a trigger of their own,
             * leaving Finance's function exactly as Finance wrote it.
             */
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION wms_refuse_movement_meta_edit() RETURNS trigger AS $$
                BEGIN
                    IF NEW.txn_uom_id IS DISTINCT FROM OLD.txn_uom_id
                       OR NEW.txn_qty IS DISTINCT FROM OLD.txn_qty
                       OR NEW.reason_code IS DISTINCT FROM OLD.reason_code
                       OR NEW.occurred_at IS DISTINCT FROM OLD.occurred_at
                       OR NEW.approved_by IS DISTINCT FROM OLD.approved_by
                       OR NEW.approval_reference IS DISTINCT FROM OLD.approval_reference
                       OR NEW.handling_unit IS DISTINCT FROM OLD.handling_unit THEN
                        RAISE EXCEPTION 'Stock movement % is posted; reverse it instead of editing it', OLD.id;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER fin_stock_movements_meta_immutable
                    BEFORE UPDATE ON fin_stock_movements
                    FOR EACH ROW EXECUTE FUNCTION wms_refuse_movement_meta_edit();
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS wms_item_events_append_only ON wms_item_events');
            DB::unprepared('DROP TRIGGER IF EXISTS fin_stock_movements_meta_immutable ON fin_stock_movements');
            DB::unprepared('DROP FUNCTION IF EXISTS wms_refuse_movement_meta_edit()');
            DB::statement('ALTER TABLE fin_stock_items DROP CONSTRAINT IF EXISTS fin_items_status_chk');
        }
        foreach (['wms_item_events', 'wms_item_bundles', 'wms_item_barcodes', 'wms_item_packaging', 'wms_item_identifiers', 'wms_item_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('fin_stock_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('txn_uom_id');
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['txn_qty', 'reason_code', 'occurred_at', 'approval_reference', 'handling_unit']);
        });
        Schema::table('fin_stock_items', function (Blueprint $t) {
            $t->dropColumn(['status', 'status_reason', 'status_changed_at']);
        });
    }
};
