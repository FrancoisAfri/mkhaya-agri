<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work-order management for the Asset Management module.
 *
 * Work orders are the richer execution layer on top of the simpler
 * AssetMaintenanceSchedule: they carry a task checklist, labour and parts
 * lines, a cost roll-up and a status flow (open → in_progress → on_hold →
 * completed / cancelled).
 *
 * Multi-tenant: each table carries a nullable, indexed company_id that is
 * auto-stamped by the BelongsToCompany trait / scoped by CompanyScope.
 *
 * Loose coupling: work-order part lines reference a spare part via a nullable
 * part_id (a separate asset_parts feature is being built in parallel) — there
 * is deliberately NO foreign key here, and each line also carries free-text
 * description / quantity / unit_cost so the feature stands alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_work_orders')) {
            Schema::create('asset_work_orders', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->string('work_order_number')->index();   // unique per tenant, enforced in the create action
                $t->unsignedBigInteger('asset_id')->index();
                $t->string('title');
                $t->text('description')->nullable();
                $t->string('type')->default('corrective');  // corrective | preventive | inspection
                $t->string('priority')->default('medium');   // low | medium | high | critical
                $t->string('status')->default('open');       // open | in_progress | on_hold | completed | cancelled
                $t->unsignedBigInteger('assigned_to')->nullable();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->date('scheduled_for')->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->decimal('labor_hours', 8, 2)->default(0);
                $t->decimal('labor_cost', 15, 2)->default(0);
                $t->decimal('parts_cost', 15, 2)->default(0);
                $t->decimal('total_cost', 15, 2)->default(0);
                $t->text('resolution_notes')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('asset_work_order_tasks')) {
            Schema::create('asset_work_order_tasks', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('work_order_id')->index();
                $t->string('description');
                $t->integer('sequence')->default(0);
                $t->boolean('is_done')->default(false);
                $t->unsignedBigInteger('done_by')->nullable();
                $t->timestamp('done_at')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('asset_work_order_parts')) {
            Schema::create('asset_work_order_parts', function (Blueprint $t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->unsignedBigInteger('company_id')->nullable()->index();
                $t->unsignedBigInteger('work_order_id')->index();
                $t->unsignedBigInteger('part_id')->nullable()->index(); // loose ref to asset_parts (no FK by design)
                $t->string('description');
                $t->decimal('quantity', 12, 2)->default(1);
                $t->decimal('unit_cost', 15, 2)->default(0);
                $t->decimal('line_cost', 15, 2)->default(0);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_work_order_parts');
        Schema::dropIfExists('asset_work_order_tasks');
        Schema::dropIfExists('asset_work_orders');
    }
};
