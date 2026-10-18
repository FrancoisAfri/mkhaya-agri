<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An incident that nobody owns, with no date by which anything must happen.
 *
 * Reporting was thorough and everything after it was voluntary. The incident was classified
 * against the pack, statutory obligations were raised with real deadlines, the escalation matrix
 * notified — and then the record sat there. No column named a person accountable for it, nothing
 * said by when an investigation had to exist, and the nightly sweep that chases overdue actions,
 * expiring competencies and breached obligations had no rule for an incident nobody picked up.
 *
 * A near miss recorded as could-have-killed could sit at REPORTED indefinitely while every screen
 * and every KPI showed the module working normally. That is the failure mode an incident system
 * exists to prevent, and it was the one thing it did not watch for.
 *
 * ── Why the clock starts at report, not at triage ────────────────────────────
 *
 * The deadline for investigating is set the moment the incident is recorded, from its potential
 * severity. Starting it when somebody gets round to acknowledging it would make the clock
 * resettable by not looking, which is exactly backwards: the longer an incident goes unread, the
 * more urgent it is, not the less.
 *
 * ── Why existing incidents are given a due date too ──────────────────────────
 *
 * Backfilling means open incidents from before today become visibly overdue on the next sweep.
 * That is not noise — it is the truth arriving late, and it is the whole reason for the change. The
 * sweep publishes once per incident, so this is a single catch-up rather than a recurring alarm.
 */
return new class extends Migration
{
    /**
     * Potential severity → days to investigate, matching the depth the pack already demands.
     *
     * A could-have-killed event requires ICAM and gets a week; a minor one gets a month. Tenants
     * can change it, which is why it lives in settings rather than in code.
     */
    private const DEFAULT_SLA = [5 => 7, 4 => 7, 3 => 14, 2 => 30, 1 => 30];

    public function up(): void
    {
        Schema::table('sheq_incidents', function (Blueprint $table) {
            /** The person accountable for getting this to a closed investigation. */
            $table->unsignedBigInteger('assigned_to')->nullable()->after('reported_by');

            /** Somebody has read it, confirmed it is real and confirmed how bad it could be. */
            $table->timestampTz('acknowledged_at')->nullable()->after('assigned_to');
            $table->unsignedBigInteger('acknowledged_by')->nullable()->after('acknowledged_at');

            /** When an approved investigation must exist by. */
            $table->timestampTz('investigation_due_at')->nullable()->after('acknowledged_by');
        });

        // Partial: the sweep only ever asks about incidents that are still open, and an index over
        // the closed ones would be almost all of the table and none of the answer.
        DB::statement(
            'CREATE INDEX sheq_incidents_still_open_idx ON sheq_incidents (company_id, investigation_due_at)
             WHERE status <> \'CLOSED\' AND deleted_at IS NULL'
        );

        Schema::table('sheq_settings', function (Blueprint $table) {
            /** Hours to acknowledge a reported incident, alongside the hazard equivalent. */
            $table->unsignedSmallInteger('incident_triage_sla_hours')->default(24)
                ->after('hazard_triage_sla_hours');

            /** Potential severity → days to reach approved findings. */
            $table->jsonb('investigation_sla_days')->nullable()->after('incident_triage_sla_hours');
        });

        DB::table('sheq_settings')->update([
            'investigation_sla_days' => json_encode(self::DEFAULT_SLA),
        ]);

        $this->backfillDueDates();
    }

    /**
     * Give every open incident the deadline it should always have had.
     *
     * Written with the query builder against `reported_at`, per severity, rather than row by row:
     * an installation may hold years of incidents and this runs inside a deploy.
     */
    private function backfillDueDates(): void
    {
        foreach (self::DEFAULT_SLA as $severity => $days) {
            DB::table('sheq_incidents')
                ->whereNull('investigation_due_at')
                ->where('status', '<>', 'CLOSED')
                ->whereNull('deleted_at')
                ->where('potential_severity', $severity)
                ->update([
                    'investigation_due_at' => DB::raw(
                        "coalesce(reported_at, occurred_at, created_at) + interval '{$days} days'"
                    ),
                ]);
        }

        // Potential severity is nullable, and an unrated incident is not therefore harmless — it is
        // unrated. The shortest deadline applies until somebody rates it.
        DB::table('sheq_incidents')
            ->whereNull('investigation_due_at')
            ->where('status', '<>', 'CLOSED')
            ->whereNull('deleted_at')
            ->update([
                'investigation_due_at' => DB::raw(
                    "coalesce(reported_at, occurred_at, created_at) + interval '7 days'"
                ),
            ]);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sheq_incidents_still_open_idx');

        Schema::table('sheq_incidents', function (Blueprint $table) {
            $table->dropColumn([
                'assigned_to', 'acknowledged_at', 'acknowledged_by', 'investigation_due_at',
            ]);
        });

        Schema::table('sheq_settings', function (Blueprint $table) {
            $table->dropColumn(['incident_triage_sla_hours', 'investigation_sla_days']);
        });
    }
};
