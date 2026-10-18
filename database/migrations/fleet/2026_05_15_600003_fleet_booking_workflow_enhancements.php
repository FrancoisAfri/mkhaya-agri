<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Extra fields on fleet_bookings ────────────────────────────────────
        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->string('usage_type', 30)->default('official')->after('purpose');   // official,local,personal,business
            $table->boolean('is_local_trip')->default(false)->after('usage_type');
            $table->string('project', 100)->nullable()->after('is_local_trip');
            $table->unsignedSmallInteger('passenger_count')->default(1)->after('project');
            $table->string('supporting_document_path', 500)->nullable()->after('passenger_count');
            // Vehicle snapshot (FR-2.2.4)
            $table->string('vehicle_make_snapshot', 60)->nullable();
            $table->string('vehicle_model_snapshot', 60)->nullable();
            $table->smallInteger('vehicle_year_snapshot')->nullable();
            $table->string('vehicle_reg_snapshot', 20)->nullable();
            // Collection (FR-3.4)
            $table->string('collection_location', 300)->nullable();
            $table->string('collection_document_path', 500)->nullable();
            $table->string('collection_image_path', 500)->nullable();
            $table->timestampTz('collected_at')->nullable();
            $table->unsignedBigInteger('collected_by')->nullable();
        });

        // ── can_drive flag on fleet_drivers ───────────────────────────────────
        Schema::table('fleet_drivers', function (Blueprint $table) {
            $table->boolean('can_drive')->default(true)->after('status');
        });

        // ── N-level approval config ────────────────────────────────────────────
        Schema::create('fleet_booking_approval_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('step_number');
            $table->string('approver_type', 20);         // role, employee, line_manager
            $table->string('role_name', 60)->nullable();
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->boolean('check_local_trip')->default(false); // skip this step for local trips
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ── Per-booking approval records ──────────────────────────────────────
        Schema::create('fleet_booking_approvals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('config_step_id')->nullable();
            $table->unsignedSmallInteger('step_number');
            $table->unsignedBigInteger('approver_id')->nullable();  // resolved at runtime
            $table->string('status', 20)->default('pending');        // pending,approved,rejected,skipped
            $table->text('comments')->nullable();
            $table->timestamp('action_at')->nullable();
            $table->timestamps();

            $table->index('booking_id');
            $table->index(['booking_id', 'status']);
            $table->foreign('booking_id')->references('id')->on('fleet_bookings')->cascadeOnDelete();
        });

        // ── Signed email-approval tokens (FR-3.3.5) ───────────────────────────
        Schema::create('fleet_booking_approval_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('approval_id');
            $table->string('token', 64)->unique();
            $table->string('action', 20);                // approved, rejected
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index('token');
            $table->foreign('booking_id')->references('id')->on('fleet_bookings')->cascadeOnDelete();
        });

        // ── Tollgate transactions (FR-3.5.2) ─────────────────────────────────
        Schema::create('fleet_booking_tollgates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->decimal('amount', 10, 2);
            $table->string('toll_location', 200)->nullable();
            $table->timestamp('toll_at');
            $table->string('receipt_path', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('booking_id');
            $table->foreign('booking_id')->references('id')->on('fleet_bookings')->cascadeOnDelete();
        });

        // ── Incident reports (FR-3.5.3) ───────────────────────────────────────
        Schema::create('fleet_booking_incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->string('incident_type', 30);         // accident,breakdown,theft,damage,other
            $table->string('severity', 20)->default('medium'); // low,medium,high,critical
            $table->text('description');
            $table->timestamp('incident_at');
            $table->string('location', 200)->nullable();
            $table->string('police_case_number', 60)->nullable();
            $table->jsonb('evidence_paths')->default('[]');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('booking_id');
            $table->foreign('booking_id')->references('id')->on('fleet_bookings')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_booking_incidents');
        Schema::dropIfExists('fleet_booking_tollgates');
        Schema::dropIfExists('fleet_booking_approval_tokens');
        Schema::dropIfExists('fleet_booking_approvals');
        Schema::dropIfExists('fleet_booking_approval_configs');

        Schema::table('fleet_drivers', function (Blueprint $table) {
            $table->dropColumn('can_drive');
        });

        Schema::table('fleet_bookings', function (Blueprint $table) {
            $table->dropColumn([
                'usage_type', 'is_local_trip', 'project', 'passenger_count',
                'supporting_document_path', 'vehicle_make_snapshot', 'vehicle_model_snapshot',
                'vehicle_year_snapshot', 'vehicle_reg_snapshot',
                'collection_location', 'collection_document_path',
                'collection_image_path', 'collected_at', 'collected_by',
            ]);
        });
    }
};
