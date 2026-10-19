<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module B — crop planning and field activity tracking.
 *
 * Catalogues (crop types, varieties, seasons) belong to the organisation; plans, activities,
 * observations and harvests belong to a farm and are confined by farm access. Inputs used on an
 * activity are issued through the shared stock ledger, never kept as a second stock balance here:
 * activity_inputs only records which movement fed which activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_types', function (Blueprint $table) {
            AgriSchema::owned($table);
            $table->string('code', 30);
            $table->string('name', 120);
            // grain | oilseed | legume | vegetable | fruit | fodder | fibre | sugar | nut | herb | other
            $table->string('category', 20)->default('other');
            $table->string('yield_unit', 12)->default('t'); // t | kg | bales | crates | bins | l
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX crop_types_company_code_unique ON crop_types (company_id, lower(code))');

        Schema::create('crop_varieties', function (Blueprint $table) {
            AgriSchema::owned($table);
            $table->foreignId('crop_type_id')->constrained('crop_types')->restrictOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('days_to_maturity')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['crop_type_id', 'name']);
        });

        Schema::create('crop_seasons', function (Blueprint $table) {
            AgriSchema::owned($table);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 12)->default('open'); // open | closed
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX crop_seasons_company_code_unique ON crop_seasons (company_id, lower(code))');

        Schema::create('crop_plans', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $table->foreignId('season_id')->constrained('crop_seasons')->restrictOnDelete();
            $table->foreignId('crop_type_id')->constrained('crop_types')->restrictOnDelete();
            $table->foreignId('variety_id')->nullable()->constrained('crop_varieties')->nullOnDelete();
            $table->string('code', 40);
            $table->decimal('planned_area', 14, 4);
            $table->string('area_unit', 10)->default('ha');
            $table->date('planned_planting_on');
            $table->date('planned_harvest_on');
            $table->date('planted_on')->nullable();
            $table->date('harvest_completed_on')->nullable();
            $table->decimal('expected_yield', 14, 3)->nullable(); // per hectare, in the crop's unit
            $table->string('yield_unit', 12)->default('t');
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->decimal('expected_price', 14, 2)->nullable(); // per yield unit
            // draft → approved → planted → harvesting → completed; cancelled from draft/approved
            $table->string('status', 12)->default('draft');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'status']);
            $table->index(['field_id', 'season_id']);
        });
        DB::statement('CREATE UNIQUE INDEX crop_plans_farm_code_unique ON crop_plans (farm_id, lower(code))');

        Schema::create('field_activities', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('field_id')->nullable()->constrained('farm_fields')->restrictOnDelete();
            $table->foreignId('crop_plan_id')->nullable()->constrained('crop_plans')->restrictOnDelete();
            // Livestock activities (dipping a group, moving camps) reference a group added in Module C.
            $table->unsignedBigInteger('livestock_group_id')->nullable();
            // soil_preparation | planting | fertilising | spraying | irrigation | weeding | scouting |
            // harvesting | pruning | mowing | other
            $table->string('activity_type', 20);
            $table->string('title', 200);
            $table->date('planned_on');
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 12)->default('planned'); // planned | in_progress | completed | cancelled
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->decimal('area_covered', 14, 4)->nullable(); // ha
            $table->decimal('labour_hours', 10, 2)->nullable();
            $table->decimal('labour_cost', 14, 2)->nullable();
            $table->unsignedBigInteger('machine_id')->nullable(); // Module F
            $table->decimal('machine_hours', 10, 2)->nullable();
            $table->string('weather', 200)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'status', 'planned_on']);
            $table->index('crop_plan_id');
        });

        Schema::create('activity_inputs', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('activity_id')->constrained('field_activities')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_item_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->decimal('quantity', 14, 4);
            $table->string('uom', 20)->nullable();
            $table->decimal('rate_per_ha', 14, 4)->nullable();
            // Days after application before the crop may be harvested (or produce sold).
            $table->unsignedSmallInteger('withholding_days')->nullable();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('total_cost', 14, 2)->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->string('status', 12)->default('planned'); // planned | issued | reversed
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();
            $table->index(['activity_id', 'status']);
        });

        Schema::create('crop_observations', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('field_id')->nullable()->constrained('farm_fields')->restrictOnDelete();
            $table->foreignId('crop_plan_id')->nullable()->constrained('crop_plans')->restrictOnDelete();
            $table->date('observed_on');
            // pest | disease | weed | growth_stage | nutrient | water_stress | weather_damage | other
            $table->string('kind', 20);
            $table->string('severity', 10)->default('low'); // low | medium | high | critical
            $table->string('growth_stage', 60)->nullable();
            $table->text('description');
            $table->boolean('action_required')->default(false);
            $table->string('status', 12)->default('open'); // open | actioned | closed
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'observed_on']);
        });

        Schema::create('harvest_records', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('crop_plan_id')->constrained('crop_plans')->restrictOnDelete();
            $table->foreignId('field_id')->constrained('farm_fields')->restrictOnDelete();
            $table->date('harvested_on');
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 12);
            $table->string('grade', 40)->nullable();
            $table->decimal('moisture_pct', 5, 2)->nullable();
            $table->decimal('area_harvested', 14, 4)->nullable(); // ha
            // store (into a farm store as stock) | sold | own_use | loss
            $table->string('destination', 12)->default('store');
            $table->unsignedBigInteger('stock_item_id')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->decimal('unit_value', 14, 4)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable(); // the sale, when sold straight off the field
            $table->string('override_reason', 500)->nullable(); // harvesting inside a withholding period
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'harvested_on']);
        });

        AgriSchema::tenantRls(['crop_types', 'crop_varieties', 'crop_seasons', 'crop_plans', 'field_activities',
            'activity_inputs', 'crop_observations', 'harvest_records']);
    }

    public function down(): void
    {
        foreach (['harvest_records', 'crop_observations', 'activity_inputs', 'field_activities', 'crop_plans',
            'crop_seasons', 'crop_varieties', 'crop_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
