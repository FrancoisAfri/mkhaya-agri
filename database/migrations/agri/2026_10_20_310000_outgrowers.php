<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

/**
 * Phase 2 §16D: a commercial farm working with independent growers — their contracts, the inputs
 * advanced to them, crop inspections and forecasts, collections with grading, and settlements that
 * pay for what was delivered less the approved advances.
 *
 * Advances are an asset (1280 Grower Input Advances) until recovered: issuing inputs moves them
 * out of stock into it, and settlement recovers them against the grower's bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('fin_accounts')->whereNull('company_id')->where('code', '1280')->exists()) {
            DB::table('fin_accounts')->insert(['uuid' => Uuid::uuid5(Uuid::NAMESPACE_OID, 'fin_account:1280')->toString(), 'company_id' => null, 'code' => '1280',
                'name' => 'Grower Input Advances', 'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('agri_growers', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id');                               // the collecting farm
            $t->unsignedBigInteger('party_id');                              // fin_parties: billed and paid as a supplier
            $t->string('code', 30);
            $t->string('location', 200)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('hectares', 10, 2)->nullable();
            $t->boolean('verified')->default(false);
            $t->string('status', 10)->default('active');
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        Schema::create('agri_grower_contracts', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->string('reference', 30);
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->string('product', 120);
            $t->decimal('hectares', 10, 2)->nullable();
            $t->decimal('expected_quantity', 14, 3);
            $t->string('unit', 12);
            $t->decimal('price', 14, 4);                                     // per unit of the base grade
            $t->json('grade_prices')->nullable();                            // {grade: price}
            $t->decimal('advance_limit', 14, 2)->default(0);
            $t->string('status', 10)->default('draft');                      // draft | active | closed
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_grower_advances', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->unsignedBigInteger('contract_id');
            $t->string('kind', 6);                                           // input | cash
            $t->unsignedBigInteger('stock_item_id')->nullable();
            $t->decimal('quantity', 14, 3)->nullable();
            $t->decimal('value', 14, 2);
            $t->decimal('recovered', 14, 2)->default(0);
            $t->date('issued_on');
            $t->unsignedBigInteger('stock_movement_id')->nullable();
            $t->unsignedBigInteger('journal_id')->nullable();
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_grower_inspections', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->unsignedBigInteger('contract_id');
            $t->date('inspected_on');
            $t->string('stage', 40)->nullable();
            $t->decimal('estimated_quantity', 14, 3)->nullable();           // the inspector's forecast
            $t->string('outcome', 12)->default('satisfactory');             // satisfactory | concern | failed
            $t->string('notes', 1000)->nullable();
            $t->unsignedBigInteger('inspected_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_grower_collections', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->unsignedBigInteger('contract_id');
            $t->date('collected_on');
            $t->decimal('quantity', 14, 3);                                  // delivered
            $t->decimal('rejected', 14, 3)->default(0);
            $t->string('grade', 40)->nullable();
            $t->decimal('price', 14, 4);
            $t->decimal('value', 14, 2);                                     // accepted × price
            $t->unsignedBigInteger('lot_id')->nullable();
            $t->unsignedBigInteger('stock_movement_id')->nullable();
            $t->unsignedBigInteger('settlement_id')->nullable();
            $t->string('client_id', 64)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->unique(['grower_id', 'client_id']);
        });
        Schema::create('agri_grower_settlements', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('grower_id');
            $t->string('reference', 30);
            $t->decimal('gross', 14, 2);
            $t->decimal('advances_recovered', 14, 2)->default(0);
            $t->decimal('net', 14, 2);
            $t->string('status', 10)->default('draft');                      // draft | approved
            $t->unsignedBigInteger('invoice_id')->nullable();                // the grower's bill in Finance
            $t->unsignedBigInteger('recovery_journal_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
        });
        AgriSchema::tenantRls(['agri_growers', 'agri_grower_contracts', 'agri_grower_advances', 'agri_grower_inspections', 'agri_grower_collections', 'agri_grower_settlements']);
    }

    public function down(): void
    {
        foreach (['agri_grower_settlements', 'agri_grower_collections', 'agri_grower_inspections', 'agri_grower_advances', 'agri_grower_contracts', 'agri_growers'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
