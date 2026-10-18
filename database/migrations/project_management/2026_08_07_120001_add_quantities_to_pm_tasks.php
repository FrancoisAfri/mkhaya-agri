<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planned quantity on a task (PM-1200).
 *
 * The derivation rules put measured progress above reported progress — installed quantity against
 * planned quantity beats a typed percentage. Without somewhere to record the planned quantity that
 * rule can never fire, and every project silently falls back to whatever someone typed, which is
 * the outcome the precedence order exists to prevent.
 *
 * Nullable: plenty of work is not measured in units ("obtain planning permission"), and those tasks
 * legitimately fall through to the weaker rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_tasks', function (Blueprint $t) {
            $t->decimal('planned_quantity', 18, 4)->nullable()->after('estimated_hours');
            // Free text rather than an enum: m³, tonnes, "panels", "boreholes". A closed list would
            // be wrong for the second industry that used it.
            $t->string('quantity_unit', 20)->nullable()->after('planned_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('pm_tasks', function (Blueprint $t) {
            $t->dropColumn(['planned_quantity', 'quantity_unit']);
        });
    }
};
