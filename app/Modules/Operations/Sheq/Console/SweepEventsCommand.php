<?php

namespace App\Modules\Operations\Sheq\Console;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;
use App\Modules\Tenancy\Services\TenantContext;
use App\Support\Console\RunsPerTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Publishes the events that time causes rather than a person (§8.2).
 *
 * A deadline passing is not a state change anybody makes — nothing calls a method when a statutory
 * obligation goes past due, an action becomes overdue or a competency lapses. Without a sweep those
 * events would never be emitted at all, and they are precisely the ones an escalation system needs.
 *
 * Every emission is guarded against repetition. A sweep that runs hourly and re-announced the same
 * overdue action each time would train every subscriber to filter SHEQ out, which is worse than not
 * publishing at all. The guard is the outbox itself: an event of the same type for the same subject
 * is published once.
 */
class SweepEventsCommand extends Command
{
    use RunsPerTenant;

    protected $signature = 'sheq:events:sweep
                            {--company= : Restrict to one company id}
                            {--warn-days=30 : How far ahead to warn on expiring competencies}
                            {--permit-warn-minutes=60 : How long before a permit lapses to warn}';

    protected $description = 'Publish the SHEQ events that fall due with the passage of time';

    public function handle(TenantContext $tenants, EventPublisher $publisher): int
    {
        $warnDays = max(1, (int) $this->option('warn-days'));
        $permitWarnMinutes = max(1, (int) $this->option('permit-warn-minutes'));
        $only = $this->option('company') ? (int) $this->option('company') : null;

        $totals = [];

        $sweep = function (int $companyId) use ($publisher, $warnDays, $permitWarnMinutes,
            &$totals): void {
            $counts = [
                'sheq.statutory_obligation.due' => $this->obligationsDue($companyId, $publisher),
                'sheq.statutory_obligation.breached' => $this->obligationsBreached($companyId, $publisher),
                'sheq.action.overdue' => $this->actionsOverdue($companyId, $publisher),
                'sheq.action.effectiveness_review_due' => $this->effectivenessReviewsDue($companyId, $publisher),
                'sheq.incident.triage_overdue' => $this->incidentsUnacknowledged($companyId, $publisher),
                'sheq.incident.investigation_overdue' => $this->investigationsOverdue($companyId, $publisher),
                'sheq.permit.expiring' => $this->permitsExpiring($companyId, $publisher,
                    $permitWarnMinutes),
                'sheq.permit.expired' => $this->permitsExpired($companyId, $publisher),
                'sheq.competency.expiring' => $this->competenciesExpiring($companyId, $publisher, $warnDays),
                'sheq.competency.expired' => $this->competenciesExpired($companyId, $publisher),
                'sheq.competency.required' => $this->competenciesRequired($companyId, $publisher),
            ];

            foreach ($counts as $type => $n) {
                $totals[$type] = ($totals[$type] ?? 0) + $n;
            }

            if (array_sum($counts) > 0) {
                $this->components->twoColumnDetail("Company {$companyId}",
                    array_sum($counts).' event(s) published');
            }
        };

        if ($only !== null) {
            $tenants->run($only, fn () => $sweep($only));
        } else {
            $this->eachTenant($tenants, function ($companyId) use ($sweep) {
                if ($companyId !== null) {
                    $sweep((int) $companyId);
                }
            });
        }

        foreach (array_filter($totals) as $type => $n) {
            $this->components->twoColumnDetail($type, (string) $n);
        }

        $this->components->info('SHEQ time-based events swept: '.array_sum($totals).' published.');

        return self::SUCCESS;
    }

