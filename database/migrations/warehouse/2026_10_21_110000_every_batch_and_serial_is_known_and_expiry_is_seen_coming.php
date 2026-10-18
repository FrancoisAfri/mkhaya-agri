<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WMS-090 to WMS-093: batches, serial numbers, rotation and expiry.
 *
 * Lots already carry what a batch needs (origin, supplier and their lot number, production source,
 * manufacture and expiry dates), so this adds only what is missing: a serial number that can never
 * be in two places at once -- enforced by the database at commit, whatever code path moved it --
 * rotation and shelf-life rules per product, the reason an allocation broke rotation, and the
 * record of which expiry warnings have been given so each is given once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_item_profiles', function (Blueprint $t) {
            $t->string('rotation', 8)->nullable(); // FEFO|FIFO|LIFO|NONE; null = the warehouse's rule
            $t->jsonb('expiry_alert_days')->nullable(); // e.g. [90, 60, 30, 7]; null = the warehouse's
        });
        Schema::table('wms_allocations', function (Blueprint $t) {
            $t->string('override_reason', 500)->nullable();
            $t->foreignId('override_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('wms_expiry_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $t->foreignId('lot_id')->constrained('fin_stock_lots')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('fin_warehouses')->cascadeOnDelete();
            $t->unsignedSmallInteger('threshold_days');
            $t->integer('days_left');
            $t->decimal('qty', 18, 4);
            $t->decimal('at_risk_qty', 18, 4)->default(0);
            $t->decimal('exposure', 18, 2)->default(0);
            $t->timestampTz('alerted_at')->useCurrent();
            $t->unique(['lot_id', 'warehouse_id', 'threshold_days'], 'wms_expiry_alert_once');
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            DB::statement("ALTER TABLE wms_expiry_alerts ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement('ALTER TABLE wms_expiry_alerts ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE wms_expiry_alerts FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation ON wms_expiry_alerts');
            DB::statement("CREATE POLICY tenant_isolation ON wms_expiry_alerts USING ({$policy}) WITH CHECK ({$policy})");

            // A serial number is one unit: across every warehouse, bin and state its positions sum
            // to at most one. Deferred to commit, so a move (out of one place, into another) passes.
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION wms_serial_single_unit() RETURNS trigger LANGUAGE plpgsql AS $$
                DECLARE total numeric; serial text;
                BEGIN
                    IF NEW.lot_id IS NULL THEN RETURN NULL; END IF;
                    SELECT l.lot_number INTO serial FROM fin_stock_lots l WHERE l.id = NEW.lot_id AND l.kind = 'SERIAL';
                    IF serial IS NULL THEN RETURN NULL; END IF;
                    SELECT COALESCE(SUM(qty), 0) INTO total FROM fin_stock_positions WHERE lot_id = NEW.lot_id;
                    IF total > 1.0001 THEN
                        RAISE EXCEPTION 'Serial number % would be in stock % times; a serial number is one unit.', serial, total
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NULL;
                END $$;
                SQL);
            DB::unprepared('CREATE CONSTRAINT TRIGGER wms_serial_single_unit AFTER INSERT OR UPDATE OF qty, lot_id ON fin_stock_positions '
                .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION wms_serial_single_unit()');
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS wms_serial_single_unit ON fin_stock_positions');
        DB::unprepared('DROP FUNCTION IF EXISTS wms_serial_single_unit()');
        Schema::dropIfExists('wms_expiry_alerts');
        Schema::table('wms_allocations', fn (Blueprint $t) => [$t->dropConstrainedForeignId('override_by'), $t->dropColumn('override_reason')]);
        Schema::table('wms_item_profiles', fn (Blueprint $t) => $t->dropColumn(['rotation', 'expiry_alert_days']));
    }
};
