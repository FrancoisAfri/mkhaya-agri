<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Vehicles ──────────────────────────────────────────────────────────
        Schema::create('fleet_vehicles', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('registration_number', 20)->unique();
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('vin_number', 30)->unique()->nullable();
            $table->string('engine_number', 30)->nullable();
            $table->string('colour', 30)->nullable();
            $table->string('vehicle_type', 30)->default('sedan'); // sedan,suv,truck,van,motorcycle,bus,other
            $table->string('fuel_type', 20)->default('petrol');   // petrol,diesel,hybrid,electric,lpg
            $table->string('transmission', 20)->default('automatic');
            $table->decimal('engine_capacity', 5, 1)->nullable();
            $table->smallInteger('seating_capacity')->default(5);
            $table->decimal('gvm', 10, 2)->nullable();
            $table->decimal('odometer_reading', 10, 1)->default(0);
            $table->decimal('hours_reading', 10, 1)->default(0);
            $table->string('status', 30)->default('available'); // available,booked,maintenance,offroad,disposed
            $table->string('location', 100)->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('assigned_driver_id')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 14, 2)->nullable();
            $table->decimal('current_value', 14, 2)->nullable();
            $table->date('disposal_date')->nullable();
            $table->text('disposal_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('department_id');
            $table->index('assigned_driver_id');
        });

        // ── Odometer history ──────────────────────────────────────────────────
        Schema::create('fleet_odometer_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->decimal('reading', 10, 1)->notNull();
            $table->date('reading_date');
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('source', 30)->default('manual'); // manual,booking,fuel_log
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('reading_date');
        });

        // ── Drivers ───────────────────────────────────────────────────────────
        Schema::create('fleet_drivers', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('licence_number', 30)->nullable();
            $table->string('licence_type', 20)->default('C1'); // A,B,C,C1,EB,PrDP
            $table->date('licence_expiry')->nullable();
            $table->string('pdp_number', 30)->nullable();
            $table->date('pdp_expiry')->nullable();
            $table->string('status', 20)->default('active'); // active,suspended,inactive
            $table->text('restriction_reason')->nullable();
            $table->unsignedInteger('total_trips')->default(0);
            $table->decimal('total_km', 12, 1)->default(0);
            $table->unsignedInteger('incidents_count')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('licence_expiry');
        });

        // ── Bookings ──────────────────────────────────────────────────────────
        Schema::create('fleet_bookings', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('reference', 30)->unique();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('driver_id');
            $table->unsignedBigInteger('requester_id');
            $table->text('purpose');
            $table->string('destination', 200)->nullable();
            $table->timestampTz('departure_at');
            $table->timestampTz('expected_return_at');
            $table->timestampTz('actual_return_at')->nullable();
            $table->decimal('start_odometer', 10, 1)->nullable();
            $table->decimal('end_odometer', 10, 1)->nullable();
            $table->decimal('km_travelled', 10, 1)->nullable();
            $table->string('workflow_state', 30)->default('pending'); // pending,security_check,approved,in_use,returned,cancelled
            $table->unsignedBigInteger('current_step_id')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('security_checked_by')->nullable();
            $table->timestampTz('security_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('driver_id');
            $table->index('requester_id');
            $table->index('workflow_state');
            $table->index('departure_at');
        });

        // ── Booking inspections (renamed from vehicle_booking_scrath_inspection) ──
        Schema::create('fleet_booking_inspections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->string('inspection_type', 20); // pre_trip,post_trip
            $table->unsignedBigInteger('inspector_id');
            $table->timestampTz('inspected_at')->useCurrent();
            $table->decimal('odometer_reading', 10, 1)->nullable();
            $table->string('fuel_level', 20)->nullable(); // empty,quarter,half,three_quarter,full
            $table->string('condition_overall', 20)->default('good'); // excellent,good,fair,poor
            $table->jsonb('damage_markers')->default('[]');
            $table->boolean('lights_ok')->default(true);
            $table->boolean('tyres_ok')->default(true);
            $table->boolean('brakes_ok')->default(true);
            $table->boolean('wipers_ok')->default(true);
            $table->boolean('fluids_ok')->default(true);
            $table->boolean('first_aid_ok')->default(true);
            $table->boolean('fire_ext_ok')->default(true);
            $table->boolean('spare_tyre_ok')->default(true);
            $table->boolean('interior_ok')->default(true);
            $table->text('notes')->nullable();
            $table->string('signature_path', 500)->nullable();
            $table->jsonb('photos')->default('[]');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('booking_id');
            $table->index('vehicle_id');
            $table->index('inspection_type');
        });

        // ── Fleet cards (PCI DSS compliant — no CVS, last 4 digits only) ─────
        Schema::create('fleet_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->char('card_number_last4', 4);
            $table->string('card_token', 100)->unique();
            $table->string('card_type', 30)->default('fuel'); // fuel,toll,multi
            $table->string('provider', 60)->nullable();        // Shell,BP,Sasol,Engen
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedSmallInteger('expiry_month')->nullable();
            $table->unsignedSmallInteger('expiry_year')->nullable();
            $table->string('status', 20)->default('active'); // active,blocked,expired,cancelled
            $table->decimal('daily_limit', 12, 2)->nullable();
            $table->decimal('monthly_limit', 12, 2)->nullable();
            $table->decimal('current_month_spend', 12, 2)->default(0);
            $table->boolean('pin_set')->default(false);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('driver_id');
            $table->index('status');
        });

        // ── Card transactions ────────────────────────────────────────────────
        Schema::create('fleet_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fleet_card_id');
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->string('transaction_type', 30)->default('fuel'); // fuel,toll,oil,service,other
            $table->timestampTz('transaction_at');
            $table->string('merchant_name', 100)->nullable();
            $table->string('merchant_city', 60)->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('litres', 10, 3)->nullable();
            $table->decimal('price_per_litre', 8, 4)->nullable();
            $table->decimal('odometer_reading', 10, 1)->nullable();
            $table->string('fuel_type', 20)->nullable();
            $table->string('reference', 60)->nullable();
            $table->boolean('is_flagged')->default(false);
            $table->text('flag_reason')->nullable();
            $table->boolean('reconciled')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index('fleet_card_id');
            $table->index('vehicle_id');
            $table->index('transaction_at');
            $table->index('is_flagged');
        });

        // ── Vehicle documents (renamed from vehicle_documets) ────────────────
        Schema::create('fleet_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('document_type', 60); // licence_disk,roadworthy,insurance,permit,registration,other
            $table->string('document_number', 60)->nullable();
            $table->date('issued_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('issuing_authority', 100)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('status', 20)->default('valid'); // valid,expired,pending_renewal,revoked
            $table->unsignedInteger('renewal_reminder_days')->default(30);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('document_type');
            $table->index('expiry_date');
            $table->index('status');
        });

        // ── Maintenance ───────────────────────────────────────────────────────
        Schema::create('fleet_maintenance', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('maintenance_type', 60); // scheduled,unscheduled,emergency,inspection
            $table->string('category', 60)->nullable(); // oil_change,tyres,brakes,electrical,bodywork,other
            $table->text('description');
            $table->date('scheduled_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->decimal('odometer_at_service', 10, 1)->nullable();
            $table->decimal('next_service_km', 10, 1)->nullable();
            $table->date('next_service_date')->nullable();
            $table->string('service_provider', 100)->nullable();
            $table->string('service_provider_contact', 60)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('labour_cost', 12, 2)->nullable();
            $table->decimal('parts_cost', 12, 2)->nullable();
            $table->string('invoice_number', 60)->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled,in_progress,completed,cancelled
            $table->string('priority', 20)->default('normal'); // low,normal,high,emergency
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('status');
            $table->index('scheduled_date');
            $table->index('priority');
        });

        // ── Fuel logs ────────────────────────────────────────────────────────
        Schema::create('fleet_fuel_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('fleet_card_id')->nullable();
            $table->timestampTz('filled_at');
            $table->string('fuel_type', 20)->default('petrol');
            $table->decimal('litres', 10, 3);
            $table->decimal('cost_per_litre', 8, 4)->nullable();
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->decimal('odometer_reading', 10, 1)->nullable();
            $table->string('station_name', 100)->nullable();
            $table->string('station_city', 60)->nullable();
            $table->boolean('full_tank')->default(true);
            $table->decimal('consumption_per_100km', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('vehicle_id');
            $table->index('driver_id');
            $table->index('filled_at');
        });

        // ── Notes (UNIQUE on vehicle_id + notes per FR-1.1.10) ────────────────
        Schema::create('fleet_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vehicle_id');
            $table->string('note_type', 30)->default('general'); // general,incident,alert,maintenance
            $table->text('notes');
            $table->string('severity', 20)->default('info'); // info,warning,critical
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_id', 'notes']);
            $table->index('vehicle_id');
            $table->index('severity');
        });

        // ── Settings ─────────────────────────────────────────────────────────
        Schema::create('fleet_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('cast', 20)->default('string');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_settings');
        Schema::dropIfExists('fleet_notes');
        Schema::dropIfExists('fleet_fuel_logs');
        Schema::dropIfExists('fleet_maintenance');
        Schema::dropIfExists('fleet_documents');
        Schema::dropIfExists('fleet_card_transactions');
        Schema::dropIfExists('fleet_cards');
        Schema::dropIfExists('fleet_booking_inspections');
        Schema::dropIfExists('fleet_bookings');
        Schema::dropIfExists('fleet_drivers');
        Schema::dropIfExists('fleet_odometer_logs');
        Schema::dropIfExists('fleet_vehicles');
    }
};
