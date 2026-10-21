<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 §2: reopening a completed job without counting anything twice (what was already issued
 * and logged is remembered), problems reported from the field with a type and a resolution, and the
 * manager's override of the recommended work sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('farm_work_orders', function (Blueprint $t) {
            $t->unsignedSmallInteger('reopen_count')->default(0);
            $t->unsignedSmallInteger('schedule_rank')->nullable();     // manager's override: 1 = do first
            $t->string('schedule_note', 300)->nullable();
        });
        Schema::table('work_order_materials', fn (Blueprint $t) => $t->decimal('issued_quantity', 14, 4)->default(0));
        Schema::table('work_order_machines', fn (Blueprint $t) => $t->decimal('logged_hours', 10, 2)->default(0));
        Schema::table('work_order_logs', function (Blueprint $t) {
            // incident | hazard | disease | pest | equipment_failure | other — for kind = issue
            $t->string('issue_type', 20)->nullable();
            $t->string('severity', 10)->nullable();
            $t->unsignedBigInteger('observation_id')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by')->nullable();
            $t->string('resolution', 500)->nullable();
        });
        // Jobs already completed issued their materials and logged their machine hours once.
        DB::table('work_order_materials')->whereNotNull('cost')->update(['issued_quantity' => DB::raw('COALESCE(actual_quantity, 0)')]);
        DB::table('work_order_machines')->whereNotNull('cost')->update(['logged_hours' => DB::raw('COALESCE(actual_hours, 0)')]);
    }

    public function down(): void
    {
        Schema::table('work_order_logs', fn (Blueprint $t) => $t->dropColumn(['issue_type', 'severity', 'observation_id', 'resolved_at', 'resolved_by', 'resolution']));
        Schema::table('work_order_machines', fn (Blueprint $t) => $t->dropColumn('logged_hours'));
        Schema::table('work_order_materials', fn (Blueprint $t) => $t->dropColumn('issued_quantity'));
        Schema::table('farm_work_orders', fn (Blueprint $t) => $t->dropColumn(['reopen_count', 'schedule_rank', 'schedule_note']));
    }
};
