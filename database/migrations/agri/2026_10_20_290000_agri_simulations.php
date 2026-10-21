<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §16E: the farm decision simulator — a production plan and its alternatives (planting
 * date, variety, input costs, labour, water, price), compared on yield, cash flow, risk and profit
 * before one is turned into a crop plan for approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agri_simulations', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('name', 160);
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('crop_type_id');
            $t->json('base');                                                // the plan as first proposed
            $t->json('scenarios');                                           // [{name, changes}]
            $t->json('results')->nullable();
            $t->unsignedBigInteger('crop_plan_id')->nullable();              // the plan the chosen scenario became
            $t->string('chosen', 120)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        AgriSchema::tenantRls(['agri_simulations']);
    }

    public function down(): void
    {
        Schema::dropIfExists('agri_simulations');
    }
};
