<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link a completed inspection back to the schedule that asked for it.
 *
 * `InspectionService::recordScheduleCompletion()` advances a schedule — stamps `last_completed`
 * and moves `next_due` on by the frequency — and nothing ever called it, because nothing could: an
 * inspection carried no reference to the schedule it was performed against.
 *
 * The consequence is worse than an unused method. A fixed-frequency schedule's `next_due` never
 * moved, so once the first one passed, the inspection stayed overdue permanently however many
 * times it was actually carried out. SHEQ-INS-4 surfaces overdue inspections as a leading
 * indicator and sweeps them hourly, so the dashboard and the escalation both reported work that
 * had in fact been done — which is how people learn to ignore a figure.
 *
 * Nullable, because an ad-hoc inspection belongs to no schedule and that is a legitimate thing to
 * do: somebody who sees a problem should be able to inspect without one being scheduled first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sheq_inspections') || Schema::hasColumn('sheq_inspections', 'schedule_id')) {
            return;
        }

        Schema::table('sheq_inspections', function (Blueprint $table) {
            $table->unsignedBigInteger('schedule_id')->nullable()->after('template_version')
                ->comment('The schedule this inspection satisfies, where it was performed against one.');

            // Looked up when a schedule's history is read, and on the sweep for overdue work.
            $table->index(['company_id', 'schedule_id'], 'sheq_inspections_schedule_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sheq_inspections', 'schedule_id')) {
            return;
        }

        Schema::table('sheq_inspections', function (Blueprint $table) {
            $table->dropIndex('sheq_inspections_schedule_idx');
            $table->dropColumn('schedule_id');
        });
    }
};
