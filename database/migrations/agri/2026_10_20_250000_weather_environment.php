<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §11: weather from approved providers, kept per farm (or field) with its source, the time
 * it was fetched and the period it covers (§11.1); operational weather rules a manager configures
 * and someone approves — per crop, chemical or species, never one threshold for everything (§11.2);
 * and environmental records with a sustainability report that never claims what it cannot prove
 * (§11.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_weather', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('field_id')->nullable();
            $t->string('provider', 40);
            $t->string('kind', 12);                                          // current | forecast | observed
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->timestamp('fetched_at');
            $t->decimal('temperature', 5, 2)->nullable();
            $t->decimal('temp_min', 5, 2)->nullable();
            $t->decimal('temp_max', 5, 2)->nullable();
            $t->decimal('humidity', 5, 2)->nullable();
            $t->decimal('wind_speed', 6, 2)->nullable();                     // km/h
            $t->decimal('wind_gust', 6, 2)->nullable();
            $t->decimal('rain_mm', 7, 2)->nullable();
            $t->unsignedTinyInteger('rain_probability')->nullable();         // %
            $t->string('conditions', 80)->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();
            $t->unique(['farm_id', 'field_id', 'provider', 'kind', 'period_start']);
            $t->index(['farm_id', 'kind', 'period_start']);
        });

        Schema::create('agri_weather_warnings', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('provider', 40);
            $t->string('external_id', 120)->nullable();
            $t->string('kind', 12);                                          // frost | heat | storm | wind | rain | severe | other
            $t->string('severity', 10);                                      // advisory | watch | warning
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->string('message', 1000);
            $t->timestamp('fetched_at');
            $t->timestamps();
            $t->unique(['farm_id', 'provider', 'external_id']);
        });

        Schema::create('agri_weather_rules', function (Blueprint $t) {
            AgriSchema::owned($t);
            $t->unsignedBigInteger('farm_id')->nullable();                   // null: every farm
            $t->string('name', 160);
            $t->string('activity', 20);                                      // spraying | frost | irrigation | harvest_access | worker_heat | livestock_heat
            $t->unsignedBigInteger('crop_type_id')->nullable();
            $t->unsignedBigInteger('stock_item_id')->nullable();             // the chemical the rule is for
            $t->unsignedBigInteger('species_id')->nullable();
            $t->json('conditions');                                          // [{metric, op, value}] — any one met triggers
            $t->unsignedSmallInteger('lookahead_hours')->default(48);
            $t->string('advice', 500);                                       // what to do, in the farm's words
            $t->string('basis', 300);                                        // where the threshold comes from (label, agronomist, procedure)
            $t->string('severity', 10)->default('warning');
            $t->string('status', 10)->default('draft');                      // draft | approved | retired
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('agri_environment_records', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->unsignedBigInteger('field_id')->nullable();
            $t->string('kind', 24);                                          // water | energy | fuel | soil | erosion | waste | container_disposal | rehabilitation | biodiversity | carbon_project
            $t->date('recorded_on');
            $t->decimal('quantity', 14, 3)->nullable();
            $t->string('unit', 16)->nullable();
            $t->string('method', 120)->nullable();                           // meter, invoice, estimate, survey…
            $t->string('description', 1000);
            $t->json('details')->nullable();                                 // e.g. containers: {count, triple_rinsed, collector, certificate}
            $t->string('verification', 12)->default('unverified');           // unverified | verified (only with methodology, verifier and evidence)
            $t->string('methodology', 200)->nullable();
            $t->string('verifier', 160)->nullable();
            $t->string('evidence_name', 200)->nullable();
            $t->string('evidence_mime', 100)->nullable();
            $t->longText('evidence_data')->nullable();                       // base64
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['farm_id', 'kind', 'recorded_on']);
        });

        AgriSchema::tenantRls(['agri_weather', 'agri_weather_warnings', 'agri_weather_rules', 'agri_environment_records']);
    }

    public function down(): void
    {
        foreach (['agri_environment_records', 'agri_weather_rules', 'agri_weather_warnings', 'agri_weather'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
