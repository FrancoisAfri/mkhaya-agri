<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §3: precision crop and production management — the field and crop lifecycle (§3.1),
 * input planning (§3.2), scouting with thresholds and review (§3.3), irrigation and water (§3.4),
 * harvest forecasting, losses, commitments and lots (§3.5), and the overhead rules crop
 * profitability allocates with (§3.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── §3.1 Field and crop lifecycle ────────────────────────────────────────────────────
        Schema::table('farm_fields', function (Blueprint $t) {
            $t->decimal('slope_pct', 5, 2)->nullable();
            $t->string('water_source', 40)->nullable();               // borehole | river | dam | municipal | canal | rain | other
            $t->unsignedBigInteger('parent_field_id')->nullable();     // a split field's whole
        });
        Schema::table('crop_plans', function (Blueprint $t) {
            $t->string('seed_lot', 60)->nullable();
            $t->decimal('planting_density', 12, 2)->nullable();
            $t->string('density_unit', 20)->nullable();                // plants/ha | kg/ha | seeds/m
            $t->string('production_method', 30)->nullable();           // conventional | conservation | organic | hydroponic | protected …
            $t->boolean('intercrop')->default(false);                  // may share the field's area with another plan
            $t->string('current_stage', 40)->nullable();
        });
        Schema::create('crop_stage_events', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('crop_plan_id')->constrained('crop_plans')->cascadeOnDelete();
            $t->string('stage', 40);
            $t->date('observed_on');
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['crop_plan_id', 'observed_on']);
        });
        Schema::create('soil_tests', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $t->date('sampled_on');
            $t->string('laboratory', 120)->nullable();
            $t->string('sample_ref', 60)->nullable();
            $t->unsignedSmallInteger('depth_cm')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('ph', 4, 2)->nullable();
            $t->decimal('organic_carbon_pct', 5, 2)->nullable();
            $t->decimal('n_mg_kg', 8, 2)->nullable();
            $t->decimal('p_mg_kg', 8, 2)->nullable();
            $t->decimal('k_mg_kg', 8, 2)->nullable();
            $t->decimal('ca_mg_kg', 8, 2)->nullable();
            $t->decimal('mg_mg_kg', 8, 2)->nullable();
            $t->decimal('ec_ms_m', 8, 2)->nullable();
            $t->jsonb('other_results')->nullable();
            $t->text('recommendation')->nullable();
            $t->string('report_name')->nullable();
            $t->string('report_mime', 80)->nullable();
            $t->longText('report_data')->nullable();                  // base64, as every file is kept
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['field_id', 'sampled_on']);
        });

        // ── §3.2 Input planning ──────────────────────────────────────────────────────────────
        Schema::create('crop_input_templates', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->foreignId('crop_type_id')->constrained('crop_types')->cascadeOnDelete();
            $t->string('production_method', 30)->nullable();           // null = any method
            $t->string('category', 20);                                // seed | fertiliser | chemical | water | labour | machinery | packaging | other
            $t->unsignedBigInteger('stock_item_id')->nullable();
            $t->string('description', 200);
            $t->decimal('rate', 14, 4);
            $t->string('basis', 12)->default('per_ha');                // per_ha | per_t_yield
            $t->string('unit', 20);                                     // kg, l, mm, h, crates…
            $t->smallInteger('timing_days')->nullable();               // days after planting (negative = before)
            $t->string('guidance_ref', 200)->nullable();               // the agronomic source the rate comes from
            $t->string('status', 10)->default('draft');                // draft | approved | retired
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'crop_type_id', 'status']);
        });

        // ── §3.3 Scouting ────────────────────────────────────────────────────────────────────
        Schema::table('crop_observations', function (Blueprint $t) {
            $t->decimal('affected_area_ha', 10, 4)->nullable();
            $t->decimal('incidence_pct', 5, 2)->nullable();            // share of plants / samples affected
            $t->string('recommended_action', 500)->nullable();
            $t->unsignedBigInteger('responsible_user_id')->nullable();
            $t->date('follow_up_on')->nullable();
            $t->unsignedBigInteger('treatment_activity_id')->nullable();
            $t->jsonb('ai_suggestion')->nullable();                    // {diagnoses: [{name, confidence}], caveats, model, at}
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 500)->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamp('closed_at')->nullable();
        });
        Schema::create('observation_photos', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('observation_id')->constrained('crop_observations')->cascadeOnDelete();
            $t->string('name');
            $t->string('mime', 80);
            $t->longText('data');
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
        });
        Schema::create('scouting_thresholds', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('crop_type_id')->nullable();        // null = every crop
            $t->string('kind', 30);                                    // pest | disease | weed | …
            $t->string('min_severity', 10)->default('medium');         // counted from this severity up
            $t->decimal('min_incidence_pct', 5, 2)->nullable();
            $t->unsignedSmallInteger('count', false)->default(1);       // this many observations…
            $t->unsignedSmallInteger('within_days')->default(7);        // …on one field within these days
            $t->unsignedSmallInteger('escalate_after_hours')->default(48);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::table('activity_inputs', function (Blueprint $t) {
            $t->unsignedSmallInteger('reentry_hours')->nullable();     // REI; withholding_days is the PHI
            $t->unsignedBigInteger('reversal_of_id')->nullable();       // a correction's link to what it corrects
            $t->string('correction_reason', 300)->nullable();
        });

        // ── §3.4 Irrigation and water ────────────────────────────────────────────────────────
        Schema::create('irrigation_zones', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $t->string('code', 30);
            $t->string('name', 120);
            $t->decimal('area_ha', 10, 4)->nullable();
            $t->string('system', 20)->nullable();                      // as farm_fields.irrigation
            $t->unsignedBigInteger('pump_machine_id')->nullable();
            $t->decimal('flow_m3_per_hour', 10, 3)->nullable();
            $t->decimal('water_cost_per_m3', 10, 4)->nullable();
            $t->decimal('energy_kwh_per_hour', 10, 3)->nullable();
            $t->decimal('energy_cost_per_kwh', 10, 4)->nullable();
            $t->string('meter_ref', 80)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('irrigation_events', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('zone_id')->nullable()->constrained('irrigation_zones')->nullOnDelete();
            $t->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->timestamp('planned_start')->nullable();
            $t->unsignedSmallInteger('planned_minutes')->nullable();
            $t->decimal('planned_m3', 12, 3)->nullable();
            $t->timestamp('actual_start')->nullable();
            $t->unsignedSmallInteger('actual_minutes')->nullable();
            $t->decimal('actual_m3', 12, 3)->nullable();
            $t->string('source', 10)->default('manual');               // manual | meter
            $t->decimal('pump_hours', 8, 2)->nullable();
            $t->decimal('energy_kwh', 10, 2)->nullable();
            $t->decimal('water_cost', 12, 2)->nullable();
            $t->decimal('energy_cost', 12, 2)->nullable();
            $t->string('status', 10)->default('planned');              // planned | done | skipped
            $t->string('notes', 500)->nullable();
            $t->uuid('client_id')->nullable()->unique();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['field_id', 'actual_start']);
        });
        Schema::create('soil_moisture_readings', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $t->unsignedBigInteger('zone_id')->nullable();
            $t->timestamp('read_at');
            $t->unsignedSmallInteger('depth_cm')->nullable();
            $t->decimal('vwc_pct', 5, 2);                               // volumetric water content
            $t->string('source', 10)->default('manual');               // manual | probe
            $t->string('device_ref', 80)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['field_id', 'read_at']);
        });
        Schema::create('irrigation_exceptions', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('zone_id')->nullable();
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('event_id')->nullable();
            $t->string('kind', 20);                                    // leak | fault | over_applied | under_applied | no_water | other
            $t->string('note', 500)->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->string('resolution', 500)->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
        });

        // ── §3.5 Harvest planning and yield ──────────────────────────────────────────────────
        Schema::table('crop_types', function (Blueprint $t) {
            $t->string('container_name', 30)->nullable();              // crate | bin | bag | bale
            $t->decimal('container_capacity', 10, 3)->nullable();     // in the crop's yield unit
            $t->decimal('load_capacity', 10, 3)->nullable();           // per truck load, same unit
        });
        Schema::table('harvest_records', function (Blueprint $t) {
            $t->string('lot_code', 60)->nullable();
            $t->decimal('rejected_quantity', 14, 3)->nullable();
            $t->decimal('spoiled_quantity', 14, 3)->nullable();
            $t->decimal('field_loss_quantity', 14, 3)->nullable();
            $t->unsignedInteger('containers')->nullable();
            $t->unsignedBigInteger('commitment_id')->nullable();
        });
        Schema::create('harvest_forecasts', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('crop_plan_id')->constrained('crop_plans')->cascadeOnDelete();
            $t->date('made_on');
            $t->date('ready_on')->nullable();
            $t->decimal('quantity', 14, 3);
            $t->decimal('low', 14, 3)->nullable();
            $t->decimal('high', 14, 3)->nullable();
            $t->string('unit', 12);
            $t->string('method', 20);                                  // model | manual | ai — never "measured"
            $t->jsonb('basis')->nullable();                            // what went into it, shown with it
            $t->unsignedBigInteger('made_by')->nullable();
            $t->timestamps();
        });
        Schema::create('harvest_commitments', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->unsignedBigInteger('crop_type_id');
            $t->string('buyer', 160);
            $t->unsignedBigInteger('party_id')->nullable();                 // the Accounting customer, when on record
            $t->string('channel', 30)->nullable();                     // contract | market | packhouse | export | retail | other
            $t->decimal('quantity', 14, 3);
            $t->string('unit', 12);
            $t->string('grade', 30)->nullable();
            $t->date('deliver_on');
            $t->decimal('price_per_unit', 14, 4)->nullable();
            $t->decimal('delivered_quantity', 14, 3)->default(0);
            $t->string('status', 12)->default('open');                 // open | delivered | short | cancelled
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'deliver_on']);
        });

        // ── §3.6 Overheads allocated to crops ───────────────────────────────────────────────
        Schema::create('overhead_rules', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();             // null = every farm
            $t->string('name', 120);
            $t->string('basis', 16);                                   // per_ha | pct_direct
            $t->decimal('rate', 12, 4);
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        AgriSchema::tenantRls(['crop_stage_events', 'soil_tests', 'crop_input_templates', 'observation_photos', 'scouting_thresholds',
            'irrigation_zones', 'irrigation_events', 'soil_moisture_readings', 'irrigation_exceptions', 'harvest_forecasts',
            'harvest_commitments', 'overhead_rules']);
    }

    public function down(): void
    {
        foreach (['overhead_rules', 'harvest_commitments', 'harvest_forecasts', 'irrigation_exceptions', 'soil_moisture_readings', 'irrigation_events',
            'irrigation_zones', 'scouting_thresholds', 'observation_photos', 'crop_input_templates', 'soil_tests', 'crop_stage_events'] as $x) {
            Schema::dropIfExists($x);
        }
        Schema::table('harvest_records', fn (Blueprint $t) => $t->dropColumn(['lot_code', 'rejected_quantity', 'spoiled_quantity', 'field_loss_quantity', 'containers', 'commitment_id']));
        Schema::table('crop_types', fn (Blueprint $t) => $t->dropColumn(['container_name', 'container_capacity', 'load_capacity']));
        Schema::table('activity_inputs', fn (Blueprint $t) => $t->dropColumn(['reentry_hours', 'reversal_of_id', 'correction_reason']));
        Schema::table('crop_observations', fn (Blueprint $t) => $t->dropColumn(['affected_area_ha', 'incidence_pct', 'recommended_action', 'responsible_user_id',
            'follow_up_on', 'treatment_activity_id', 'ai_suggestion', 'reviewed_by', 'reviewed_at', 'review_note', 'escalated_at', 'closed_at']));
        Schema::table('crop_plans', fn (Blueprint $t) => $t->dropColumn(['seed_lot', 'planting_density', 'density_unit', 'production_method', 'intercrop', 'current_stage']));
        Schema::table('farm_fields', fn (Blueprint $t) => $t->dropColumn(['slope_pct', 'water_source', 'parent_field_id']));
    }
};
