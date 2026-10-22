<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three gaps closed together:
 *
 *  A. Residues and MRLs — the markets produce is sold into (with the countries each covers and
 *     whether a residue result is required before produce ships there), maximum residue limits per
 *     active ingredient × crop × market with their source and effective date, actives banned or
 *     restricted per market, laboratory residue tests with a result per active, and the audited
 *     overrides that let a blocked load go. Lots and crop plans may name the markets they are for.
 *  B. Certification checklists — which ready-made scheme template (GLOBALG.A.P. IFA, SIZA, organic)
 *     an organisation installed as its own SHEQ compliance scheme, and the self-assessment answers
 *     given per farm and control point.
 *  C. Export document packs — exporter registration codes per farm or packhouse (PUC, PHC, FBO…),
 *     an export consignment per load with its lines per lot, and each generated document's versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── A ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_mrl_markets', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('code', 20);                                      // EU | UK | ZA | CN | US | CODEX | a custom code
            $t->string('name', 120);
            $t->jsonb('countries')->nullable();                          // ISO 3166 alpha-2 codes the market covers
            $t->decimal('default_mrl_mg_kg', 12, 4)->nullable();          // where no specific MRL is set (EU/UK: 0.01)
            $t->boolean('requires_result')->default(false);              // no residue result → no shipment
            $t->boolean('default_target')->default(false);               // a lot with no markets named is assessed for this one
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('agri_mrls', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('active_ingredient', 160);
            $t->string('active_key', 160);                               // lower-case, single-spaced: what matches
            $t->string('crop_code', 40);                                 // the catalogue crop code (CITRUS, GRAPE…)
            $t->string('market_code', 20);
            $t->decimal('mrl_mg_kg', 12, 4);
            $t->string('source', 300)->nullable();
            $t->date('effective_from');
            $t->boolean('is_starter')->default(false);                   // shipped as a starting point, not verified
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'active_key', 'crop_code', 'market_code', 'effective_from']);
            $t->index(['company_id', 'crop_code', 'market_code']);
        });

        Schema::create('agri_market_restrictions', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('active_ingredient', 160);
            $t->string('active_key', 160);
            $t->string('market_code', 20);
            $t->string('status', 12);                                    // banned | restricted
            $t->string('source', 300)->nullable();
            $t->date('effective_from')->nullable();
            $t->boolean('is_starter')->default(false);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'active_key', 'market_code']);
        });

        Schema::create('agri_residue_tests', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('reference', 40);
            $t->string('laboratory', 160);
            $t->string('lab_reference', 80)->nullable();
            $t->string('sample_type', 10);                               // lot | field
            $t->unsignedBigInteger('produce_lot_id')->nullable();
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->string('crop_code', 40)->nullable();
            $t->date('sampled_on');
            $t->date('reported_on')->nullable();
            $t->string('certificate_name', 200)->nullable();
            $t->string('certificate_mime', 100)->nullable();
            $t->text('certificate_data')->nullable();                    // base64
            $t->string('notes', 1000)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['farm_id', 'sampled_on']);
            $t->index('produce_lot_id');
            $t->index('crop_plan_id');
        });

        Schema::create('agri_residue_results', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('test_id')->constrained('agri_residue_tests')->cascadeOnDelete();
            $t->string('active_ingredient', 160);
            $t->string('active_key', 160);
            $t->decimal('measured_mg_kg', 12, 4);                        // 0 with below_loq when not detected
            $t->decimal('loq_mg_kg', 12, 4)->nullable();
            $t->boolean('below_loq')->default(false);
            $t->timestamps();
            $t->index(['test_id', 'active_key']);
        });

        Schema::create('agri_residue_overrides', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('wms_order_id');                      // the load
            $t->string('market_code', 20);
            $t->jsonb('blocks');                                         // what the gate said when it was overridden
            $t->string('reason', 1000);
            $t->unsignedBigInteger('authorised_by');
            $t->timestamps();
            $t->index(['wms_order_id', 'market_code']);
        });

        Schema::table('produce_lots', function (Blueprint $t) {
            $t->jsonb('target_markets')->nullable();
        });
        Schema::table('crop_plans', function (Blueprint $t) {
            $t->jsonb('target_markets')->nullable();
        });

        // ── B ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_scheme_installs', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->string('template_code', 60);
            $t->string('template_version', 20);
            $t->unsignedBigInteger('scheme_id');                         // the organisation's own SHEQ scheme
            $t->unsignedBigInteger('installed_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'template_code']);
        });

        Schema::create('agri_self_assessments', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('scheme_id');
            $t->string('clause', 60);
            $t->string('answer', 16);                                    // compliant | non_compliant | not_applicable
            $t->string('comment', 2000)->nullable();
            $t->unsignedBigInteger('answered_by')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'scheme_id', 'clause']);
        });

        // ── C ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_export_codes', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('store_id')->nullable();              // a packhouse; null: the farm (production unit)
            $t->string('kind', 20);                                      // PUC | PHC | FBO | EXPORTER | other
            $t->string('code', 60);
            $t->date('valid_until')->nullable();
            $t->string('notes', 300)->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'store_id', 'kind', 'code']);
        });

        Schema::create('agri_export_consignments', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('wms_order_id')->unique();            // the load
            $t->string('reference', 40);
            $t->string('exporter_name', 200)->nullable();
            $t->string('exporter_address', 500)->nullable();
            $t->string('exporter_code', 60)->nullable();
            $t->string('consignee_name', 200)->nullable();
            $t->string('consignee_address', 500)->nullable();
            $t->string('destination_country', 2)->nullable();
            $t->string('notify_party', 500)->nullable();
            $t->string('incoterm', 3)->nullable();
            $t->string('incoterm_place', 120)->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('transport_mode', 8)->nullable();                 // sea | air | road
            $t->string('container_no', 20)->nullable();
            $t->string('seal_no', 40)->nullable();
            $t->string('vessel', 120)->nullable();
            $t->string('voyage', 40)->nullable();
            $t->string('awb_no', 40)->nullable();
            $t->string('flight_no', 20)->nullable();
            $t->string('carrier', 120)->nullable();
            $t->string('booking_ref', 60)->nullable();
            $t->string('port_of_loading', 120)->nullable();
            $t->string('port_of_discharge', 120)->nullable();
            $t->string('final_destination', 120)->nullable();
            $t->date('departure_on')->nullable();
            $t->date('arrival_on')->nullable();
            $t->decimal('temperature_setpoint_c', 5, 2)->nullable();
            $t->decimal('temperature_min_c', 5, 2)->nullable();
            $t->decimal('temperature_max_c', 5, 2)->nullable();
            $t->boolean('cold_treatment')->default(false);
            $t->string('cold_treatment_protocol', 200)->nullable();
            $t->string('ventilation', 60)->nullable();
            $t->string('inspection_point', 160)->nullable();             // where PPECB inspects
            $t->date('inspection_on')->nullable();
            $t->string('import_permit_no', 60)->nullable();
            $t->string('additional_declarations', 1000)->nullable();     // phyto: additional declarations the importing country asks for
            $t->jsonb('required_documents');                             // the kinds this consignment's pack needs
            $t->string('notes', 1000)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('agri_export_consignment_lines', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('consignment_id')->constrained('agri_export_consignments')->cascadeOnDelete();
            $t->unsignedBigInteger('produce_lot_id')->nullable();
            $t->string('description', 200);
            $t->string('hs_code', 12)->nullable();
            $t->string('pallet_ids', 1000)->nullable();                  // SSCCs or pallet numbers, comma separated
            $t->unsignedInteger('pallets')->nullable();
            $t->decimal('cartons', 12, 2)->nullable();
            $t->string('unit', 12)->nullable();
            $t->decimal('net_kg', 12, 2)->nullable();
            $t->decimal('gross_kg', 12, 2)->nullable();
            $t->decimal('unit_price', 14, 4)->nullable();
            $t->string('variety', 80)->nullable();
            $t->string('grade', 40)->nullable();
            $t->string('puc', 60)->nullable();                           // the production unit the lot came from
            $t->timestamps();
        });

        Schema::create('agri_export_documents', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('consignment_id')->constrained('agri_export_consignments')->cascadeOnDelete();
            $t->string('kind', 30);
            $t->unsignedInteger('version');
            $t->unsignedBigInteger('document_id');                      // Document Management
            $t->string('data_hash', 64);                                 // of what it was generated from
            $t->string('status', 12)->default('current');                // current | superseded
            $t->unsignedBigInteger('generated_by')->nullable();
            $t->timestamp('generated_at');
            $t->timestamps();
            $t->index(['consignment_id', 'kind', 'status']);
        });

        AgriSchema::tenantRls(['agri_mrl_markets', 'agri_mrls', 'agri_market_restrictions', 'agri_residue_tests', 'agri_residue_results', 'agri_residue_overrides',
            'agri_scheme_installs', 'agri_self_assessments', 'agri_export_codes', 'agri_export_consignments', 'agri_export_consignment_lines', 'agri_export_documents']);
    }

    public function down(): void
    {
        foreach (['agri_export_documents', 'agri_export_consignment_lines', 'agri_export_consignments', 'agri_export_codes', 'agri_self_assessments', 'agri_scheme_installs',
            'agri_residue_overrides', 'agri_residue_results', 'agri_residue_tests', 'agri_market_restrictions', 'agri_mrls', 'agri_mrl_markets'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('crop_plans', fn (Blueprint $t) => $t->dropColumn('target_markets'));
        Schema::table('produce_lots', fn (Blueprint $t) => $t->dropColumn('target_markets'));
    }
};
