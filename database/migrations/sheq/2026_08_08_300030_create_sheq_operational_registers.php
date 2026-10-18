<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PPE, medical surveillance, environmental monitoring, safety equipment, visitors and emergency
 * preparedness.
 *
 * Six registers in one migration because they share a shape — a catalogue, an issue or reading, and
 * a due date that someone must act on — and splitting them would produce six near-identical files.
 *
 * Two carry more weight than the rest:
 *
 * **Medical surveillance is health data.** Audiometry results, lung function, vision — this is
 * POPIA special personal information about identifiable people, the same category as injury detail.
 * The columns holding results are text rather than sized varchars because they are encrypted at
 * rest, and the schema deliberately stores a *fitness outcome* separately from the clinical detail:
 * a supervisor needs to know somebody is fit for the task, and has no business seeing their
 * audiogram.
 *
 * **Environmental readings are what the "E" in SHEQ means.** Water, electricity, fuel, waste,
 * emissions. Stored as meter readings rather than computed totals, because a carbon figure whose
 * inputs cannot be produced is not auditable — and the whole point of recording this is that
 * somebody will eventually be asked to prove it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── PPE ───────────────────────────────────────────────────────────────────────────────
        Schema::create('sheq_ppe_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('category', 40)->nullable();
            $table->string('unit', 20)->default('EACH');

            /** The standard it must meet — SANS 20345 for safety footwear, EN 397 for helmets. */
            $table->string('specification', 160)->nullable();

            /**
             * Months of service life once issued.
             *
             * A helmet has a shelf life whether or not it is ever struck, and "it looks fine" is
             * not a competent assessment of a five-year-old polymer shell.
             */
            $table->unsignedInteger('lifespan_months')->nullable();

            $table->boolean('requires_size')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'sheq_ppe_items_code_unique');
        });

        Schema::create('sheq_ppe_stock', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('ppe_item_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('size', 20)->nullable();
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('reorder_level')->default(0);
            $table->string('barcode', 80)->nullable()->index();

            $table->timestamps();
        });

        Schema::create('sheq_ppe_issues', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->unsignedBigInteger('ppe_item_id')->index();
            $table->unsignedBigInteger('hr_person_id')->nullable()->index();
            $table->unsignedBigInteger('contractor_worker_id')->nullable();

            $table->string('size', 20)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('serial_number', 80)->nullable();

            $table->date('issued_on');
            $table->date('replace_due')->nullable();
            $table->date('returned_on')->nullable();

            /** ISSUED, RETURNED, LOST, DAMAGED, EXPIRED. */
            $table->string('status', 20)->default('ISSUED')->index();

            /**
             * The recipient's signature.
             *
             * Section 8 requires the employer to provide PPE; the signature is the employer's
             * evidence that they did, which is the first thing asked for after an injury that PPE
             * would have prevented.
             */
            $table->longText('signature')->nullable();

            $table->unsignedBigInteger('issued_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Medical surveillance ──────────────────────────────────────────────────────────────
        Schema::create('sheq_medical_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('code', 40);
            $table->string('name', 160);

            /** AUDIOMETRY, SPIROMETRY, VISION, DRUG_SCREEN, GENERAL, EXIT. */
            $table->string('examination_type', 30);

            $table->unsignedInteger('frequency_months')->default(12);

            /** The regulation requiring it — Noise-Induced Hearing Loss Regulations, HCS Regs. */
            $table->string('regulatory_reference', 160)->nullable();

            /** Whether an unfit result stops the person working. */
            $table->boolean('blocks_work_when_unfit')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'sheq_medical_types_code_unique');
        });

        Schema::create('sheq_medical_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();

            $table->unsignedBigInteger('medical_type_id')->index();
            $table->unsignedBigInteger('hr_person_id')->index();

            $table->date('examined_on');
            $table->date('expires_on')->nullable();

            /**
             * The outcome a supervisor may see: FIT, FIT_WITH_RESTRICTION, TEMPORARILY_UNFIT, UNFIT.
             *
             * Separated from the clinical detail below on purpose. A line manager needs to know
             * whether somebody may do the task; they have no business reading the audiogram, and
             * putting both in one field would force the system to disclose the second to convey
             * the first.
             */
            $table->string('fitness_outcome', 30)->index();
            $table->text('restrictions')->nullable();

            /**
             * Clinical findings. Encrypted at rest — POPIA s26 special personal information.
             * Text rather than varchar because ciphertext is several times longer than the value.
             */
            $table->text('clinical_findings')->nullable();
            $table->text('practitioner_name')->nullable();
            $table->string('practitioner_number', 60)->nullable();

            $table->unsignedBigInteger('file_id')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // ── Environmental ─────────────────────────────────────────────────────────────────────
        Schema::create('sheq_environmental_meters', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('code', 40);
            $table->string('name', 160);

            /** WATER, ELECTRICITY, DIESEL, PETROL, GAS, WASTE, RECYCLING, EMISSIONS. */
            $table->string('aspect', 30)->index();
            $table->string('unit', 20);

            /**
             * kg CO2e per unit, for the carbon figure.
             *
             * Stored per meter rather than as a global constant because the factor differs by fuel
             * and by grid, and it changes when Eskom's mix changes — a historical reading must
             * keep the factor it was computed under or last year's total silently moves.
             */
            $table->decimal('carbon_factor', 12, 6)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'sheq_env_meters_code_unique');
        });

        Schema::create('sheq_environmental_readings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('meter_id')->index();

            $table->date('reading_date')->index();
            $table->decimal('value', 16, 4);

            /** The factor applied at the time, so a recomputation is reproducible. */
            $table->decimal('carbon_factor_applied', 12, 6)->nullable();
            $table->decimal('co2e_kg', 16, 4)->nullable();

            $table->string('source', 30)->default('MANUAL');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();

            // One reading per meter per day: a duplicate would double a month's consumption.
            $table->unique(['meter_id', 'reading_date'], 'sheq_env_reading_unique');
        });

        // ── Safety equipment register ─────────────────────────────────────────────────────────
        Schema::create('sheq_equipment', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('asset_tag', 60);
            $table->string('name', 160);

            /** FIRE_EXTINGUISHER, LADDER, HARNESS, LIFTING_GEAR, GENERATOR, COMPRESSOR, FIRST_AID. */
            $table->string('equipment_type', 40)->index();

            $table->string('location_text', 200)->nullable();
            $table->string('serial_number', 80)->nullable();
            $table->string('qr_code', 80)->nullable()->unique();

            /** The platform asset record, where the company also tracks it financially. */
            $table->unsignedBigInteger('asset_id')->nullable();

            $table->date('commissioned_on')->nullable();
            $table->unsignedInteger('inspection_frequency_days')->nullable();
            $table->date('next_inspection_due')->nullable()->index();
            $table->date('certificate_expires_on')->nullable();

            /** IN_SERVICE, OUT_OF_SERVICE, CONDEMNED, MISSING. */
            $table->string('status', 20)->default('IN_SERVICE')->index();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'asset_tag'], 'sheq_equipment_tag_unique');
        });

        // ── Visitors ──────────────────────────────────────────────────────────────────────────
        Schema::create('sheq_visits', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('reference', 40);

            $table->string('visitor_name', 160);
            $table->string('visitor_company', 160)->nullable();
            $table->string('visitor_contact', 60)->nullable();
            $table->string('vehicle_registration', 30)->nullable();

            /** The employee responsible for them while on site. */
            $table->unsignedBigInteger('host_user_id')->nullable()->index();

            $table->text('purpose')->nullable();
            $table->timestamp('expected_from')->nullable();
            $table->timestamp('expected_to')->nullable();

            /**
             * REQUESTED, APPROVED, REJECTED, INDUCTED, ON_SITE, SIGNED_OUT, EXPIRED.
             *
             * Induction sits between approval and entry deliberately: an approved visitor who has
             * not been inducted does not know where the assembly point is, and a visitor is the
             * person least likely to survive an evacuation they were never briefed on.
             */
            $table->string('status', 20)->default('REQUESTED')->index();

            $table->timestamp('induction_completed_at')->nullable();
            $table->longText('induction_signature')->nullable();
            $table->boolean('nda_accepted')->default(false);
            $table->jsonb('ppe_issued')->nullable();

            $table->string('badge_code', 60)->nullable()->unique();

            $table->timestamp('signed_in_at')->nullable();
            $table->timestamp('signed_out_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        // ── Emergency preparedness ────────────────────────────────────────────────────────────
        Schema::create('sheq_emergency_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->string('name', 160);
            /** FIRE, MEDICAL, CHEMICAL_SPILL, BOMB_THREAT, STRUCTURAL, FLOOD, POWER. */
            $table->string('emergency_type', 30)->index();

            $table->text('procedure')->nullable();
            $table->jsonb('assembly_points')->nullable();
            $table->jsonb('emergency_contacts')->nullable();
            $table->jsonb('incident_command')->nullable();

            $table->unsignedBigInteger('document_id')->nullable();
            $table->date('review_due')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sheq_emergency_drills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('plan_id')->nullable()->index();
            $table->unsignedBigInteger('site_id')->nullable()->index();

            $table->date('drill_date');
            $table->string('drill_type', 30);

            /**
             * How long the evacuation took, and how many were unaccounted for.
             *
             * The second number is the one that matters. A four-minute evacuation with two people
             * missing is a failed drill, and recording only the time would let it read as a pass.
             */
            $table->unsignedInteger('evacuation_seconds')->nullable();
            $table->unsignedInteger('participants')->nullable();
            $table->unsignedInteger('unaccounted')->nullable();

            $table->text('observations')->nullable();
            $table->text('improvements')->nullable();
            $table->unsignedBigInteger('action_id')->nullable();

            $table->unsignedBigInteger('conducted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

            foreach (['sheq_ppe_items', 'sheq_ppe_stock', 'sheq_ppe_issues',
                'sheq_medical_types', 'sheq_medical_records',
                'sheq_environmental_meters', 'sheq_environmental_readings',
                'sheq_equipment', 'sheq_visits',
                'sheq_emergency_plans', 'sheq_emergency_drills'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
                DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
            }
        }
    }

    public function down(): void
    {
        foreach (['sheq_emergency_drills', 'sheq_emergency_plans', 'sheq_visits', 'sheq_equipment',
            'sheq_environmental_readings', 'sheq_environmental_meters',
            'sheq_medical_records', 'sheq_medical_types',
            'sheq_ppe_issues', 'sheq_ppe_stock', 'sheq_ppe_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
