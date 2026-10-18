<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Following the injured person until they are back on full duty.
 *
 * Two typed numbers on the incident — days lost, days restricted — decided whether an injury was
 * reportable, drove the DIFR the board was shown, and were what a COIDA claim was assessed on.
 * Nothing tracked the person they described: no record of being booked off and coming back, no
 * return date to chase, and no way to answer "who is still off work".
 *
 * ── The numbers are counted from the timeline ────────────────────────────────
 *
 * Every period off or on restricted duty is recorded, and the totals are derived and written back
 * to the incident. A typed figure drifts from the true one, and these are the figures an inspector
 * recalculates and a claims assessor disputes — so the count has to be explainable by pointing at
 * what produced it.
 *
 * ── An open case does not hold the investigation open ────────────────────────
 *
 * The case's status is deliberately separate from the incident's. Findings can be approved and the
 * incident closed while the person is still in rehabilitation months later; tying them together
 * either holds the investigation open for no reason or quietly drops the person once the paperwork
 * is done.
 */
class InjuryCaseService
{
    /** Periods that move the day counts, as against the record of care that does not. */
    private const LOST = 'BOOKED_OFF';

    private const RESTRICTED = 'RESTRICTED_DUTY';

    private const RETURNED = 'RETURNED_FULL';

    public function __construct(private readonly EventPublisher $events) {}

