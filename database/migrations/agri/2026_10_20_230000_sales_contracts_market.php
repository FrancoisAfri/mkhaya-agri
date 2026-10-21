<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §9: buyers and sales (§9.1), contract farming (§9.2), the marketplace's first stage —
 * listings that say whether the produce exists or is forecast, and buyer requests (§9.3) — and
 * market prices with their source (§9.4).
 *
 * Customers, price lists, quotes, orders, invoices and disputes are Accounting's (see the finance
 * migration that gives its documents grade, unit and dimensions); only what is particular to selling
 * a crop lives here — supply contracts and their amendments, market prices, and the marketplace's
 * listings and requests — each pointing at the Accounting customer (fin_parties).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_sales_contracts', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->string('reference', 30);
            $t->unsignedBigInteger('party_id');                              // the Accounting customer
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->unsignedBigInteger('variety_id')->nullable();
            $t->string('product', 120);
            $t->decimal('quantity', 14, 3);
            $t->string('unit', 12);
            $t->decimal('tolerance_pct', 5, 2)->default(0);                 // ± on the quantity before it is short or over
            $t->json('windows');                                             // [{from, to, quantity, location}]
            $t->unsignedBigInteger('standard_id')->nullable();               // grading standard the quality is judged by
            $t->json('accepted_grades')->nullable();
            $t->string('quality_spec', 1000)->nullable();
            $t->string('pricing', 10)->default('fixed');                     // fixed | formula
            $t->decimal('price', 14, 4)->nullable();                         // fixed price per unit
            $t->json('formula')->nullable();                                 // {market, product, grade, unit, lookback_days, adjust_pct, premium, floor, cap, grade_premiums}
            $t->string('currency', 3)->default('ZAR');
            $t->string('packaging', 500)->nullable();
            $t->string('transport', 10)->default('farm');                    // farm | buyer
            $t->string('rejection_rules', 1000)->nullable();
            $t->string('dispute_procedure', 1000)->nullable();
            $t->decimal('advance_amount', 14, 2)->nullable();
            $t->json('input_financing')->nullable();                         // [{item, value, recover_from}] — inputs the buyer finances, recovered from payments
            $t->string('status', 12)->default('draft');                      // draft | active | suspended | completed | cancelled
            $t->unsignedSmallInteger('version')->default(1);
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_contract_amendments', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('contract_id')->constrained('agri_sales_contracts')->cascadeOnDelete();
            $t->unsignedSmallInteger('version');
            $t->json('changes');                                             // {field: [from, to]}
            $t->string('reason', 500);
            $t->string('status', 10)->default('proposed');                   // proposed | approved | rejected
            $t->unsignedBigInteger('proposed_by')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        if (Schema::hasTable('produce_dispatches')) {
            Schema::table('produce_dispatches', fn (Blueprint $t) => $t->unsignedBigInteger('invoice_id')->nullable());
        }

        Schema::create('agri_market_prices', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('product', 120);
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->string('grade', 40)->nullable();
            $t->string('market', 120);                                       // e.g. Johannesburg Fresh Produce Market
            $t->string('region', 80)->nullable();
            $t->date('price_date');
            $t->decimal('price', 14, 4);
            $t->string('currency', 3)->default('ZAR');
            $t->string('unit', 12);
            $t->string('kind', 10)->default('actual');                       // actual | forecast
            $t->string('source', 160);                                       // who published it
            $t->string('source_url', 300)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'product', 'market', 'price_date']);
        });

        Schema::create('agri_market_listings', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('product', 120);
            $t->string('grade', 40)->nullable();
            $t->decimal('quantity', 14, 3);
            $t->string('unit', 12);
            $t->string('availability', 10);                                  // in_stock | forecast
            $t->json('lot_ids')->nullable();                                 // in stock: the lots behind it
            $t->unsignedBigInteger('crop_plan_id')->nullable();              // forecast: the crop it will come from
            $t->date('available_from')->nullable();
            $t->decimal('asking_price', 14, 4)->nullable();
            $t->string('currency', 3)->default('ZAR');
            $t->string('status', 10)->default('open');                       // open | reserved | sold | withdrawn
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_market_requests', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('party_id')->nullable();                  // the Accounting customer, when known
            $t->string('buyer_name', 160);
            $t->string('product', 120);
            $t->string('grade', 40)->nullable();
            $t->decimal('quantity', 14, 3);
            $t->string('unit', 12);
            $t->date('needed_by')->nullable();
            $t->decimal('target_price', 14, 4)->nullable();
            $t->string('status', 10)->default('open');                       // open | matched | closed
            $t->unsignedBigInteger('quote_id')->nullable();
            $t->timestamps();
        });

        AgriSchema::tenantRls(['agri_sales_contracts', 'agri_contract_amendments', 'agri_market_prices', 'agri_market_listings', 'agri_market_requests']);
    }

    public function down(): void
    {
        foreach (['agri_market_requests', 'agri_market_listings', 'agri_market_prices', 'agri_contract_amendments', 'agri_sales_contracts'] as $x) {
            Schema::dropIfExists($x);
        }
        if (Schema::hasColumn('produce_dispatches', 'invoice_id')) {
            Schema::table('produce_dispatches', fn (Blueprint $t) => $t->dropColumn('invoice_id'));
        }
    }
};
