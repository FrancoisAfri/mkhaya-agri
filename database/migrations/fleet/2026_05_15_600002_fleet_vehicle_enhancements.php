<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Add missing columns to fleet_vehicles ─────────────────────────────
        Schema::table('fleet_vehicles', function (Blueprint $table) {
            $table->string('fleet_number', 20)->nullable()->unique()->after('registration_number');
            $table->string('chassis_number', 30)->nullable()->unique()->after('vin_number');
            $table->date('expected_disposal_date')->nullable()->after('disposal_reason');
            $table->decimal('co2_emissions_g_per_km', 8, 2)->nullable()->after('expected_disposal_date');
            $table->string('disposal_type', 30)->nullable()->after('disposal_reason'); // sold,written_off,transferred
            $table->string('status_reason', 255)->nullable()->after('status');         // reason for last status change
            $table->string('registration_document_path', 500)->nullable();
            $table->string('approval_status', 20)->default('approved');                // pending,approved,rejected
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
        });

        // ── Vehicle images ─────────────────────────────────────────────────────
        Schema::create('fleet_vehicle_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->unsignedInteger('file_size')->nullable();    // bytes
            $table->string('mime_type', 50)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->foreign('vehicle_id')->references('id')->on('fleet_vehicles')->cascadeOnDelete();
        });

        // ── Vehicle status change history ─────────────────────────────────────
        Schema::create('fleet_vehicle_status_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->foreign('vehicle_id')->references('id')->on('fleet_vehicles')->cascadeOnDelete();
        });

        // ── Vehicle field-level change log ─────────────────────────────────────
        Schema::create('fleet_vehicle_change_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('field_name', 60);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('field_name');
            $table->foreign('vehicle_id')->references('id')->on('fleet_vehicles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_vehicle_change_logs');
        Schema::dropIfExists('fleet_vehicle_status_changes');
        Schema::dropIfExists('fleet_vehicle_images');

        Schema::table('fleet_vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'fleet_number', 'chassis_number', 'expected_disposal_date',
                'co2_emissions_g_per_km', 'disposal_type', 'status_reason',
                'registration_document_path', 'approval_status', 'approved_by', 'approved_at',
            ]);
        });
    }
};
