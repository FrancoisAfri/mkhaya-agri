<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four gaps closed together:
 *
 *  A. Irrigation scheduling from evapotranspiration — the day's reference ET0 kept with the farm's
 *     daily weather, crop coefficients per crop (FAO-56 defaults, the organisation's to edit), the
 *     daily soil-water balance per irrigation zone (or field) and the irrigations it recommends.
 *  B. Peer benchmarks — an organisation's opt-in, and a table of aggregates only: no company, farm
 *     or field id is ever written to it, so it carries no row-level security of its own.
 *  C. Consumer trace — an unguessable public token per produce lot, with what it may show.
 *  D. Carbon registry — a carbon project's registry listing, monitoring periods and data, issuances,
 *     retirements and transfers, its documents, and the export packages built for a submission.
 *  E. Imagery — "expected drop" rules (a cut, a harvest) and why a capture's flag was suppressed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── A ──────────────────────────────────────────────────────────────────────────────────
        Schema::table('agri_weather', function (Blueprint $t) {
            $t->decimal('et0_mm', 5, 2)->nullable();                     // reference evapotranspiration for the day
            $t->string('et0_source', 12)->nullable();                    // provider | hargreaves
        });

        Schema::create('agri_crop_coefficients', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('crop_type_id')->constrained('crop_types')->cascadeOnDelete();
            $t->decimal('kc_ini', 4, 2);
            $t->decimal('kc_mid', 4, 2);
            $t->decimal('kc_end', 4, 2);
            // FAO-56 stage lengths, as shares of the season (planting → harvest); they add to 100.
            $t->unsignedTinyInteger('stage_ini_pct');
            $t->unsignedTinyInteger('stage_dev_pct');
            $t->unsignedTinyInteger('stage_mid_pct');
            $t->unsignedTinyInteger('stage_late_pct');
            $t->decimal('root_depth_m', 4, 2);                           // effective rooting depth at full cover
            $t->decimal('depletion_p', 3, 2);                            // share of TAW that may go before stress (RAW = p × TAW)
            $t->string('source', 200)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'crop_type_id']);
        });

        Schema::create('agri_water_balance', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->cascadeOnDelete();
            $t->unsignedBigInteger('zone_id')->nullable();
            $t->string('scope_key', 30);                                 // z:<zone id> | f:<field id>
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->date('day');
            $t->boolean('is_forecast')->default(false);
            $t->decimal('et0_mm', 6, 2)->nullable();
            $t->string('et0_source', 12)->nullable();
            $t->decimal('kc', 4, 2)->nullable();
            $t->decimal('etc_mm', 6, 2)->nullable();
            $t->decimal('rain_mm', 7, 2)->default(0);
            $t->decimal('effective_rain_mm', 7, 2)->default(0);
            $t->decimal('irrigation_mm', 7, 2)->default(0);              // net, after application losses
            $t->decimal('depletion_mm', 7, 2);                           // root-zone depletion at the end of the day
            $t->decimal('taw_mm', 7, 2);
            $t->decimal('raw_mm', 7, 2);
            $t->boolean('reset_by_sensor')->default(false);
            $t->decimal('sensor_vwc_pct', 5, 2)->nullable();
            $t->timestamps();
            $t->unique(['scope_key', 'day']);
            $t->index(['farm_id', 'day']);
        });

        Schema::create('agri_irrigation_recommendations', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->cascadeOnDelete();
            $t->unsignedBigInteger('zone_id')->nullable();
            $t->string('scope_key', 30);
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->date('recommend_on');
            $t->decimal('depletion_mm', 7, 2);
            $t->decimal('raw_mm', 7, 2);
            $t->decimal('net_mm', 7, 2);
            $t->decimal('gross_mm', 7, 2);
            $t->decimal('volume_m3', 12, 2)->nullable();
            $t->decimal('area_ha', 10, 4)->nullable();
            $t->string('reason', 300);
            $t->string('status', 12)->default('open');                  // open | planned | dismissed | superseded
            $t->unsignedBigInteger('event_id')->nullable();               // the planned irrigation it became
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('computed_at');
            $t->timestamps();
            $t->index(['scope_key', 'status', 'recommend_on']);
        });

        // ── B ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_org_settings', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->boolean('benchmarks_opt_in')->default(false);            // contribute to, and see, peer benchmarks
            $t->timestamp('benchmarks_opted_at')->nullable();
            $t->unsignedBigInteger('benchmarks_opted_by')->nullable();
            $t->jsonb('trace_defaults')->nullable();                     // what a new public trace token shows
            $t->jsonb('certifications')->nullable();                     // certifications the organisation lets consumers see
            $t->timestamps();
            $t->unique('company_id');
        });

        // Aggregates only: no company_id, farm_id or field_id, by design.
        Schema::create('agri_benchmarks', function (Blueprint $t) {
            $t->id();
            $t->string('season_key', 20);                                // e.g. S2025/26, W2026
            $t->string('crop_code', 30);                                 // the catalogue crop code
            $t->string('crop_name', 120);
            $t->string('region_key', 60);                                // province:<name> | grid:<lat>:<lng>
            $t->string('region_label', 120);
            $t->string('metric', 20);                                    // yield_t_ha | cost_per_ha | cost_per_t
            $t->string('currency', 3)->default('');                      // cost metrics only ('' for yield)
            $t->unsignedInteger('farms');
            $t->unsignedInteger('organisations');
            $t->decimal('p25', 14, 3);
            $t->decimal('median', 14, 3);
            $t->decimal('p75', 14, 3);
            $t->boolean('seeded')->default(false);                       // demo data, not computed
            $t->timestamp('computed_at');
            $t->timestamps();
            $t->unique(['season_key', 'crop_code', 'region_key', 'metric', 'currency']);
        });

        // ── C ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_trace_tokens', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('produce_lot_id')->constrained('produce_lots')->cascadeOnDelete();
            $t->string('token', 64)->unique();
            $t->jsonb('visibility');                                     // which parts of the story the page shows
            $t->string('status', 10)->default('active');                 // active | revoked
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedBigInteger('revoked_by')->nullable();
            $t->string('revoke_reason', 300)->nullable();
            $t->unsignedInteger('views')->default(0);
            $t->timestamp('last_viewed_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['produce_lot_id', 'status']);
        });

        // ── D ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_carbon_registrations', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('environment_record_id')->unique();    // the carbon project
            $t->string('registry', 20);                                  // verra_vcs | gold_standard | other
            $t->string('registry_name', 120)->nullable();                // when other
            $t->string('registry_project_id', 60)->nullable();
            $t->string('status', 20)->default('listed');                 // listed | under_validation | registered | issuing
            $t->date('status_on')->nullable();
            $t->string('methodology', 200)->nullable();
            $t->string('proponent', 200)->nullable();
            $t->date('crediting_starts_on')->nullable();
            $t->date('crediting_ends_on')->nullable();
            $t->decimal('buffer_pct', 5, 2)->default(0);                 // non-permanence buffer withheld from issuance
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_carbon_periods', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('registration_id')->constrained('agri_carbon_registrations')->cascadeOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 12)->default('monitoring');             // monitoring | submitted | verified | issued
            $t->decimal('claimed_t', 14, 3)->nullable();                 // reductions / removals claimed, tCO2e
            $t->decimal('verified_t', 14, 3)->nullable();
            $t->string('verifier', 160)->nullable();
            $t->date('verified_on')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->timestamps();
        });
        Schema::create('agri_carbon_monitoring', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('period_id')->constrained('agri_carbon_periods')->cascadeOnDelete();
            $t->date('measured_on');
            $t->string('parameter', 120);
            $t->decimal('value', 16, 4);
            $t->string('unit', 30);
            $t->unsignedBigInteger('field_id')->nullable();
            $t->string('method', 160)->nullable();
            $t->string('source', 160)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['period_id', 'measured_on']);
        });
        Schema::create('agri_carbon_issuances', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('registration_id')->constrained('agri_carbon_registrations')->cascadeOnDelete();
            $t->unsignedBigInteger('period_id')->nullable();
            $t->unsignedSmallInteger('vintage');
            $t->decimal('quantity_t', 14, 3);                            // credits issued to the project
            $t->decimal('buffer_t', 14, 3)->default(0);                  // withheld in the registry's buffer pool
            $t->string('serial_start', 160)->nullable();
            $t->string('serial_end', 160)->nullable();
            $t->date('issued_on');
            $t->timestamps();
        });
        Schema::create('agri_carbon_transactions', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('issuance_id')->constrained('agri_carbon_issuances')->cascadeOnDelete();
            $t->string('kind', 12);                                      // retirement | transfer
            $t->decimal('quantity_t', 14, 3);
            $t->date('transacted_on');
            $t->string('counterparty', 200)->nullable();                 // the account transferred to
            $t->string('beneficiary', 200)->nullable();                  // on whose behalf it was retired
            $t->string('serial_start', 160)->nullable();
            $t->string('serial_end', 160)->nullable();
            $t->string('reason', 300)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_carbon_documents', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('registration_id')->constrained('agri_carbon_registrations')->cascadeOnDelete();
            $t->unsignedBigInteger('period_id')->nullable();
            $t->string('kind', 30);                                      // pdd | validation_report | monitoring_report | verification_report | evidence | other
            $t->string('name', 200);
            $t->string('mime', 100);
            $t->unsignedBigInteger('size')->default(0);
            $t->longText('data');                                        // base64
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });
        Schema::create('agri_carbon_exports', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('registration_id')->constrained('agri_carbon_registrations')->cascadeOnDelete();
            $t->unsignedBigInteger('period_id')->nullable();
            $t->string('file_name', 200);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->jsonb('contents');                                       // the files in the zip
            $t->longText('data');                                        // the zip, base64
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        // ── E ──────────────────────────────────────────────────────────────────────────────────
        Schema::create('agri_imagery_drop_rules', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('crop_type_id')->nullable();          // null: every crop
            $t->jsonb('activity_types');                                 // field activities that cause the drop (mowing, harvesting…)
            $t->boolean('after_harvest')->default(true);                 // a recorded harvest counts too
            $t->unsignedSmallInteger('within_days');
            $t->boolean('is_active')->default(true);
            $t->string('note', 300)->nullable();
            $t->timestamps();
        });
        Schema::table('agri_field_imagery', function (Blueprint $t) {
            $t->string('flag_suppressed', 300)->nullable();              // why a drop on this capture raised no flag
        });

        AgriSchema::tenantRls(['agri_crop_coefficients', 'agri_water_balance', 'agri_irrigation_recommendations', 'agri_org_settings', 'agri_trace_tokens',
            'agri_carbon_registrations', 'agri_carbon_periods', 'agri_carbon_monitoring', 'agri_carbon_issuances', 'agri_carbon_transactions',
            'agri_carbon_documents', 'agri_carbon_exports', 'agri_imagery_drop_rules']);
    }

    public function down(): void
    {
        Schema::table('agri_field_imagery', fn (Blueprint $t) => $t->dropColumn('flag_suppressed'));
        foreach (['agri_imagery_drop_rules', 'agri_carbon_exports', 'agri_carbon_documents', 'agri_carbon_transactions', 'agri_carbon_issuances', 'agri_carbon_monitoring',
            'agri_carbon_periods', 'agri_carbon_registrations', 'agri_trace_tokens', 'agri_benchmarks', 'agri_org_settings', 'agri_irrigation_recommendations',
            'agri_water_balance', 'agri_crop_coefficients'] as $x) {
            Schema::dropIfExists($x);
        }
        Schema::table('agri_weather', fn (Blueprint $t) => $t->dropColumn(['et0_mm', 'et0_source']));
    }
};
