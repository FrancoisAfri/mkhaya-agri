<?php

use App\Modules\Agri\Support\AgriSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 · §2 — farm work orders: every job planned, assigned, executed, verified and costed.
 *
 *   draft → approved → in_progress ⇄ paused → verification → completed
 *                                     (rejected / reopened back to in_progress; cancelled before completion)
 *
 * Completion is the only moment money moves: materials are issued through the stock ledger (once,
 * idempotently), machine hours are logged on the machine, labour is the approved time and piecework
 * on the job. Nothing on the order itself is a second copy of those records — it only totals them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farm_work_orders', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->string('reference', 30);
            // land_preparation | planting | fertilising | spraying | weeding | pruning | irrigation | harvesting |
            // grading | packing | feeding | vaccination | treatment | machinery_service | infrastructure | fencing |
            // borehole | cleaning | biosecurity | other
            $t->string('category', 20);
            $t->string('title', 200);
            $t->text('instructions')->nullable();
            $t->string('priority', 8)->default('normal');           // low | normal | high | urgent
            $t->unsignedBigInteger('field_id')->nullable();
            $t->unsignedBigInteger('location_id')->nullable();
            $t->unsignedBigInteger('crop_plan_id')->nullable();
            $t->unsignedBigInteger('livestock_group_id')->nullable();
            $t->timestamp('planned_start');
            $t->timestamp('planned_end');
            $t->decimal('estimated_hours', 10, 2)->nullable();
            $t->decimal('expected_output', 14, 3)->nullable();
            $t->string('output_unit', 12)->nullable();
            $t->decimal('budget_amount', 14, 2)->nullable();
            $t->jsonb('required_certifications')->nullable();
            // draft | approved | in_progress | paused | verification | completed | cancelled | rejected
            $t->string('status', 14)->default('draft');
            $t->decimal('actual_output', 14, 3)->nullable();
            $t->unsignedInteger('actual_labour_minutes')->nullable();
            $t->decimal('actual_labour_cost', 14, 2)->nullable();
            $t->decimal('actual_material_cost', 14, 2)->nullable();
            $t->decimal('actual_machine_cost', 14, 2)->nullable();
            $t->unsignedBigInteger('field_activity_id')->nullable();  // the crop/livestock record it produced
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->unsignedBigInteger('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedBigInteger('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('verification_note', 1000)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'farm_id', 'status', 'planned_start']);
        });
        DB::statement('CREATE UNIQUE INDEX farm_work_orders_company_reference_unique ON farm_work_orders (company_id, reference)');

        Schema::create('work_order_workers', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('work_order_id')->constrained('farm_work_orders')->cascadeOnDelete();
            $t->foreignId('worker_id')->constrained('farm_workers')->restrictOnDelete();
            $t->string('warnings', 500)->nullable();
            $t->timestamps();
            $t->unique(['work_order_id', 'worker_id']);
        });

        Schema::create('work_order_materials', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('work_order_id')->constrained('farm_work_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('stock_item_id');
            $t->decimal('planned_quantity', 14, 4);
            $t->decimal('actual_quantity', 14, 4)->nullable();     // as recorded by the crew
            $t->decimal('rate_per_ha', 14, 4)->nullable();
            $t->unsignedSmallInteger('withholding_days')->nullable();
            $t->unsignedBigInteger('reservation_id')->nullable();
            $t->decimal('cost', 14, 2)->nullable();
            $t->timestamps();
        });

        Schema::create('work_order_machines', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('work_order_id')->constrained('farm_work_orders')->cascadeOnDelete();
            $t->foreignId('machine_id')->constrained('farm_machines')->restrictOnDelete();
            $t->timestamp('booked_from');
            $t->timestamp('booked_to');
            $t->decimal('planned_hours', 10, 2)->nullable();
            $t->decimal('actual_hours', 10, 2)->nullable();
            $t->decimal('cost', 14, 2)->nullable();
            $t->string('warnings', 500)->nullable();
            $t->timestamps();
            $t->index(['machine_id', 'booked_from']);
        });

        Schema::create('work_order_logs', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('work_order_id')->constrained('farm_work_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('worker_id')->nullable();
            // start | pause | resume | progress | material | machine | issue | request | submit
            $t->string('kind', 10);
            $t->unsignedInteger('minutes')->nullable();
            $t->decimal('quantity', 14, 3)->nullable();
            $t->unsignedBigInteger('stock_item_id')->nullable();
            $t->unsignedBigInteger('machine_id')->nullable();
            $t->decimal('machine_hours', 10, 2)->nullable();
            $t->text('note')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('photo_name', 200)->nullable();
            $t->string('photo_mime', 80)->nullable();
            $t->longText('photo_data')->nullable();                   // base64, as every file on the platform
            $t->timestampTz('logged_at');
            $t->uuid('client_id')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->timestamps();
            $t->index(['work_order_id', 'kind']);
        });
        DB::statement('CREATE UNIQUE INDEX work_order_logs_client_unique ON work_order_logs (company_id, client_id) WHERE client_id IS NOT NULL');

        Schema::create('work_order_status_changes', function (Blueprint $t) {
            AgriSchema::farmOwned($t);
            $t->foreignId('work_order_id')->constrained('farm_work_orders')->cascadeOnDelete();
            $t->string('from_status', 14)->nullable();
            $t->string('to_status', 14);
            $t->string('reason', 500)->nullable();
            $t->unsignedBigInteger('changed_by')->nullable();
            $t->timestamps();
        });

        AgriSchema::tenantRls(['farm_work_orders', 'work_order_workers', 'work_order_materials', 'work_order_machines', 'work_order_logs', 'work_order_status_changes']);
    }

    public function down(): void
    {
        foreach (['work_order_status_changes', 'work_order_logs', 'work_order_machines', 'work_order_materials', 'work_order_workers', 'farm_work_orders'] as $x) {
            Schema::dropIfExists($x);
        }
    }
};
