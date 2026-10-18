<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAssetRiskProfilesTable extends Migration
{
    public function up(): void
    {
        Schema::create('asset_risk_profiles', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('asset_id')->unique();
            $table->unsignedInteger('digital_twin_id')->nullable();
            $table->float('risk_score')->default(0)->index();
            $table->string('risk_level', 20)->default('low')->index();
            $table->float('age_score')->default(0);
            $table->float('condition_score')->default(0);
            $table->float('maintenance_score')->default(0);
            $table->float('incident_score')->default(0);
            $table->float('utilization_score')->default(0);
            $table->json('risk_factors')->nullable();
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();

            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_risk_profiles');
    }
}
