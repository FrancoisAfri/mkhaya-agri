<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §5: a farm's stores (more than one: silos, cold rooms, the chemical store, the fuel
 * tank), what each item is to a farm, the register of regulated chemicals and the rules they are
 * kept and used under, and disputes raised with suppliers.
 *
 * Stock itself stays in the shared inventory (warehouses, lots, movements): this adds only what a
 * farm needs to know about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_stores', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('warehouse_id')->unique();
            $t->string('kind', 20);            // main | silo | cold_room | chemical_store | fuel_tank | feed_store | workshop | packhouse | other
            $t->string('name', 120);
            $t->decimal('capacity', 14, 3)->nullable();
            $t->string('capacity_unit', 12)->nullable();
            // Chemical storage classes this store may hold; null = no chemicals at all.
            $t->jsonb('allowed_storage_classes')->nullable();
            $t->boolean('restricted')->default(false);               // locked store: only authorised people issue from it
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        // Every farm that already has a store gets it registered as its main store.
        foreach (DB::table('farms')->whereNotNull('warehouse_id')->get(['id', 'uuid', 'company_id', 'warehouse_id', 'name']) as $f) {
            DB::table('farm_stores')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $f->company_id, 'farm_id' => $f->id,
                'warehouse_id' => $f->warehouse_id, 'kind' => 'main', 'name' => "{$f->name} store", 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('agri_item_settings', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('stock_item_id');
            // seed | fertiliser | crop_protection | feed | veterinary | fuel | lubricant | spare_part | packaging | produce | tool | ppe | consumable | other
            $t->string('category', 20);
            $t->unsignedBigInteger('preferred_supplier_id')->nullable();
            $t->unsignedBigInteger('default_store_id')->nullable();   // farm_stores.id
            $t->boolean('lot_tracked')->default(false);
            $t->boolean('serial_tracked')->default(false);
            $t->timestamps();
            $t->unique(['company_id', 'stock_item_id']);
        });

        Schema::create('chemical_products', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('stock_item_id');
            $t->string('registration_no', 40)->nullable();           // e.g. Act 36 L-number
            $t->string('active_ingredient', 200)->nullable();
            $t->string('formulation', 40)->nullable();
            $t->string('hazard_class', 30)->nullable();               // GHS / toxicity class
            $t->string('storage_class', 20);                          // flammable | oxidiser | corrosive | toxic | fertiliser_oxidising | general
            $t->jsonb('incompatible_with')->nullable();               // storage classes it may not share a store with
            $t->string('ppe', 300)->nullable();
            $t->string('handling', 1000)->nullable();
            $t->unsignedSmallInteger('reentry_hours')->nullable();
            $t->unsignedSmallInteger('withholding_days')->nullable();
            $t->boolean('restricted')->default(false);                // only authorised people may issue or apply it
            $t->string('competency_code', 40)->nullable();            // the SHEQ competency (e.g. a pesticide applicator licence) required
            $t->jsonb('authorised_user_ids')->nullable();             // named people, besides the competency
            $t->date('registration_expires_on')->nullable();
            $t->string('label_name')->nullable();
            $t->string('label_mime', 80)->nullable();
            $t->longText('label_data')->nullable();
            $t->string('sds_name')->nullable();
            $t->string('sds_mime', 80)->nullable();
            $t->longText('sds_data')->nullable();
            $t->date('sds_revised_on')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'stock_item_id']);
        });

        Schema::create('supplier_disputes', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('purchase_order_id')->nullable();
            $t->unsignedBigInteger('grn_id')->nullable();
            $t->string('kind', 20);                                   // short_delivery | damaged | wrong_item | quality | price | late | invoice | other
            $t->string('description', 2000);
            $t->decimal('amount', 14, 2)->nullable();
            $t->string('status', 12)->default('open');                // open | resolved | rejected
            $t->string('resolution', 1000)->nullable();
            $t->unsignedBigInteger('raised_by')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'supplier_id']);
        });

        AgriSchema::tenantRls(['farm_stores', 'agri_item_settings', 'chemical_products', 'supplier_disputes']);
    }

    public function down(): void
    {
        foreach (['supplier_disputes', 'chemical_products', 'agri_item_settings', 'farm_stores'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