    /**
     * People who need a competency they have never held (`sheq.competency.required`).
     *
     * Swept rather than emitted from `CompetencyService::gapsFor()`, which is a read: a dashboard
     * opening must not publish events (SHEQ-ARC-4), and a gap that only announces itself when
     * somebody happens to look at a screen is not an escalation mechanism.
     *
     * **Scope: requirements assigned directly to a person.** Requirements can also attach to a
     * role, department, site, task or contractor type, and those are resolved from a context map
     * the *caller* supplies — `['ROLE' => 'Electrician', 'SITE' => '3']` — because nothing in SHEQ
     * owns the mapping from a person to their role or department; that lives in HR. Guessing at it
     * here would enrol whole workforces into training they do not need, which is expensive and
     * teaches people to ignore the notifications. Those requirements surface on the competency
     * matrix, where the context is known.
     *
     * This is the event the LMS consumes to enrol someone. Never-held only: a lapsed competency is
     * already announced as `expired`, and sending both would enrol the person twice.
     */
    private function competenciesRequired(int $companyId, EventPublisher $publisher): int
    {
        $published = 0;

        $requirements = DB::table('sheq_competency_requirements as r')
            ->join('sheq_competencies as c', 'c.id', '=', 'r.competency_id')
            ->where('r.company_id', $companyId)
            ->where('r.applies_to_type', 'EMPLOYEE')
            ->where('r.is_exempt', false)
            ->where('c.is_active', true)->whereNull('c.deleted_at')
            ->where(fn ($q) => $q->whereNull('r.effective_from')
                ->orWhere('r.effective_from', '<=', now()))
            ->get(['r.id', 'r.competency_id', 'r.applies_to_id']);

        foreach ($requirements as $requirement) {
            $hrPersonId = (int) $requirement->applies_to_id;

            if ($hrPersonId <= 0) {
                continue;
            }

            $holds = DB::table('sheq_competency_records')
                ->where('company_id', $companyId)
                ->where('hr_person_id', $hrPersonId)
                ->where('competency_id', $requirement->competency_id)
                ->whereNull('deleted_at')
                ->exists();

            if ($holds) {
                continue;
            }

            // Guarded per person per competency, not per requirement: one enrolment must not
            // suppress everybody else's.
            $alreadySent = DB::table('sheq_outbox_messages')
                ->where('company_id', $companyId)
                ->where('event_type', 'sheq.competency.required')
                ->where('subject_id', $hrPersonId)
                ->whereRaw("payload->>'competency_id' = ?", [(string) $requirement->competency_id])
                ->exists();

            if ($alreadySent) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId,
                'sheq.competency.required', 'HR_PERSON', $hrPersonId, [
                    'hr_person_id' => $hrPersonId,
                    'competency_id' => (int) $requirement->competency_id,
                    'requirement_id' => (int) $requirement->id,
                ]));

