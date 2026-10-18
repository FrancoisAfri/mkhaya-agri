<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Environmental management (ENV-1 to ENV-7).
 *
 * The module could already read a meter. It could not say what the site's environmental aspects
 * were, where a skip went, or that forty litres of hydraulic oil reached a stormwater drain on
 * Tuesday — which is the one environmental record that has a statutory reporting clock attached
 * to it in South Africa.
 *
 * That last point shapes the whole section. Environmental non-compliance is reported to a
 * REGULATOR rather than to a head office: NEMA section 30 obliges the responsible person to
 * notify the authorities of an incident causing significant pollution, and the Waste Act requires
 * a manifest to accompany hazardous waste off site with the generator liable if it does not
 * arrive. A system that records these as ordinary observations has recorded the wrong thing.
 *
 * Three tables, and one deliberate absence:
 *
 * **Aspects and impacts (ENV-1)** is the register ISO 14001 is built around — the thing an
 * auditor asks for first and the thing that decides which of everything else matters.
 *
 * **Waste manifests (ENV-3)** because the chain of custody is the legal artefact, not the
 * tonnage.
 *
 * **Spills (ENV-4)** because a spill is not an incident with a different label: it has a
 * substance, a quantity, a containment, a clean-up and a notification deadline.
 *
 * Thresholds and alerts (ENV-5) get no table: `sheq_kpi_thresholds` already exists and is what
 * the KPI engine reads, so an environmental threshold is a row in it rather than a parallel
 * mechanism that would need its own sweep, its own alerting and its own screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        /** ENV-1. */
        Schema::create('sheq_environmental_aspects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference', 40);

            /** The activity that interacts with the environment. */
            $table->string('activity', 255);

            /** What it does to it: dust emission, effluent discharge, waste generation. */
            $table->string('aspect', 255);

            /** And the consequence: air quality, groundwater contamination, habitat loss. */
            $table->string('impact', 255);

            /** AIR, WATER, LAND, WASTE, NOISE, ENERGY, BIODIVERSITY or RESOURCE. */
            $table->string('medium', 30)->nullable();

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /**
             * NORMAL, ABNORMAL or EMERGENCY.
             *
             * ISO 14001 requires all three to be considered, and the emergency condition is the
             * one that gets left out — a tank that is harmless in normal operation and a
             * catastrophe when it ruptures is one aspect with two significance ratings.
             */
            $table->string('condition', 20)->default('NORMAL');

            /**
             * Significance, scored the way the risk engine scores risk.
             *
             * Deliberately the same shape as `sheq_risk_lines`: likelihood × severity, before and
             * after controls. An environmental register scored on a different scale from the
             * safety register cannot be read side by side, and the significant aspects are
             * exactly what a combined risk review needs.
             */
            $table->unsignedSmallInteger('inherent_likelihood')->nullable();
            $table->unsignedSmallInteger('inherent_severity')->nullable();
            $table->unsignedSmallInteger('residual_likelihood')->nullable();
            $table->unsignedSmallInteger('residual_severity')->nullable();
            $table->unsignedSmallInteger('inherent_score')->nullable();
            $table->unsignedSmallInteger('residual_score')->nullable();

            /** Whether this aspect is significant, which is what drives everything else. */
            $table->boolean('is_significant')->default(false);

            $table->text('controls')->nullable();

            /**
             * The legal obligation it arises under (ENV-6).
             *
             * A real foreign key into the legal register rather than a typed reference, so that
             * when the register is updated — and it is, constantly — the aspects pointing at the
             * changed line can be found. A typed "NEMA s28" cannot be queried when NEMA changes.
             */
            $table->foreignId('legal_register_line_id')->nullable()
                ->constrained('sheq_legal_register_lines')->nullOnDelete();

            /** The meter that monitors it, where one does (ENV-5). */
            $table->foreignId('meter_id')->nullable()
                ->constrained('sheq_environmental_meters')->nullOnDelete();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('review_due')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_significant'], 'sheq_aspect_significant_idx');
            $table->index(['company_id', 'medium'], 'sheq_aspect_medium_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_aspect_reference_uniq ON sheq_environmental_aspects '
            .'(company_id, reference) WHERE deleted_at IS NULL'
        );

        /**
         * Waste off site (ENV-3).
         *
         * The manifest is the point. Under the National Environmental Management: Waste Act the
         * generator remains liable for hazardous waste until it is lawfully disposed of, and the
         * manifest is the only evidence that it was — so a record of tonnage with no transporter,
         * no destination and no manifest number documents a liability rather than discharging one.
         */
        Schema::create('sheq_waste_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference', 40);

            /** The waste stream, from the tenant's own list. */
            $table->string('waste_stream', 120);

            /**
             * Hazardous or not, and it is a column rather than a lookup on the stream.
             *
             * The same stream can be either depending on what went into it — general waste
             * contaminated with solvent is hazardous waste — and a classification derived from
             * the stream name would be wrong in exactly the cases that matter.
             */
            $table->boolean('is_hazardous')->default(false);

            /** The SAWIS / H-class code where the tenant uses them. */
            $table->string('classification_code', 40)->nullable();

            $table->decimal('quantity', 14, 3);
            $table->string('unit', 20)->default('KG');

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();

            /** Who generated it, where a contractor did. */
            $table->foreignId('contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete();

            // ── Chain of custody ─────────────────────────────────────────────────────────────
            $table->string('transporter', 255)->nullable();
            $table->string('transporter_permit', 120)->nullable();
            $table->string('vehicle_registration', 40)->nullable();

            $table->string('destination', 255)->nullable();
            $table->string('destination_permit', 120)->nullable();

            /** The manifest number, and whether the signed copy has come back. */
            $table->string('manifest_number', 120)->nullable();
            $table->boolean('manifest_returned')->default(false);
            $table->date('manifest_returned_on')->nullable();

            /** DISPOSAL, RECYCLING, RECOVERY, REUSE or TREATMENT. */
            $table->string('disposal_route', 30)->nullable();

            $table->date('removed_on');

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'removed_on'], 'sheq_waste_date_idx');
            $table->index(['company_id', 'is_hazardous'], 'sheq_waste_hazardous_idx');
            $table->index(['company_id', 'waste_stream'], 'sheq_waste_stream_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_waste_reference_uniq ON sheq_waste_movements '
            .'(company_id, reference) WHERE deleted_at IS NULL'
        );

        /**
         * Spills (ENV-4).
         *
         * Its own register rather than an incident type, because the fields are different and one
         * of them is a clock. NEMA section 30 requires the responsible person to notify the
         * authorities of an incident causing significant pollution — so "was the authority
         * notified, when, and by whom" is a column, not a note. An incident form has nowhere to
         * put it.
         */
        Schema::create('sheq_spills', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference', 40);

            $table->string('substance', 255);

            /** Whether it is classified hazardous, which decides the notification duty. */
            $table->boolean('is_hazardous')->default(true);

            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 20)->default('L');

            $table->foreignId('site_id')->nullable()->constrained('sheq_sites')->nullOnDelete();
            $table->string('location_text', 255)->nullable();

            /**
             * Where it ended up. The question that decides how serious it is.
             *
             * CONTAINED, SOIL, STORMWATER, WATERCOURSE, SEWER or AIR. A spill contained on a bund
             * is housekeeping; the same volume in a stormwater drain is a reportable event, and a
             * register that cannot tell them apart reports both or neither.
             */
            $table->string('receiving_environment', 30)->nullable();

            $table->timestampTz('occurred_at');
            $table->text('cause')->nullable();

            $table->text('containment')->nullable();
            $table->timestampTz('contained_at')->nullable();

            $table->text('clean_up')->nullable();
            $table->timestampTz('cleaned_up_at')->nullable();

            /** Where the waste from the clean-up went, as a real link (ENV-3). */
            $table->foreignId('waste_movement_id')->nullable()
                ->constrained('sheq_waste_movements')->nullOnDelete();

            // ── Notification, which is the statutory half ────────────────────────────────────
            $table->boolean('authority_notifiable')->default(false);
            $table->boolean('authority_notified')->default(false);
            $table->timestampTz('authority_notified_at')->nullable();
            $table->string('authority_name', 255)->nullable();
            $table->string('authority_reference', 120)->nullable();
            $table->text('notification_notes')->nullable();

            /** The CAPA raised from it. */
            $table->foreignId('action_id')->nullable()
                ->constrained('sheq_actions')->nullOnDelete();

            /** The aspect it realised, where the register names one. */
            $table->foreignId('aspect_id')->nullable()
                ->constrained('sheq_environmental_aspects')->nullOnDelete();

            $table->foreignId('contractor_id')->nullable()
                ->constrained('sheq_contractors')->nullOnDelete();

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('OPEN');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'occurred_at'], 'sheq_spill_date_idx');
            $table->index(['company_id', 'status'], 'sheq_spill_status_idx');
            // The list somebody has to clear before the end of the day.
            $table->index(['company_id', 'authority_notifiable', 'authority_notified'],
                'sheq_spill_notify_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX sheq_spill_reference_uniq ON sheq_spills '
            .'(company_id, reference) WHERE deleted_at IS NULL'
        );

        /*
         * ENV-5: let a meter carry its own threshold and its own CAPA behaviour.
         *
         * The KPI engine already has thresholds, and those are about KPIs. A meter threshold is a
         * different thing — it is a limit on a READING, breached by one measurement rather than
         * by a monthly aggregate — and conflating them would mean a single dust reading could
         * only trip an alert at month end, which is useless for dust.
         */
        Schema::table('sheq_environmental_meters', function (Blueprint $table) {
            /** The limit a single reading may not exceed. */
            $table->decimal('threshold_value', 14, 4)->nullable();

            /** And the one that is worth a warning before it is exceeded. */
            $table->decimal('warning_value', 14, 4)->nullable();

            /** Where the limit comes from: a licence condition, SANS, a permit. */
            $table->string('threshold_source', 255)->nullable();

            /** Whether a breach raises a corrective action automatically (ENV-5). */
            $table->boolean('capa_on_breach')->default(false);

            /** And whether it opens an investigation, for the limits that warrant one. */
            $table->boolean('investigate_on_breach')->default(false);

            $table->foreignId('legal_register_line_id')->nullable()
                ->constrained('sheq_legal_register_lines')->nullOnDelete();
        });

        /*
         * And let a reading record that it breached, and what came of it.
         *
         * Stamped at the time rather than recomputed, because a threshold changes — a licence is
         * renewed on different terms — and a reading judged against today's limit would rewrite
         * whether last year was compliant.
         */
        Schema::table('sheq_environmental_readings', function (Blueprint $table) {
            $table->boolean('breached_threshold')->default(false);
            $table->decimal('threshold_at_reading', 14, 4)->nullable();
            $table->foreignId('action_id')->nullable()
                ->constrained('sheq_actions')->nullOnDelete();
        });

        $this->applyTenantRls(['sheq_environmental_aspects', 'sheq_waste_movements',
            'sheq_spills']);
    }

    public function down(): void
    {
        Schema::table('sheq_environmental_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('action_id');
            $table->dropColumn(['breached_threshold', 'threshold_at_reading']);
        });

        Schema::table('sheq_environmental_meters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_register_line_id');
            $table->dropColumn(['threshold_value', 'warning_value', 'threshold_source',
                'capa_on_breach', 'investigate_on_breach']);
        });

        Schema::dropIfExists('sheq_spills');
        Schema::dropIfExists('sheq_waste_movements');
        Schema::dropIfExists('sheq_environmental_aspects');
    }

    /** The same row-level security every other SHEQ table carries. */
    private function applyTenantRls(array $tables): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $session = "NULLIF(current_setting('app.company_id', true), '')";
        $policy = "{$session} IS NULL OR company_id = {$session}::bigint";

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN company_id SET DEFAULT {$session}::bigint");
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
            DB::statement("CREATE POLICY tenant_isolation ON {$table} USING ({$policy}) WITH CHECK ({$policy})");
        }
    }
};
