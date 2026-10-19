<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules E–J: budgets and farm transactions, machinery, tasks, compliance documents and alerts.
 *
 * farm_transactions is a register of what was entered on the farm screens, each pointing at the
 * journal it posted — the amounts that count are the journal's. farm_documents keeps the file in the
 * database as base64 (never on disk) with its expiry, so compliance reminders need nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── E: budgets and farm transactions ────────────────────────────────────────────────
        Schema::create('farm_budget_lines', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('season_id')->nullable()->constrained('crop_seasons')->nullOnDelete();
            $table->string('enterprise', 40)->nullable(); // crop_plan:12 | livestock_group:3 | null = whole farm
            // inputs | labour | machinery | fuel | animal_health | feed | repairs | overheads | other | income
            $table->string('category', 20);
            $table->string('description', 200)->nullable();
            $table->decimal('amount', 14, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'period_start']);
        });

        Schema::create('farm_transactions', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->string('kind', 10); // expense | sale
            $table->string('enterprise', 40)->nullable();
            $table->unsignedBigInteger('field_id')->nullable();
            $table->string('category', 20);
            $table->date('transacted_on');
            $table->string('description', 300);
            $table->string('counterparty', 150)->nullable();
            $table->string('reference', 60)->nullable();
            $table->decimal('quantity', 14, 3)->nullable();
            $table->string('unit', 12)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('settled_by', 12)->default('bank'); // bank | payable | receivable
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'transacted_on']);
        });

        // ── F: machinery ────────────────────────────────────────────────────────────────────
        Schema::create('farm_machines', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->unsignedBigInteger('fleet_vehicle_id')->nullable(); // a road-registered tractor/bakkie in Fleet
            $table->string('code', 30);
            $table->string('name', 150);
            // tractor | harvester | sprayer | planter | implement | irrigation | pump | generator | vehicle | other
            $table->string('machine_type', 20);
            $table->string('make', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('serial_number', 60)->nullable();
            $table->string('registration', 30)->nullable();
            $table->decimal('hours_reading', 10, 1)->default(0);
            $table->decimal('service_interval_hours', 10, 1)->nullable();
            $table->decimal('next_service_hours', 10, 1)->nullable();
            $table->date('next_service_on')->nullable();
            $table->decimal('hourly_rate', 14, 2)->nullable(); // costing rate for work done
            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->date('purchased_on')->nullable();
            $table->string('status', 12)->default('active'); // active | maintenance | disposed
            $table->date('disposed_on')->nullable();
            $table->string('disposal_reason', 300)->nullable();
            $table->decimal('disposal_value', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'status']);
        });
        \Illuminate\Support\Facades\DB::statement('CREATE UNIQUE INDEX farm_machines_company_code_unique ON farm_machines (company_id, lower(code))');

        Schema::create('machine_usage_logs', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('machine_id')->constrained('farm_machines')->restrictOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('field_activities')->nullOnDelete();
            $table->date('used_on');
            $table->decimal('hours', 10, 2)->default(0);
            $table->decimal('hours_reading_after', 10, 1)->nullable();
            $table->decimal('fuel_litres', 10, 2)->nullable();
            $table->decimal('fuel_cost', 14, 2)->nullable();
            $table->unsignedBigInteger('operator_id')->nullable();
            $table->string('notes', 300)->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
            $table->index(['machine_id', 'used_on']);
        });

        Schema::create('machine_services', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->foreignId('machine_id')->constrained('farm_machines')->restrictOnDelete();
            $table->date('serviced_on');
            $table->string('service_type', 20)->default('service'); // service | repair | inspection
            $table->decimal('hours_at_service', 10, 1)->nullable();
            $table->string('description', 300);
            $table->string('provider', 150)->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->timestamps();
        });

        // ── G: tasks ─────────────────────────────────────────────────────────────────────────
        Schema::create('farm_tasks', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            $table->unsignedBigInteger('field_id')->nullable();
            $table->unsignedBigInteger('crop_plan_id')->nullable();
            $table->unsignedBigInteger('livestock_group_id')->nullable();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 8)->default('normal'); // low | normal | high | urgent
            $table->date('due_on')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            // open | in_progress | submitted | approved | rejected | cancelled
            $table->string('status', 12)->default('open');
            $table->boolean('requires_approval')->default(true);
            $table->decimal('labour_hours', 10, 2)->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'status', 'due_on']);
            $table->index(['assigned_to', 'status']);
        });

        // ── J: compliance documents ─────────────────────────────────────────────────────────
        Schema::create('farm_documents', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            // title_deed | lease | water_use_licence | pesticide_registration | operator_certificate |
            // organic_certificate | globalgap | brand_registration | movement_permit | vet_certificate |
            // insurance | fire_permit | other
            $table->string('document_type', 30);
            $table->string('title', 200);
            $table->string('reference', 80)->nullable();
            $table->string('subject_type', 30)->nullable(); // machine | animal | livestock_group | field | crop_plan
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->unsignedSmallInteger('remind_days')->default(30);
            $table->string('file_name', 200)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->longText('data')->nullable(); // base64, as every document on the platform
            $table->string('status', 12)->default('active'); // active | superseded | archived
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'farm_id', 'expires_on']);
        });

        // ── I: alerts ────────────────────────────────────────────────────────────────────────
        Schema::create('farm_alerts', function (Blueprint $table) {
            AgriSchema::farmOwned($table);
            // low_stock | service_due | document_expiry | task_overdue | withdrawal | observation | harvest_due | budget_overrun
            $table->string('kind', 20);
            $table->string('severity', 10)->default('warning'); // info | warning | critical
            $table->string('title', 200);
            $table->string('message', 500)->nullable();
            $table->string('link', 300)->nullable();
            $table->date('due_on')->nullable();
            // One open alert per condition: the scan refreshes it rather than adding another.
            $table->string('dedupe_key', 120);
            $table->string('status', 12)->default('open'); // open | acknowledged | resolved
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'dedupe_key']);
            $table->index(['company_id', 'farm_id', 'status']);
        });

        Schema::table('field_activities', function (Blueprint $table) {
            $table->foreign('machine_id')->references('id')->on('farm_machines')->nullOnDelete();
        });

        AgriSchema::tenantRls(['farm_budget_lines', 'farm_transactions', 'farm_machines', 'machine_usage_logs',
            'machine_services', 'farm_tasks', 'farm_documents', 'farm_alerts']);
    }

    public function down(): void
    {
        Schema::table('field_activities', fn (Blueprint $t) => $t->dropForeign(['machine_id']));
        foreach (['farm_alerts', 'farm_documents', 'farm_tasks', 'machine_services', 'machine_usage_logs',
            'farm_machines', 'farm_transactions', 'farm_budget_lines'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