            $published++;
        }

        return $published;
    }

    /**
     * Has this event already gone out for this subject?
     *
     * Read against the outbox rather than a flag column on each table: it keeps the guard in one
     * place, and it is the same record a consumer's idempotency key comes from.
     */
    private function alreadyPublished(int $companyId, string $eventType, int $subjectId): bool
    {
        return DB::table('sheq_outbox_messages')
            ->where('company_id', $companyId)
            ->where('event_type', $eventType)
            ->where('subject_id', $subjectId)
            ->exists();
    }

    /** Approaching, not yet passed — the warning that still leaves time to act. */
    private function obligationsDue(int $companyId, EventPublisher $publisher): int
    {
        $now = Carbon::now();
        $published = 0;

        $rows = DB::table('sheq_statutory_obligations')
            ->where('company_id', $companyId)
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->whereBetween('due_at', [$now, $now->copy()->addHours(48)])
            ->get(['id', 'subject_id', 'due_at', 'responsible_role', 'label']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.statutory_obligation.due', (int) $row->id)) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId,
                'sheq.statutory_obligation.due', 'STATUTORY_OBLIGATION', (int) $row->id, [
                    'obligation_id' => (int) $row->id,
                    'subject_id' => (int) $row->subject_id,
                    'label' => $row->label,
                    'due_at' => Timestamps::utc(Carbon::parse($row->due_at))->toIso8601String(),
                    'responsible_role' => $row->responsible_role,
                ]));

            $published++;
        }

        return $published;
    }

    /** Passed undischarged. A consumer should treat this as an escalation, not a notification. */
    private function obligationsBreached(int $companyId, EventPublisher $publisher): int
    {
        $now = Carbon::now();
        $published = 0;

        $rows = DB::table('sheq_statutory_obligations')
            ->where('company_id', $companyId)
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->where('due_at', '<', $now)
            ->get(['id', 'subject_id', 'due_at', 'label']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.statutory_obligation.breached', (int) $row->id)) {
                continue;
            }

            DB::transaction(function () use ($publisher, $companyId, $row) {
                $publisher->publish($companyId, 'sheq.statutory_obligation.breached',
                    'STATUTORY_OBLIGATION', (int) $row->id, [
                        'obligation_id' => (int) $row->id,
                        'subject_id' => (int) $row->subject_id,
                        'label' => $row->label,
                        'breached_at' => Timestamps::utc(Carbon::now())->toIso8601String(),
                    ]);

                // The record follows the fact. A deadline that passed is breached whether or not
                // anybody looked, and leaving it PENDING would understate the tenant's position.
                DB::table('sheq_statutory_obligations')->where('id', $row->id)
                    ->update(['status' => 'BREACHED', 'updated_at' => now()]);
            });

            $published++;
        }

        return $published;
    }

    private function actionsOverdue(int $companyId, EventPublisher $publisher): int
    {
        $today = Carbon::today();
        $published = 0;

        $rows = DB::table('sheq_actions')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotIn('status', ['VERIFIED', 'VERIFIED_CLOSED', 'CLOSED'])
            ->whereNotNull('due_date')->where('due_date', '<', $today)
            ->get(['id', 'reference', 'due_date', 'assigned_to']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.action.overdue', (int) $row->id)) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId, 'sheq.action.overdue',
                'ACTION', (int) $row->id, [
                    'action_id' => (int) $row->id,
                    'reference' => $row->reference,
                    'due_date' => $row->due_date,
                    'days_overdue' => (int) $today->diffInDays(Carbon::parse($row->due_date)),
                    'assigned_to' => $row->assigned_to,
                ]));

            $published++;
        }

        return $published;
    }

    /** Work under an expired permit is no longer authorised — the gate needs telling. */
    /**
     * A permit that is about to lapse (AUTO-1).
     *
     * AUTO-1 asks for expiry warnings on seven things and this was the only one of them where
     * nothing warned in advance — `permitsExpired()` below announces a permit that has already
     * lapsed, and flips it to EXPIRED, which is a consequence rather than a warning.
     *
     * The window is minutes rather than days, and that is the whole reason this is a separate
     * method instead of a parameter on the others. A competency expires on a date and the useful
     * warning is a month out; a hot-work permit is valid until 16:00 on one afternoon, and the
     * useful warning is an hour before — in time for the issuer to extend it, or to stop the work
     * and set a fire watch, instead of discovering at 16:05 that the welding that is still
     * happening is no longer authorised.
     *
     * Guarded by the outbox like everything else here, so an hourly sweep inside the window
     * announces it once.
     */
    private function permitsExpiring(int $companyId, EventPublisher $publisher,
        int $warnMinutes): int
    {
        $now = Carbon::now();
        $published = 0;

        $rows = DB::table('sheq_permits')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            // The same live states `permitsExpired()` acts on. A suspended permit is left alone:
            // the work has already stopped, so there is nothing to warn anybody to wind up.
            ->whereIn('status', ['ISSUED', 'ACTIVE', 'APPROVED'])
            ->whereNotNull('valid_to')
            ->whereBetween('valid_to', [$now, $now->copy()->addMinutes($warnMinutes)])
            ->get(['id', 'reference', 'permit_type', 'site_id', 'valid_to', 'issuer_id',
                'requested_by', 'fire_watch_minutes']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.permit.expiring', (int) $row->id)) {
                continue;
            }

            $publisher->publish($companyId, 'sheq.permit.expiring', 'PERMIT', (int) $row->id, [
                'permit_id' => (int) $row->id,
                'reference' => $row->reference,
                'permit_type' => $row->permit_type,
                'site_id' => $row->site_id,
                'valid_to' => $row->valid_to,
                'minutes_left' => (int) $now->diffInMinutes(Carbon::parse($row->valid_to), false),
                // Both, because either may be the person who can act: the issuer can extend it
                // and the requester is the one whose crew has to stop.
                'issuer_id' => $row->issuer_id,
                'requested_by' => $row->requested_by,
                // A hot-work permit with a fire watch has an obligation that outlives the permit
                // itself, and the warning is the last moment anybody is looking at it.
                'fire_watch_minutes' => $row->fire_watch_minutes,
            ]);

            $published++;
        }

        return $published;
    }

    private function permitsExpired(int $companyId, EventPublisher $publisher): int
    {
        $now = Carbon::now();
        $published = 0;

        $rows = DB::table('sheq_permits')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('status', ['ISSUED', 'ACTIVE', 'APPROVED'])
            ->whereNotNull('valid_to')->where('valid_to', '<', $now)
            ->get(['id', 'reference', 'permit_type', 'valid_to']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.permit.expired', (int) $row->id)) {
                continue;
            }

            DB::transaction(function () use ($publisher, $companyId, $row) {
                $publisher->publish($companyId, 'sheq.permit.expired', 'PERMIT', (int) $row->id, [
                    'permit_id' => (int) $row->id,
                    'reference' => $row->reference,
                    'permit_type' => $row->permit_type,
                    'valid_to' => $row->valid_to,
                ]);

                DB::table('sheq_permits')->where('id', $row->id)
                    ->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            });

            $published++;
        }

        return $published;
    }

    private function competenciesExpiring(int $companyId, EventPublisher $publisher, int $warnDays): int
    {
        $today = Carbon::today();
        $published = 0;

        $rows = DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$today, $today->copy()->addDays($warnDays)])
            ->get(['id', 'hr_person_id', 'competency_id', 'expires_at']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.competency.expiring', (int) $row->id)) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId, 'sheq.competency.expiring',
                'COMPETENCY_RECORD', (int) $row->id, [
                    'hr_person_id' => (int) $row->hr_person_id,
                    'competency_id' => (int) $row->competency_id,
                    'expires_at' => $row->expires_at,
                    'days_remaining' => (int) $today->diffInDays(Carbon::parse($row->expires_at), false),
                ]));

            $published++;
        }

        return $published;
    }

    /** Lapsed. Where the competency blocks work, the holder must stop — hence a separate event. */
    private function competenciesExpired(int $companyId, EventPublisher $publisher): int
    {
        $today = Carbon::today();
        $published = 0;

        $rows = DB::table('sheq_competency_records')
            ->where('company_id', $companyId)->whereNull('deleted_at')->whereNull('revoked_at')
            ->whereNotNull('expires_at')->where('expires_at', '<', $today)
            ->get(['id', 'hr_person_id', 'competency_id', 'expires_at']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.competency.expired', (int) $row->id)) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId, 'sheq.competency.expired',
                'COMPETENCY_RECORD', (int) $row->id, [
                    'hr_person_id' => (int) $row->hr_person_id,
                    'competency_id' => (int) $row->competency_id,
                    'expired_on' => $row->expires_at,
                ]));

            $published++;
        }

        return $published;
    }

    /**
     * An incident nobody has read yet, past the hour it should have been read by.
     *
     * This is the first link in the chain and the one that was missing entirely. Reporting notifies
     * the escalation matrix, but a notification is not a task: nobody had to acknowledge it, so
     * nothing noticed when nobody did.
     */
    private function incidentsUnacknowledged(int $companyId, EventPublisher $publisher): int
    {
        $hours = (int) (DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('incident_triage_sla_hours') ?? 24);

        $cutoff = Carbon::now()->subHours($hours);
        $published = 0;

        $rows = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('status', '<>', 'CLOSED')
            ->whereNull('acknowledged_at')
            ->whereRaw('coalesce(reported_at, occurred_at, created_at) < ?', [$cutoff])
            ->get(['id', 'reference', 'reported_at', 'potential_severity', 'site_id']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.incident.triage_overdue', (int) $row->id)) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId, 'sheq.incident.triage_overdue',
                'INCIDENT', (int) $row->id, [
                    'incident_id' => (int) $row->id,
                    'reference' => $row->reference,
                    'reported_at' => $row->reported_at,
                    'potential_severity' => $row->potential_severity,
                    'site_id' => $row->site_id,
                    'sla_hours' => $hours,
                ]));

            $published++;
        }

        return $published;
    }

    /**
     * An incident past the date by which approved findings should have existed.
     *
     * Approved findings, not an opened investigation — an investigation left in DRAFT for a month
     * is the same silence as one never opened, and only the approval makes the findings real. The
     * incident's own status is not the test either: it can sit at UNDER_INVESTIGATION indefinitely.
     */
    private function investigationsOverdue(int $companyId, EventPublisher $publisher): int
    {
        $now = Carbon::now();
        $published = 0;

        $rows = DB::table('sheq_incidents as i')
            ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
            ->where('i.status', '<>', 'CLOSED')
            ->whereNotNull('i.investigation_due_at')
            ->where('i.investigation_due_at', '<', $now)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sheq_investigations as v')
                ->whereColumn('v.incident_id', 'i.id')
                ->where('v.company_id', $companyId)
                ->where('v.status', 'APPROVED'))
            ->get(['i.id', 'i.reference', 'i.investigation_due_at', 'i.assigned_to',
                'i.potential_severity', 'i.site_id', 'i.status']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.incident.investigation_overdue', (int) $row->id)) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId, 'sheq.incident.investigation_overdue',
                'INCIDENT', (int) $row->id, [
                    'incident_id' => (int) $row->id,
                    'reference' => $row->reference,
                    'due_at' => $row->investigation_due_at,
                    'days_overdue' => (int) $now->diffInDays(Carbon::parse($row->investigation_due_at)),
                    'assigned_to' => $row->assigned_to,
                    'unassigned' => $row->assigned_to === null,
                    'potential_severity' => $row->potential_severity,
                    'incident_status' => $row->status,
                    'site_id' => $row->site_id,
                ]));

            $published++;
        }

        return $published;
    }

    /**
     * A corrective action that was closed, and never checked to see whether it worked.
     *
     * The last step of CAPA and the easiest to skip, because by the time it falls due the incident
     * has been closed for months and everyone has moved on. The date was already being set and the
     * KPI was already counting how often it was answered — nothing ever asked the question.
     */
    private function effectivenessReviewsDue(int $companyId, EventPublisher $publisher): int
    {
        $today = Carbon::today();
        $published = 0;

        $rows = DB::table('sheq_actions')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('effectiveness_review_due')
            ->where('effectiveness_review_due', '<=', $today)
            ->whereNull('effectiveness_result')
            // Only actions that were actually completed: reviewing whether an unfinished control
            // worked is a question with no answer.
            ->whereIn('status', ['VERIFIED', 'VERIFIED_CLOSED', 'CLOSED', 'COMPLETED'])
            ->get(['id', 'reference', 'effectiveness_review_due', 'assigned_to', 'approver_id',
                'hierarchy_level', 'source_type', 'source_id']);

        foreach ($rows as $row) {
            if ($this->alreadyPublished($companyId, 'sheq.action.effectiveness_review_due', (int) $row->id)) {
                continue;
            }

            DB::transaction(fn () => $publisher->publish($companyId, 'sheq.action.effectiveness_review_due',
                'ACTION', (int) $row->id, [
                    'action_id' => (int) $row->id,
                    'reference' => $row->reference,
                    'due_date' => $row->effectiveness_review_due,
                    'assigned_to' => $row->assigned_to,
                    'reviewer_id' => $row->approver_id,
                    'hierarchy_level' => $row->hierarchy_level,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id !== null ? (int) $row->source_id : null,
                ]));

            $published++;
        }

        return $published;
    }
}
