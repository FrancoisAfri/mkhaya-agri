<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §8: post-harvest. Harvest intake in full (§8.1); produce lots and their genealogy through
 * grading, packing and processing (§8.2); storage conditions and readings with corrective actions
 * (§8.3); recalls (§8.4); customer orders, dispatches and deliveries (§8.5).
 *
 * A produce lot is what the farm traces. Where the produce sits in a store, its quantities also move
 * in the shared stock ledger; the lot carries the stock item and store so the two agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('harvest_records', function (Blueprint $t) {
            $t->unsignedBigInteger('crew_id')->nullable();
            $t->string('measuring_method', 20)->nullable();                // weighbridge | scale | count | volume | estimate
            $t->string('inspection_outcome', 12)->nullable();              // passed | conditional | failed
            $t->string('inspection_note', 300)->nullable();
            $t->unsignedBigInteger('inspected_by')->nullable();
            $t->string('vehicle_ref', 60)->nullable();
            $t->string('container_ref', 120)->nullable();                  // crate, bin or trailer numbers
            $t->unsignedBigInteger('store_id')->nullable();                // farm store it went into
            $t->string('storage_location', 80)->nullable();                // bin, bay, pallet position
        });

        Schema::table('farm_stores', function (Blueprint $t) {
            $t->decimal('temperature_min', 6, 2)->nullable();
            $t->decimal('temperature_max', 6, 2)->nullable();
            $t->decimal('humidity_min', 5, 2)->nullable();
            $t->decimal('humidity_max', 5, 2)->nullable();
            $t->string('sensor_ref', 80)->nullable();
        });

        Schema::create('grading_standards', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->string('name', 120);
            $t->json('grades');                                             // [{code, name, criteria}]
            $t->json('checks')->nullable();                                 // [{check, unit, min, max}] — the quality checks an operation records
            $t->unsignedSmallInteger('version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('produce_lots', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('code', 60);
            $t->string('kind', 12);                                         // harvest | graded | packed | processed | returned
            $t->string('product', 120);
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('harvest_record_id')->nullable();
            $t->string('grade', 40)->nullable();
            $t->decimal('quantity', 14, 3);
            $t->decimal('remaining', 14, 3);
            $t->string('unit', 12);
            $t->unsignedBigInteger('stock_item_id')->nullable();
            $t->unsignedBigInteger('store_id')->nullable();
            $t->unsignedBigInteger('warehouse_id')->nullable();
            $t->string('location', 80)->nullable();                         // bin, rack, pallet position
            $t->string('pallet_ref', 60)->nullable();
            $t->decimal('unit_cost', 14, 4)->nullable();
            $t->string('status', 12)->default('available');                 // available | quarantined | recalled | consumed | dispatched | written_off
            $t->date('produced_on');
            $t->date('best_before')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
            $t->index(['farm_id', 'status']);
        });

        Schema::create('packhouse_operations', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('reference', 30);
            $t->string('kind', 12);                                         // grading | sorting | packing | repacking | processing
            $t->unsignedBigInteger('standard_id')->nullable();
            $t->date('performed_on');
            $t->decimal('input_quantity', 14, 3);
            $t->decimal('output_quantity', 14, 3);
            $t->decimal('loss_quantity', 14, 3)->default(0);
            $t->string('loss_reason', 200)->nullable();
            $t->json('packaging')->nullable();                              // [{stock_item_id, item, qty, movement_id, value}]
            $t->unsignedBigInteger('crew_id')->nullable();
            $t->unsignedSmallInteger('workers')->nullable();
            $t->unsignedInteger('labour_minutes')->nullable();
            $t->decimal('labour_cost', 14, 2)->nullable();
            $t->unsignedBigInteger('machine_id')->nullable();
            $t->decimal('machine_hours', 8, 2)->nullable();
            $t->json('quality_checks')->nullable();                         // [{check, value, ok, note}]
            $t->boolean('quality_passed')->default(true);
            $t->decimal('input_value', 14, 2)->default(0);
            $t->decimal('output_value', 14, 2)->default(0);
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('produce_lot_links', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('parent_lot_id')->index();
            $t->unsignedBigInteger('child_lot_id')->index();
            $t->unsignedBigInteger('operation_id')->nullable();
            $t->decimal('quantity', 14, 3);                                  // of the parent that went in
            $t->timestamps();
        });

        Schema::create('storage_readings', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('store_id');
            $t->timestamp('recorded_at');
            $t->decimal('temperature', 6, 2)->nullable();
            $t->decimal('humidity', 5, 2)->nullable();
            $t->json('other')->nullable();                                  // CO2, ethylene, … as the sensor sends them
            $t->string('source', 10)->default('manual');                    // manual | sensor
            $t->boolean('out_of_range')->default(false);
            $t->string('deviation', 200)->nullable();
            $t->string('corrective_action', 500)->nullable();
            $t->unsignedBigInteger('acted_by')->nullable();
            $t->timestamp('acted_at')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->string('client_id', 64)->nullable();
            $t->timestamps();
            $t->index(['store_id', 'recorded_at']);
            $t->unique(['store_id', 'client_id']);
        });

        // Dispatches are the warehouse's fulfilment orders and shipments, and recalls are
        // Manufacturing's (see 2026_10_21_170000): the packhouse keeps no copies of either.

        AgriSchema::tenantRls(['grading_standards', 'produce_lots', 'packhouse_operations', 'produce_lot_links', 'storage_readings']);
    }

    public function down(): void
    {
        foreach (['produce_recalls', 'produce_dispatch_items', 'produce_dispatches', 'storage_readings',
            'produce_lot_links', 'packhouse_operations', 'produce_lots', 'grading_standards'] as $x) {
            Schema::dropIfExists($x);
        }
        Schema::table('farm_stores', fn (Blueprint $t) => $t->dropColumn(['temperature_min', 'temperature_max', 'humidity_min', 'humidity_max', 'sensor_ref']));
        Schema::table('harvest_records', fn (Blueprint $t) => $t->dropColumn(['crew_id', 'measuring_method', 'inspection_outcome', 'inspection_note', 'inspected_by',
            'vehicle_ref', 'container_ref', 'store_id', 'storage_location']));
    }
};
