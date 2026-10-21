<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agricultural production, processing and intelligence — one engine inside Manufacturing &
 * Production rather than a module per animal or crop.
 *
 * A production type (broilers, layers, maize, tilapia, a juice line) is configuration: its stages,
 * the measurements it is recorded by, its custom fields and the metric formulas it is judged on.
 * A subtype inherits from its parent and adds to it. Every change to a type is a new version, and a
 * cycle keeps the version it started on, so a formula revised in March does not rewrite how a flock
 * placed in January was measured.
 *
 * A unit is where production happens (a house, a field, a pond, a greenhouse, a line); a cycle is
 * one round of it (a flock, a season, a batch). Records are what happened in a cycle — mortality,
 * feed, water, weights, treatments, harvests — each with an optional client id so a record captured
 * offline arrives once however many times the phone retries.
 *
 * The farm keeps its own registers: a poultry cycle's head count is the farm's livestock group, a
 * crop cycle is the farm's crop plan, and grading or processing is a Manufacturing production run.
 * These tables link to them; they never copy them.
 *
 * Devices, readings, thresholds, incidents, escalation and commands are the Command & Control
 * Centre. Sources, publications, outbreaks, exposures, opportunities, recommendations and forecasts
 * are the intelligence layer. Every table carries company_id under row-level security.
 */