    /**
     * Open a case for somebody hurt in an incident.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function open(int $companyId, string $incidentUuid, array $input = [], ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $incidentUuid, $input, $actorId) {
            $incident = DB::table('sheq_incidents')
                ->where('company_id', $companyId)->where('uuid', $incidentUuid)
                ->whereNull('deleted_at')->first();

            if ($incident === null) {
                throw new RuntimeException('Incident not found.');
            }

            $personId = isset($input['incident_person_id']) ? (int) $input['incident_person_id'] : null;

            $existing = DB::table('sheq_injury_cases')
                ->where('incident_id', $incident->id)
                ->where('incident_person_id', $personId)
                ->whereNull('deleted_at')->first(['uuid']);

            // Two cases for one person means two sets of day counts, and the incident would be told
            // whichever was written last.
            if ($existing !== null) {
                return $this->view($companyId, $existing->uuid);
            }

            $uuid = (string) Str::uuid();

            DB::table('sheq_injury_cases')->insert([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'reference' => $this->reference($companyId),
                'incident_id' => $incident->id,
                'incident_person_id' => $personId,
                'hr_person_id' => $input['hr_person_id'] ?? null,
                'injured_on' => Carbon::parse($input['injured_on'] ?? $incident->occurred_at)->toDateString(),
                'status' => 'OPEN',
                'expected_return_on' => isset($input['expected_return_on'])
                    ? Carbon::parse($input['expected_return_on'])->toDateString() : null,
                'diagnosis' => $input['diagnosis'] ?? null,
                'restrictions' => $input['restrictions'] ?? null,
                'created_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->events->publish($companyId, 'sheq.injury_case.opened', 'INJURY_CASE',
                (int) DB::table('sheq_injury_cases')->where('uuid', $uuid)->value('id'), [
                    'incident_id' => (int) $incident->id,
                    'reference' => $incident->reference,
                    'hr_person_id' => $input['hr_person_id'] ?? null,
                ]);

            return $this->view($companyId, $uuid);
        });
    }

    /**
     * Record a period off work, on restricted duty, or a return to full duty.
     *
     * @param  array{event_type:string, from_date:string, to_date?:string|null, practitioner?:string|null, notes?:string|null}  $input
     * @return array<string,mixed>
     */
    public function record(int $companyId, string $caseUuid, array $input, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $caseUuid, $input, $actorId) {
            $case = $this->find($companyId, $caseUuid);

            if ($case->status === 'CLOSED') {
                throw new RuntimeException('A closed case cannot be added to. Reopen it first.');
            }

            $type = strtoupper((string) $input['event_type']);
            $from = Carbon::parse($input['from_date'])->startOfDay();
            $to = isset($input['to_date']) && $input['to_date'] !== null
                ? Carbon::parse($input['to_date'])->startOfDay() : null;

            if ($to !== null && $to->lessThan($from)) {
                throw new RuntimeException('A period cannot end before it starts.');
            }

            if ($from->lessThan(Carbon::parse($case->injured_on)->startOfDay())) {
                // Time off before the injury belongs to something else, and counting it would
                // inflate a figure a claims assessor recalculates.
                throw new RuntimeException('A period cannot start before the injury.');
            }

            DB::table('sheq_injury_case_events')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'case_id' => $case->id,
                'event_type' => $type,
                'from_date' => $from->toDateString(),
                'to_date' => $to?->toDateString(),
                'practitioner' => $input['practitioner'] ?? null,
                'notes' => $input['notes'] ?? null,
                'recorded_by' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($type === self::RETURNED) {
                DB::table('sheq_injury_cases')->where('id', $case->id)->update([
                    'status' => 'RETURNED',
                    'returned_on' => $from->toDateString(),
                    'updated_at' => now(),
                ]);
            }

            $this->recount($companyId, (int) $case->id);

            return $this->view($companyId, $caseUuid);
        });
    }

    /** Close the case: nothing further is expected. */
    public function close(int $companyId, string $caseUuid, ?int $actorId = null): array
    {
        $case = $this->find($companyId, $caseUuid);

        $stillOpen = DB::table('sheq_injury_case_events')
            ->where('case_id', $case->id)
            ->whereIn('event_type', [self::LOST, self::RESTRICTED])
            ->whereNull('to_date')->exists();

        if ($stillOpen) {
            /*
             * An open-ended period means the person has not been recorded as back. Closing over it
             * would freeze the day count at whatever today happens to be, and that figure would
             * then be defended to an assessor.
             */
            throw new RuntimeException(
                'This person is still recorded as off work or on restricted duty with no end date. '
                .'Record their return before closing the case.'
            );
        }

        DB::table('sheq_injury_cases')->where('id', $case->id)->update([
            'status' => 'CLOSED',
            'closed_at' => Timestamps::utc(Carbon::now()),
            'closed_by' => $actorId,
            'updated_at' => now(),
        ]);

        $this->recount($companyId, (int) $case->id);

        return $this->view($companyId, $caseUuid);
    }

    /** Record the COIDA claim this injury was reported under. */
    public function recordCoidaClaim(int $companyId, string $caseUuid, string $claimNumber): array
    {
        $case = $this->find($companyId, $caseUuid);

        DB::table('sheq_injury_cases')->where('id', $case->id)->update([
            'coida_claim_number' => $claimNumber,
            'coida_reported_at' => Timestamps::utc(Carbon::now()),
            'updated_at' => now(),
        ]);

        return $this->view($companyId, $caseUuid);
    }

    /** Everyone not yet back on full duty. */
    public function stillAway(int $companyId): array
    {
        return DB::table('sheq_injury_cases as c')
            ->join('sheq_incidents as i', 'i.id', '=', 'c.incident_id')
            ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
            ->where('c.status', 'OPEN')
            ->orderBy('c.injured_on')
            ->get(['c.uuid', 'c.reference', 'c.injured_on', 'c.expected_return_on',
                'c.days_lost', 'c.days_restricted', 'c.hr_person_id',
                'i.reference as incident_reference'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Recount the days from the timeline and tell the incident.
     *
     * An open period counts to today, so the figure a manager sees is what it is right now rather
     * than what it was when somebody last opened the screen.
     */
    private function recount(int $companyId, int $caseId): void
    {
        $today = Carbon::today();

        $events = DB::table('sheq_injury_case_events')
            ->where('case_id', $caseId)
            ->whereIn('event_type', [self::LOST, self::RESTRICTED])
            ->get(['event_type', 'from_date', 'to_date']);

        $lost = 0;
        $restricted = 0;

        foreach ($events as $event) {
            $from = Carbon::parse($event->from_date)->startOfDay();
            $to = $event->to_date !== null ? Carbon::parse($event->to_date)->startOfDay() : $today;

            // Inclusive of both ends: a person booked off on Monday and back on Tuesday lost a day,
            // and a difference alone would call that zero.
            $days = (int) $from->diffInDays($to) + 1;

            if ($event->event_type === self::LOST) {
                $lost += $days;
            } else {
                $restricted += $days;
            }
        }

        DB::table('sheq_injury_cases')->where('id', $caseId)->update([
            'days_lost' => $lost,
            'days_restricted' => $restricted,
            'updated_at' => now(),
        ]);

        $this->tellTheIncident($companyId, $caseId);
    }

    /**
     * Push the totals onto the incident, which is what the statutory forms and the DIFR read.
     *
     * Summed across the incident's cases rather than taken from this one: an event that hurt three
     * people has three cases, and the incident's lost-day figure is all of them.
     */
    private function tellTheIncident(int $companyId, int $caseId): void
    {
        $incidentId = (int) DB::table('sheq_injury_cases')->where('id', $caseId)->value('incident_id');

        $totals = DB::table('sheq_injury_cases')
            ->where('incident_id', $incidentId)->whereNull('deleted_at')
            ->selectRaw('coalesce(sum(days_lost),0) as lost, coalesce(sum(days_restricted),0) as restricted')
            ->first();

        $before = DB::table('sheq_incidents')->where('id', $incidentId)
            ->first(['days_lost', 'days_restricted', 'reference']);

        DB::table('sheq_incidents')->where('id', $incidentId)->update([
            'days_lost' => (int) $totals->lost,
            'days_restricted' => (int) $totals->restricted,
            'updated_at' => now(),
        ]);

        /*
         * Lost days are what turns an injury into a reportable one under most rulebooks, so a
         * change in the total is a compliance event and not a bookkeeping one. Published rather
         * than acted on here: reclassification against the pack is a decision, and this module
         * already has a command for making it deliberately.
         */
        if ((int) ($before->days_lost ?? 0) !== (int) $totals->lost) {
            $this->events->publish($companyId, 'sheq.injury_case.days_changed', 'INCIDENT', $incidentId, [
                'incident_id' => $incidentId,
                'reference' => $before->reference ?? null,
                'days_lost' => (int) $totals->lost,
                'days_restricted' => (int) $totals->restricted,
                'previous_days_lost' => (int) ($before->days_lost ?? 0),
            ]);
        }
    }

    /** @return array<string,mixed> */
    public function view(int $companyId, string $caseUuid): array
    {
        $case = $this->find($companyId, $caseUuid);

        return [
            'uuid' => $case->uuid,
            'reference' => $case->reference,
            'status' => $case->status,
            'injured_on' => $case->injured_on,
            'expected_return_on' => $case->expected_return_on,
            'returned_on' => $case->returned_on,
            'days_lost' => (int) $case->days_lost,
            'days_restricted' => (int) $case->days_restricted,
            'restrictions' => $case->restrictions,
            'coida_claim_number' => $case->coida_claim_number,
            'timeline' => DB::table('sheq_injury_case_events')
                ->where('case_id', $case->id)->orderBy('from_date')
                ->get(['event_type', 'from_date', 'to_date', 'practitioner', 'notes'])
                ->map(fn ($e) => (array) $e)->all(),
        ];
    }

    private function reference(int $companyId): string
    {
        $next = DB::table('sheq_injury_cases')->where('company_id', $companyId)->count() + 1;

        return 'INJ-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function find(int $companyId, string $caseUuid): object
    {
        $case = DB::table('sheq_injury_cases')
            ->where('company_id', $companyId)->where('uuid', $caseUuid)
            ->whereNull('deleted_at')->first();

        if ($case === null) {
            throw new RuntimeException('Injury case not found.');
        }

        return $case;
    }
}
