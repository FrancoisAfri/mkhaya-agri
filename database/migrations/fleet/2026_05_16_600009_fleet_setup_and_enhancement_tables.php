<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Vehicle makes ──────────────────────────────────────────────────────
        Schema::create('fleet_vehicle_makes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // ── Vehicle models (linked to make) ────────────────────────────────────
        Schema::create('fleet_vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('make_id')->constrained('fleet_vehicle_makes')->cascadeOnDelete();
            $table->string('name', 60);
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['make_id', 'name']);
            $table->index('make_id');
        });

        // ── Vehicle types ──────────────────────────────────────────────────────
        Schema::create('fleet_vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── Fuel types ─────────────────────────────────────────────────────────
        Schema::create('fleet_fuel_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── Incident types ─────────────────────────────────────────────────────
        Schema::create('fleet_incident_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // ── Key safes ──────────────────────────────────────────────────────────
        Schema::create('fleet_key_safes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('location_description', 200)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // ── Projects (linked to company units) ────────────────────────────────
        Schema::create('fleet_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('description', 300)->nullable();
            $table->unsignedBigInteger('company_unit_id')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index('company_unit_id');
        });

        // ── Extend fleet_vehicles: FK references to lookup tables + company unit
        Schema::table('fleet_vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('make_id')->nullable()->after('fleet_number');
            $table->unsignedBigInteger('model_id')->nullable()->after('make_id');
            $table->unsignedBigInteger('vehicle_type_id')->nullable()->after('colour');
            $table->unsignedBigInteger('company_unit_id')->nullable()->after('department_id');
            $table->string('key_safe_name', 80)->nullable()->after('location');

            $table->foreign('make_id')->references('id')->on('fleet_vehicle_makes')->nullOnDelete();
            $table->foreign('model_id')->references('id')->on('fleet_vehicle_models')->nullOnDelete();
            $table->foreign('company_unit_id')->references('id')->on('company_units')->nullOnDelete();
        });

        // ── Extend fleet_bookings: project FK ─────────────────────────────────
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('project');
            $table->foreign('project_id')->references('id')->on('fleet_projects')->nullOnDelete();
        });

        // ── Extend fleet_booking_approval_configs: add department/unit fields ─
        Schema::table('fleet_booking_approval_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('company_unit_id')->nullable()->after('role_name');
            $table->string('step_label', 100)->nullable()->after('step_number');
        });

        // ── Extend hr_people: eligible_to_drive ────────────────────────────────
        Schema::table('hr_people', function (Blueprint $table) {
            $table->boolean('eligible_to_drive')->default(false)->after('drivers_licence_code');
        });
    }

    public function down(): void
    {
        Schema::table('hr_people', fn (Blueprint $t) => $t->dropColumn('eligible_to_drive'));
        Schema::table('fleet_booking_approval_configs', fn (Blueprint $t) => $t->dropColumn(['company_unit_id', 'step_label']));
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
        Schema::table('fleet_vehicles', function (Blueprint $table) {
            $table->dropForeign(['make_id']);
            $table->dropForeign(['model_id']);
            $table->dropForeign(['company_unit_id']);
            $table->dropColumn(['make_id', 'model_id', 'vehicle_type_id', 'company_unit_id', 'key_safe_name']);
        });
        Schema::dropIfExists('fleet_projects');
        Schema::dropIfExists('fleet_key_safes');
        Schema::dropIfExists('fleet_incident_types');
        Schema::dropIfExists('fleet_fuel_types');
        Schema::dropIfExists('fleet_vehicle_types');
        Schema::dropIfExists('fleet_vehicle_models');
        Schema::dropIfExists('fleet_vehicle_makes');
    }
};