return new class extends Migration
{
    private array $tables = [];

    public function up(): void
    {
        $this->create('prd_production_types', function (Blueprint $t) {
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('category', 20);                                   // poultry | crop | livestock | dairy | aquaculture | greenhouse | specialist | processing
            $t->foreignId('parent_id')->nullable()->constrained('prd_production_types')->nullOnDelete();
            $t->string('unit_kind', 20)->default('house');                // house | field | pond | greenhouse | pen | line | orchard | hive
            $t->string('count_unit', 20)->nullable();                     // birds, head, fish, plants
            $t->string('output_unit', 20)->nullable();                    // kg, t, dozen, l
            $t->jsonb('stages')->nullable();                              // [{code, name, day_from?, day_to?}]
            $t->jsonb('measurements')->nullable();                        // [{code, name, unit, kind}]
            $t->jsonb('custom_fields')->nullable();                       // [{code, name, type, required, options?}]
            $t->jsonb('metrics')->nullable();                             // [{code, name, formula, unit, better}]
            $t->jsonb('defaults')->nullable();                            // expected cycle days, target weight, standard curves
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_active')->default(true);
            $t->unique(['company_id', 'code']);
        });

        $this->create('prd_type_versions', function (Blueprint $t) {
            $t->foreignId('production_type_id')->constrained('prd_production_types')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->jsonb('definition');                                      // stages, measurements, fields, metrics, defaults as they stood
            $t->text('change_note')->nullable();
            $t->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['production_type_id', 'version']);
        });

        $this->create('prd_units', function (Blueprint $t) {
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->foreignId('field_id')->nullable()->constrained('farm_fields')->nullOnDelete();
            $t->unsignedBigInteger('factory_id')->nullable();             // mfg_factories, for a processing line
            $t->foreignId('production_type_id')->nullable()->constrained('prd_production_types')->nullOnDelete();
            $t->string('code', 40);
            $t->string('name', 120);
            $t->string('kind', 20);
            $t->decimal('capacity', 14, 2)->nullable();
            $t->string('capacity_unit', 20)->nullable();
            $t->decimal('area', 14, 4)->nullable();                       // m² for a house, ha for a field
            $t->string('area_unit', 8)->nullable();
            $t->string('status', 16)->default('active');                  // active | idle | cleaning | maintenance | retired
            $t->string('biosecurity_zone', 40)->nullable();
            $t->jsonb('attributes')->nullable();
            $t->unique(['company_id', 'farm_id', 'code']);
        });

        $this->create('prd_cycles', function (Blueprint $t) {
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->foreignId('production_type_id')->constrained('prd_production_types');
            $t->unsignedInteger('type_version');
            $t->foreignId('unit_id')->nullable()->constrained('prd_units')->nullOnDelete();
            $t->string('code', 40);
            $t->string('name', 160)->nullable();
            $t->string('status', 16)->default('planned');                 // planned | active | closed | cancelled
            $t->string('stage', 40)->nullable();
            $t->date('planned_start_on')->nullable();
            $t->date('started_on')->nullable();
            $t->date('expected_end_on')->nullable();
            $t->date('ended_on')->nullable();
            $t->unsignedInteger('opening_count')->nullable();
            $t->unsignedInteger('current_count')->nullable();
            $t->decimal('area', 14, 4)->nullable();
            $t->string('area_unit', 8)->nullable();
            $t->decimal('output_quantity', 18, 3)->default(0);
            $t->decimal('cost_to_date', 18, 2)->default(0);
            $t->string('breed', 80)->nullable();
            $t->string('source_ref', 80)->nullable();                     // hatchery, seed lot, fingerling batch
            $t->unsignedBigInteger('livestock_group_id')->nullable();     // the farm's group for an animal cycle
            $t->unsignedBigInteger('crop_plan_id')->nullable();           // the farm's crop plan for a crop cycle
            $t->unsignedBigInteger('production_order_id')->nullable();    // mfg order for a processing batch
            $t->jsonb('custom')->nullable();
            $t->jsonb('closing_metrics')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['company_id', 'code']);
            $t->index(['company_id', 'status']);
        });

        $this->create('prd_cycle_units', function (Blueprint $t) {
            $t->foreignId('cycle_id')->constrained('prd_cycles')->cascadeOnDelete();
            $t->foreignId('unit_id')->constrained('prd_units');
            $t->timestampTz('from_at');
            $t->timestampTz('to_at')->nullable();
            $t->unsignedInteger('count')->nullable();
            $t->string('reason', 120)->nullable();
        });

        $this->create('prd_stage_events', function (Blueprint $t) {
            $t->foreignId('cycle_id')->constrained('prd_cycles')->cascadeOnDelete();
            $t->string('from_stage', 40)->nullable();
            $t->string('to_stage', 40);
            $t->timestampTz('at');
            $t->text('note')->nullable();
            $t->foreignId('by')->nullable()->constrained('users')->nullOnDelete();
        });

        $this->create('prd_records', function (Blueprint $t) {
            $t->foreignId('cycle_id')->constrained('prd_cycles')->cascadeOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained('prd_units')->nullOnDelete();
            $t->string('kind', 20);                                       // mortality | cull | feed | water | weight | transfer | treatment | harvest | eggs | milk | input | cost | observation
            $t->timestampTz('recorded_at');
            $t->decimal('quantity', 18, 4)->nullable();
            $t->string('unit', 20)->nullable();
            $t->integer('count_delta')->nullable();
            $t->decimal('cost', 18, 2)->nullable();
            $t->string('grade', 40)->nullable();
            $t->string('reason', 120)->nullable();
            $t->jsonb('details')->nullable();
            $t->string('client_id', 80)->nullable()->unique();            // offline capture: arrives once
            $t->string('source', 12)->default('manual');                  // manual | device | offline | import
            $t->string('link_type', 40)->nullable();                      // what this record wrote elsewhere
            $t->unsignedBigInteger('link_id')->nullable();
            $t->foreignId('corrects_id')->nullable()->constrained('prd_records')->nullOnDelete();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index(['cycle_id', 'kind', 'recorded_at']);
        });

        $this->create('prd_restrictions', function (Blueprint $t) {
            $t->foreignId('cycle_id')->constrained('prd_cycles')->cascadeOnDelete();
            $t->string('kind', 20);                                       // withdrawal | quarantine | qa_hold | movement
            $t->string('reason', 255);
            $t->date('starts_on');
            $t->date('until_on')->nullable();
            $t->string('source_type', 40)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->timestampTz('lifted_at')->nullable();
            $t->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('lift_note')->nullable();
        });

        // ── Command & Control Centre ─────────────────────────────────────────────────────────────

        $this->create('prd_devices', function (Blueprint $t) {
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained('prd_units')->nullOnDelete();
            $t->string('code', 60);
            $t->string('name', 120);
            $t->string('kind', 16)->default('sensor');                    // sensor | controller | gateway | meter
            $t->string('metric', 40)->nullable();                         // temperature | humidity | ammonia | co2 | water_flow | feed_weight | ...
            $t->string('unit', 20)->nullable();
            $t->decimal('calibration_offset', 12, 4)->default(0);
            $t->decimal('calibration_factor', 12, 6)->default(1);
            $t->date('calibrated_on')->nullable();
            $t->unsignedInteger('expected_interval_seconds')->default(300);
            $t->timestampTz('last_seen_at')->nullable();
            $t->timestampTz('last_measured_at')->nullable();
            $t->decimal('last_value', 18, 4)->nullable();
            $t->string('status', 12)->default('online');                  // online | stale | offline | fault | retired
            $t->boolean('controllable')->default(false);
            $t->boolean('commands_need_approval')->default(true);
            $t->jsonb('allowed_commands')->nullable();                    // [{command, min?, max?}]
            $t->string('secret_hash', 128)->nullable();                   // a gateway signs what it sends
            $t->unique(['company_id', 'code']);
        });

        $this->create('prd_readings', function (Blueprint $t) {
            $t->foreignId('device_id')->constrained('prd_devices')->cascadeOnDelete();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->string('metric', 40);
            $t->decimal('value', 18, 4);
            $t->decimal('raw_value', 18, 4)->nullable();
            $t->string('unit', 20)->nullable();
            $t->timestampTz('measured_at');
            $t->timestampTz('received_at');
            $t->string('message_id', 120);
            $t->string('quality', 14)->default('good');                   // good | out_of_order | suspect | late
            $t->unique(['device_id', 'message_id']);
            $t->index(['device_id', 'measured_at']);
            $t->index(['unit_id', 'metric', 'measured_at']);
        }, timestamps: false);

        $this->create('prd_thresholds', function (Blueprint $t) {
            $t->foreignId('production_type_id')->nullable()->constrained('prd_production_types')->cascadeOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained('prd_units')->cascadeOnDelete();
            $t->string('metric', 40);
            $t->decimal('min_value', 18, 4)->nullable();
            $t->decimal('max_value', 18, 4)->nullable();
            $t->string('severity', 12)->default('warning');
            $t->unsignedInteger('sustain_seconds')->default(0);           // how long out of range before it counts
            $t->string('message', 255)->nullable();
            $t->boolean('is_active')->default(true);
        });

        $this->create('prd_incidents', function (Blueprint $t) {
            $t->string('number', 30);
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained('prd_units')->nullOnDelete();
            $t->foreignId('cycle_id')->nullable()->constrained('prd_cycles')->nullOnDelete();
            $t->foreignId('device_id')->nullable()->constrained('prd_devices')->nullOnDelete();
            $t->string('kind', 30);                                       // threshold | water_interruption | stale_sensor | mortality | weather | outbreak_exposure | command_failed | manual
            $t->string('severity', 12);                                   // info | advisory | warning | critical | emergency
            $t->string('status', 14)->default('open');                    // open | acknowledged | in_progress | resolved | closed
            $t->string('title', 255);
            $t->text('detail')->nullable();
            $t->string('metric', 40)->nullable();
            $t->decimal('observed_value', 18, 4)->nullable();
            $t->string('dedupe_key', 160)->nullable();
            $t->timestampTz('opened_at');
            $t->timestampTz('last_seen_at')->nullable();
            $t->unsignedInteger('occurrences')->default(1);
            $t->timestampTz('acknowledged_at')->nullable();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedSmallInteger('escalation_level')->default(0);
            $t->timestampTz('next_escalation_at')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('resolution')->nullable();
            $t->string('root_cause', 255)->nullable();
            $t->jsonb('evidence')->nullable();
            $t->timestampTz('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index(['company_id', 'status', 'severity']);
            $t->index(['company_id', 'dedupe_key']);
        });

        $this->create('prd_incident_events', function (Blueprint $t) {
            $t->foreignId('incident_id')->constrained('prd_incidents')->cascadeOnDelete();
            $t->string('event', 30);
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('at');
            $t->jsonb('detail')->nullable();
        });

        $this->create('prd_escalation_policies', function (Blueprint $t) {
            $t->string('severity', 12);
            $t->jsonb('steps');                                           // [{level, after_minutes, user_ids[], roles[], channels[]}]
            $t->boolean('is_active')->default(true);
            $t->unique(['company_id', 'severity']);
        });

        $this->create('prd_notifications', function (Blueprint $t) {
            $t->foreignId('incident_id')->nullable()->constrained('prd_incidents')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('channel', 16);                                    // in_app | email | sms | whatsapp
            $t->unsignedSmallInteger('level')->default(1);
            $t->string('status', 14)->default('sent');                    // sent | failed | acknowledged
            $t->timestampTz('sent_at');
            $t->timestampTz('acknowledged_at')->nullable();
            $t->string('error', 255)->nullable();
        });

        $this->create('prd_commands', function (Blueprint $t) {
            $t->foreignId('device_id')->constrained('prd_devices');
            $t->foreignId('unit_id')->nullable()->constrained('prd_units')->nullOnDelete();
            $t->string('command', 40);                                    // set_temperature | fan_on | fan_off | valve_open | valve_close | lights | feeder
            $t->jsonb('parameters')->nullable();
            $t->string('status', 18);                                     // requested | pending_approval | approved | rejected | sent | accepted | executed | failed | unconfirmed | cancelled
            $t->string('reason', 255)->nullable();
            $t->string('origin', 16)->default('manual');                  // manual | recommendation | schedule
            $t->unsignedBigInteger('recommendation_id')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('sent_at')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->timestampTz('executed_at')->nullable();
            $t->timestampTz('confirm_by')->nullable();
            $t->string('failure_reason', 255)->nullable();
            $t->string('idempotency_key', 120)->nullable()->unique();
            $t->jsonb('history')->nullable();
        });

        // ── Intelligence ─────────────────────────────────────────────────────────────────────────

        $this->create('prd_sources', function (Blueprint $t) {
            $t->string('code', 40);
            $t->string('name', 160);
            $t->string('kind', 16);                                       // weather | disease | government | ngo | market | research
            $t->string('publisher', 160)->nullable();
            $t->string('url', 500)->nullable();
            $t->string('trust', 12)->default('official');                 // official | trusted | community | unverified
            $t->string('status', 12)->default('active');                  // active | stale | failing | paused
            $t->unsignedInteger('refresh_minutes')->default(60);
            $t->timestampTz('last_attempt_at')->nullable();
            $t->timestampTz('last_success_at')->nullable();
            $t->unsignedInteger('failure_count')->default(0);
            $t->string('last_error', 255)->nullable();
            $t->timestampTz('next_attempt_at')->nullable();
            $t->unique(['company_id', 'code']);
        });

        $this->create('prd_publications', function (Blueprint $t) {
            $t->foreignId('source_id')->constrained('prd_sources')->cascadeOnDelete();
            $t->string('external_id', 160);
            $t->string('kind', 20);                                       // warning | outbreak | notice | funding | price | advisory
            $t->string('title', 255);
            $t->text('body')->nullable();                                 // plain text, sanitised
            $t->string('url', 500)->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->timestampTz('fetched_at');
            $t->string('content_hash', 64);
            $t->unsignedInteger('revision')->default(1);
            $t->boolean('flagged')->default(false);                       // suspected instructions or injected markup
            $t->jsonb('flags')->nullable();
            $t->unique(['source_id', 'external_id']);
        });

        $this->create('prd_outbreaks', function (Blueprint $t) {
            $t->foreignId('source_id')->constrained('prd_sources');
            $t->foreignId('publication_id')->nullable()->constrained('prd_publications')->nullOnDelete();
            $t->string('external_id', 160);
            $t->string('disease', 120);
            $t->string('species', 60)->nullable();                        // poultry | cattle | pigs | sheep | crops ...
            $t->string('status', 14);                                     // suspected | confirmed | resolved | retracted
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('radius_km', 8, 2)->default(10);
            $t->string('region', 160)->nullable();
            $t->date('reported_on')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->jsonb('history')->nullable();
            $t->unique(['company_id', 'source_id', 'external_id']);
        });

        $this->create('prd_exposures', function (Blueprint $t) {
            $t->foreignId('outbreak_id')->constrained('prd_outbreaks')->cascadeOnDelete();
            $t->foreignId('farm_id')->constrained('farms')->cascadeOnDelete();
            $t->decimal('distance_km', 10, 2);
            $t->string('band', 10);                                       // inside | near | watch
            $t->string('status', 12)->default('open');                    // open | reviewed | closed
            $t->text('statement');
            $t->unsignedBigInteger('incident_id')->nullable();
            $t->unsignedBigInteger('recommendation_id')->nullable();
            $t->timestampTz('assessed_at');
            $t->unique(['outbreak_id', 'farm_id']);
        });

        $this->create('prd_opportunities', function (Blueprint $t) {
            $t->foreignId('source_id')->nullable()->constrained('prd_sources')->nullOnDelete();
            $t->foreignId('publication_id')->nullable()->constrained('prd_publications')->nullOnDelete();
            $t->string('kind', 16);                                       // grant | loan | subsidy | programme | tender | training | market
            $t->string('title', 255);
            $t->string('funder', 160)->nullable();
            $t->text('summary')->nullable();
            $t->decimal('amount_max', 18, 2)->nullable();
            $t->string('currency', 3)->default('ZAR');
            $t->date('opens_on')->nullable();
            $t->date('closes_on')->nullable();
            $t->jsonb('eligibility')->nullable();                         // {categories[], provinces[], max_hectares, min_hectares, max_head, smallholder}
            $t->string('url', 500)->nullable();
            $t->string('status', 12)->default('open');
        });

        $this->create('prd_opportunity_matches', function (Blueprint $t) {
            $t->foreignId('opportunity_id')->constrained('prd_opportunities')->cascadeOnDelete();
            $t->foreignId('farm_id')->constrained('farms')->cascadeOnDelete();
            $t->unsignedSmallInteger('score');
            $t->jsonb('reasons');
            $t->jsonb('gaps')->nullable();
            $t->string('status', 12)->default('suggested');               // suggested | reviewing | applying | dismissed
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['opportunity_id', 'farm_id']);
        });

        $this->create('prd_recommendations', function (Blueprint $t) {
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->foreignId('unit_id')->nullable()->constrained('prd_units')->nullOnDelete();
            $t->foreignId('cycle_id')->nullable()->constrained('prd_cycles')->nullOnDelete();
            $t->foreignId('incident_id')->nullable()->constrained('prd_incidents')->nullOnDelete();
            $t->string('agent', 30);
            $t->string('title', 255);
            $t->text('rationale');
            $t->jsonb('evidence')->nullable();
            $t->string('action_kind', 12)->default('task');               // task | command | review | none
            $t->jsonb('action')->nullable();
            $t->decimal('confidence', 5, 2)->nullable();
            $t->string('priority', 10)->default('normal');
            $t->string('status', 12)->default('proposed');                // proposed | approved | rejected | done | expired
            $t->string('dedupe_key', 160)->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->unsignedBigInteger('task_id')->nullable();
            $t->unsignedBigInteger('command_id')->nullable();
            $t->string('model', 60)->default('rules');
            $t->boolean('ai_used')->default(false);
            $t->index(['company_id', 'status']);
        });

        $this->create('prd_forecasts', function (Blueprint $t) {
            $t->foreignId('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $t->foreignId('cycle_id')->nullable()->constrained('prd_cycles')->cascadeOnDelete();
            $t->string('metric', 40);
            $t->date('target_on');
            $t->decimal('predicted', 18, 4);
            $t->decimal('lower', 18, 4)->nullable();
            $t->decimal('upper', 18, 4)->nullable();
            $t->string('unit', 20)->nullable();
            $t->string('model', 60);
            $t->string('model_version', 20);
            $t->jsonb('inputs')->nullable();
            $t->timestampTz('made_at');
            $t->decimal('actual', 18, 4)->nullable();
            $t->decimal('error', 18, 4)->nullable();
            $t->decimal('abs_pct_error', 10, 4)->nullable();
            $t->boolean('within_interval')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->index(['company_id', 'metric', 'model']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $session = "NULLIF(current_setting('app.company_id', true), '')";
            $policy = "{$session} IS NULL OR company_id = {$session}::bigint";
            foreach ($this->tables as $table) {
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
        foreach (['prd_forecasts', 'prd_recommendations', 'prd_opportunity_matches', 'prd_opportunities', 'prd_exposures', 'prd_outbreaks',
            'prd_publications', 'prd_sources', 'prd_commands', 'prd_notifications', 'prd_escalation_policies', 'prd_incident_events',
            'prd_incidents', 'prd_thresholds', 'prd_readings', 'prd_devices', 'prd_restrictions', 'prd_records', 'prd_stage_events',
            'prd_cycle_units', 'prd_cycles', 'prd_units', 'prd_type_versions', 'prd_production_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** Every table gets an id, a public uuid (bar readings), the company it belongs to and timestamps. */
    private function create(string $table, callable $columns, bool $timestamps = true): void
    {
        Schema::create($table, function (Blueprint $t) use ($columns, $timestamps, $table) {
            $t->id();
            if ($table !== 'prd_readings') {
                $t->uuid('uuid')->unique();
            }
            $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $columns($t);
            if ($timestamps) {
                $t->timestamps();
            }
        });
        $this->tables[] = $table;
    }
};
