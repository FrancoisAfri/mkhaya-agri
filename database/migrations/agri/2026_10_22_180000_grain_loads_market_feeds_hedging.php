<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two gaps closed together:
 *
 *  A. Grain loads over the weighbridge — a load ticket per truck from a field (or a silo) to the
 *     farm's own silo or a buyer, with its two weighings (from a weighbridge device or captured by
 *     hand), quality, SA grade and the moisture, handling and screenings deductions that give the
 *     settled mass; photos kept in the database. A load into the farm's silo becomes stock through
 *     the harvest path (no second inventory); a load to a buyer is a delivery, matched line by line
 *     to the buyer's settlement statement, which then posts through the farm's sales ledger route.
 *     The grain grading rules live on the existing grading standards.
 *  B. Market price feeds and hedging — a per-organisation price feed (CSV or JSON, URL, encrypted
 *     credentials, column mapping) that fills the existing market prices, which gain a contract
 *     month and a price type; a hedging register of futures and options positions with a daily
 *     mark-to-market. Records only: nothing here trades with a broker.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── A ──────────────────────────────────────────────────────────────────────────────────
        Schema::table('grading_standards', function (Blueprint $t) {
            $t->string('crop_code', 40)->nullable();                     // the catalogue crop code a grain standard is for
            $t->jsonb('grain_rules')->nullable();                        // {base_moisture_pct, max_moisture_pct, handling_pct, screenings_allowance_pct, source}
        });

        Schema::create('agri_grain_tickets', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('number', 30);
            $t->string('status', 12)->default('dispatched');             // dispatched | weighing | weighed | complete | void
            $t->string('movement', 16)->nullable();                      // field_to_store | field_to_buyer | store_to_buyer
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->unsignedBigInteger('from_store_id')->nullable();
            $t->unsignedBigInteger('to_store_id')->nullable();
            $t->unsignedBigInteger('stock_item_id')->nullable();
            $t->unsignedBigInteger('party_id')->nullable();              // the buyer (Accounting customer)
            $t->unsignedBigInteger('contract_id')->nullable();           // agri_sales_contracts
            $t->string('destination_name', 160)->nullable();             // the buyer's silo, elevator or mill
            $t->string('vehicle_reg', 20)->nullable();
            $t->string('trailer_reg', 20)->nullable();
            $t->string('driver', 120)->nullable();
            $t->string('load_ref', 40)->nullable();                      // the field's own load or bin-cart number
            $t->timestamp('dispatched_at')->nullable();
            $t->timestamp('first_weighed_at')->nullable();
            $t->timestamp('second_weighed_at')->nullable();
            $t->decimal('gross_kg', 12, 1)->nullable();
            $t->decimal('tare_kg', 12, 1)->nullable();
            $t->decimal('net_kg', 12, 1)->nullable();
            $t->string('weigh_source', 10)->nullable();                  // device | manual | mixed
            $t->decimal('moisture_pct', 5, 2)->nullable();
            $t->decimal('foreign_matter_pct', 5, 2)->nullable();         // foreign matter / screenings
            $t->jsonb('quality')->nullable();                            // {defects_pct, protein_pct, hectolitre_kg, …}
            $t->unsignedBigInteger('standard_id')->nullable();
            $t->string('grade', 16)->nullable();
            $t->string('suggested_grade', 16)->nullable();
            $t->decimal('moisture_deduction_kg', 12, 1)->default(0);
            $t->decimal('handling_deduction_kg', 12, 1)->default(0);
            $t->decimal('screenings_deduction_kg', 12, 1)->default(0);
            $t->decimal('settled_kg', 12, 1)->nullable();
            $t->jsonb('deduction_basis')->nullable();
            $t->decimal('unit_value', 14, 4)->nullable();                // value per plan unit when it went into store
            $t->unsignedBigInteger('harvest_record_id')->nullable();
            $t->jsonb('stock_out')->nullable();                          // [{lot_id, lot, quantity, movement_id}] for a load out of a silo
            $t->string('buyer_ticket_ref', 60)->nullable();              // the buyer's own weighbridge / delivery receipt number
            $t->unsignedBigInteger('settlement_line_id')->nullable();
            $t->jsonb('flags')->nullable();                              // [{code, message}] anomalies on the weighing
            $t->string('completion_error', 500)->nullable();
            $t->string('notes', 1000)->nullable();
            $t->string('void_reason', 300)->nullable();
            $t->unsignedBigInteger('captured_by')->nullable();
            $t->unsignedBigInteger('completed_by')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'number']);
            $t->index(['company_id', 'vehicle_reg', 'status']);
            $t->index(['company_id', 'party_id', 'status']);
            $t->index(['company_id', 'contract_id']);
        });

        Schema::create('agri_grain_weighings', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->unsignedBigInteger('ticket_id')->nullable();
            $t->unsignedBigInteger('device_id')->nullable();
            $t->string('external_id', 120)->nullable();                  // the weighbridge's own id: a repeat is answered, not weighed twice
            $t->string('kind', 8)->nullable();                           // gross | tare | null (unknown: paired by size)
            $t->decimal('mass_kg', 12, 1);
            $t->timestamp('weighed_at');
            $t->string('vehicle_reg', 20)->nullable();
            $t->string('ticket_ref', 60)->nullable();
            $t->string('source', 10)->default('device');                 // device | manual
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'external_id']);
            $t->index(['company_id', 'ticket_id']);
        });

        Schema::create('agri_grain_ticket_photos', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('ticket_id')->constrained('agri_grain_tickets')->cascadeOnDelete();
            $t->string('name', 200);
            $t->string('mime', 80);
            $t->longText('data');                                        // base64: files live in the database
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });

        Schema::create('agri_grain_settlements', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->unsignedBigInteger('party_id');
            $t->unsignedBigInteger('contract_id')->nullable();
            $t->string('reference', 60);                                 // the buyer's statement number
            $t->date('statement_date');
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->string('source', 10)->default('manual');                 // manual | csv
            $t->string('file_name', 200)->nullable();
            $t->string('file_mime', 80)->nullable();
            $t->longText('file_data')->nullable();                       // the imported file, base64
            $t->decimal('charges', 14, 2)->default(0);                   // levies, transport, storage the buyer took off
            $t->string('charges_note', 300)->nullable();
            $t->string('status', 10)->default('draft');                  // draft | matched | posted
            $t->jsonb('totals')->nullable();
            $t->jsonb('journal_ids')->nullable();
            $t->timestamp('matched_at')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->unsignedBigInteger('posted_by')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'party_id', 'reference']);
        });

        Schema::create('agri_grain_settlement_lines', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('settlement_id')->constrained('agri_grain_settlements')->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('buyer_ticket_ref', 60)->nullable();
            $t->string('our_ticket_ref', 60)->nullable();
            $t->string('vehicle_reg', 20)->nullable();
            $t->date('delivered_on')->nullable();
            $t->decimal('gross_kg', 12, 1)->nullable();
            $t->decimal('tare_kg', 12, 1)->nullable();
            $t->decimal('net_kg', 12, 1)->nullable();
            $t->decimal('moisture_pct', 5, 2)->nullable();
            $t->decimal('foreign_matter_pct', 5, 2)->nullable();
            $t->string('grade', 16)->nullable();
            $t->decimal('deductions_kg', 12, 1)->nullable();
            $t->decimal('settled_kg', 12, 1)->nullable();
            $t->decimal('price_per_t', 14, 4)->nullable();
            $t->decimal('amount', 14, 2)->nullable();
            $t->decimal('expected_amount', 14, 2)->nullable();           // what our contract price says the line is worth
            $t->unsignedBigInteger('ticket_id')->nullable();
            $t->string('match_status', 10)->default('unmatched');        // unmatched | matched | differs | duplicate
            $t->jsonb('differences')->nullable();                        // [{field, ours, theirs, message}]
            $t->timestamps();
            $t->index(['company_id', 'ticket_id']);
        });

        // ── B ──────────────────────────────────────────────────────────────────────────────────
        Schema::table('agri_market_prices', function (Blueprint $t) {
            $t->string('contract_month', 7)->nullable();                 // YYYY-MM of a futures contract
            $t->string('price_type', 10)->default('spot');               // spot | futures | cash
            $t->unsignedBigInteger('feed_id')->nullable();
            $t->string('external_id', 160)->nullable();
            $t->boolean('flagged')->default(false);                      // the feed's text read like instructions; kept as text
            $t->index(['company_id', 'crop_type_id', 'price_type', 'contract_month', 'price_date'], 'agri_market_prices_curve_idx');
        });

        Schema::create('agri_price_feeds', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('name', 120);
            $t->string('adapter', 8);                                    // csv | json
            $t->string('url', 500);
            $t->text('credentials')->nullable();                         // encrypted {type, name, value, username, password}
            $t->jsonb('config')->nullable();                             // {list, delimiter, columns{…}, products{…}, date_format, price_multiplier}
            $t->string('market', 120);
            $t->string('product', 120)->nullable();
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->string('currency', 3)->default('ZAR');
            $t->string('unit', 12)->default('t');
            $t->string('price_type', 10)->default('futures');
            $t->unsignedInteger('refresh_minutes')->default(1440);
            $t->string('status', 10)->default('active');                 // active | failing | paused
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->timestamp('next_attempt_at')->nullable();
            $t->unsignedInteger('failure_count')->default(0);
            $t->string('last_error', 300)->nullable();
            $t->unsignedInteger('last_items')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('agri_hedges', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();
            $t->string('reference', 30);
            $t->string('instrument', 8);                                 // futures | option
            $t->string('side', 4);                                       // sell | buy
            $t->string('option_type', 4)->nullable();                    // put | call
            $t->decimal('strike', 14, 4)->nullable();
            $t->decimal('premium', 14, 4)->nullable();                   // per unit, paid (bought) or received (sold)
            $t->unsignedBigInteger('crop_type_id');
            $t->string('product', 120);
            $t->string('market', 120);
            $t->string('contract_month', 7);
            $t->decimal('contracts', 10, 2);
            $t->decimal('contract_size', 10, 3);
            $t->decimal('quantity', 14, 3);                              // contracts × size
            $t->string('unit', 12)->default('t');
            $t->string('currency', 3)->default('ZAR');
            $t->decimal('entry_price', 14, 4);
            $t->date('entry_date');
            $t->string('broker', 120)->nullable();
            $t->string('account', 60)->nullable();
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->unsignedBigInteger('contract_id')->nullable();           // the sales contract it hedges, if any
            $t->string('status', 10)->default('open');                   // open | closed | expired | exercised
            $t->decimal('exit_price', 14, 4)->nullable();
            $t->date('exit_date')->nullable();
            $t->decimal('realised_pnl', 14, 2)->nullable();
            $t->decimal('last_mark_price', 14, 4)->nullable();
            $t->date('last_mark_date')->nullable();
            $t->decimal('unrealised_pnl', 14, 2)->nullable();
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'reference']);
        });

        Schema::create('agri_hedge_marks', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('hedge_id')->constrained('agri_hedges')->cascadeOnDelete();
            $t->date('mark_date');
            $t->decimal('futures_price', 14, 4);
            $t->date('price_date');                                      // the price used (the latest on or before the mark date)
            $t->decimal('value_per_unit', 14, 4);
            $t->decimal('unrealised_pnl', 14, 2);
            $t->timestamps();
            $t->unique(['hedge_id', 'mark_date']);
        });

        AgriSchema::tenantRls(['agri_grain_tickets', 'agri_grain_weighings', 'agri_grain_ticket_photos', 'agri_grain_settlements', 'agri_grain_settlement_lines',
            'agri_price_feeds', 'agri_hedges', 'agri_hedge_marks']);
    }

    public function down(): void
    {
        foreach (['agri_hedge_marks', 'agri_hedges', 'agri_price_feeds', 'agri_grain_settlement_lines', 'agri_grain_settlements', 'agri_grain_ticket_photos',
            'agri_grain_weighings', 'agri_grain_tickets'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('agri_market_prices', function (Blueprint $t) {
            $t->dropIndex('agri_market_prices_curve_idx');
            $t->dropColumn(['contract_month', 'price_type', 'feed_id', 'external_id', 'flagged']);
        });
        Schema::table('grading_standards', fn (Blueprint $t) => $t->dropColumn(['crop_code', 'grain_rules']));
    }
};
