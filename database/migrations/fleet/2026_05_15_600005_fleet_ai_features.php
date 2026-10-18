<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── FR-4.1 Predictive maintenance predictions ─────────────────────────
        Schema::create('fleet_vehicle_predictions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->decimal('risk_score', 5, 2)->default(0);        // 0-100
            $table->date('predicted_service_date')->nullable();
            $table->jsonb('top_risk_factors')->default('[]');        // [{factor, label, contribution}]
            $table->jsonb('feature_values')->default('{}');          // raw feature snapshot
            $table->string('prediction_method', 30)->default('rule_based');
            $table->timestamp('predicted_at');
            $table->boolean('alert_sent')->default(false);
            $table->unsignedBigInteger('maintenance_job_id')->nullable(); // auto-created job card
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index(['vehicle_id', 'predicted_at']);
            $table->foreign('vehicle_id')->references('id')->on('fleet_vehicles')->cascadeOnDelete();
        });

        // ── FR-4.2 Inspection photo damage analysis ───────────────────────────
        Schema::create('fleet_inspection_analyses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inspection_id')->nullable();
            $table->unsignedBigInteger('booking_id');
            $table->string('photo_path', 500);
            $table->string('analysis_type', 20)->default('single');  // single, comparative
            $table->string('reference_photo_path', 500)->nullable(); // checkout photo for comparison
            $table->boolean('damage_detected')->default(false);
            $table->string('damage_type', 30)->nullable();            // dent, scratch, crack, multiple
            $table->decimal('confidence', 4, 3)->nullable();          // 0.000 - 1.000
            $table->jsonb('bounding_box')->nullable();                // {x,y,w,h} normalised
            $table->boolean('new_damage_detected')->nullable();       // for comparative
            $table->text('ai_description')->nullable();
            // Human-in-the-loop (FR-4.2.4)
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->boolean('is_confirmed_correct')->nullable();
            $table->string('guard_label', 30)->nullable();            // confirmed_damage, no_damage, pre_existing
            $table->timestamps();

            $table->index('booking_id');
            $table->index('inspection_id');
        });

        // ── FR-4.3 Fuel & spend anomaly flags ─────────────────────────────────
        Schema::create('fleet_fuel_anomalies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fuel_log_id')->nullable();
            $table->unsignedBigInteger('tollgate_id')->nullable();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->string('anomaly_type', 40);                       // exceeds_tank, efficiency_spike, excessive_spend, tollgate_mismatch
            $table->text('description');
            $table->string('severity', 20)->default('medium');        // low, medium, high
            $table->decimal('expected_value', 10, 2)->nullable();
            $table->decimal('actual_value', 10, 2)->nullable();
            $table->boolean('is_reviewed')->default(false);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('resolution', 30)->nullable();             // false_positive, legitimate, under_investigation
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('driver_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_fuel_anomalies');
        Schema::dropIfExists('fleet_inspection_analyses');
        Schema::dropIfExists('fleet_vehicle_predictions');
    }
};
