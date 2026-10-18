<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Somebody has read this incident, and somebody now owns it.
 *
 * Between "reported" and "investigated" there was nothing. Reporting classified the incident,
 * raised its statutory obligations and notified per the escalation matrix, and then the record sat
 * waiting for a person to remember it. Hazards already had a triage stage; incidents, which matter
 * more, did not.
 *
 * Triage is two claims made by a named person at a known time: this is real and this is how bad it
 * could have been, and here is who is accountable for closing it. Both are needed. An incident
 * that is acknowledged but unowned is a read receipt, and one that is assigned without anybody
 * confirming the severity is assigned against a number the reporter guessed in a hurry.
 *
 * ── Re-rating changes the deadline ───────────────────────────────────────────
 *
 * Triage may correct the potential severity, and when it does the investigation deadline moves
 * with it. A first-aid case that turns out to have been a near-miss fatality must not keep the
 * thirty days it was given by someone who had not yet seen the scene.
 */
class IncidentTriage
{
    /** Matches the reporter's, so a re-rating cannot land on a different rule from the report. */
    private const DEFAULT_INVESTIGATION_SLA = [5 => 7, 4 => 7, 3 => 14, 2 => 30, 1 => 30];

    private const DEFAULT_INVESTIGATION_DAYS = 7;

    public function __construct(private readonly EventPublisher $events) {}

    /**
     * Acknowledge an incident and give it an owner.
     *
     * @param  array{assigned_to?:int|null, potential_severity?:int|null, notes?:string|null}  $input
     * @return array<string,mixed>
     */
    public function triage(int $companyId, string $incidentUuid, array $input, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $incidentUuid, $input, $actorId) {
            $incident = $this->find($companyId, $incidentUuid);

            if ($incident->status === 'CLOSED') {
                throw new RuntimeException('A closed incident cannot be triaged.');
            }

            $owner = isset($input['assigned_to']) ? (int) $input['assigned_to'] : null;

            if ($owner !== null && ! $this->belongsToCompany($companyId, $owner)) {
                throw new RuntimeException('That person is not in this company.');
            }

            $changes = [
                'acknowledged_at' => $incident->acknowledged_at
                    ?? Timestamps::utc(Carbon::now()),
                'acknowledged_by' => $incident->acknowledged_by ?? $actorId,
                'updated_at' => now(),
            ];

            if ($owner !== null) {
                $changes['assigned_to'] = $owner;
            }

            /*
             * A corrected rating moves the deadline, forwards or back. Re-rating upwards is the
             * case that matters — it is how a misjudged first-aid case gets the week it deserves —
             * but a correction downwards is a correction too, and quietly keeping the shorter
             * deadline would teach people not to correct anything.
             */
            if (array_key_exists('potential_severity', $input) && $input['potential_severity'] !== null) {
                $severity = (int) $input['potential_severity'];

                $changes['potential_severity'] = $severity;
                $changes['investigation_due_at'] = Timestamps::utc(
                    $this->dueFrom($companyId, $severity, $incident)
                );
            }

            DB::table('sheq_incidents')->where('id', $incident->id)->update($changes);

            $this->events->publish($companyId, 'sheq.incident.triaged', 'INCIDENT', (int) $incident->id, [
                'incident_id' => (int) $incident->id,
                'reference' => $incident->reference,
                'assigned_to' => $owner ?? ($incident->assigned_to !== null ? (int) $incident->assigned_to : null),
                'potential_severity' => $changes['potential_severity'] ?? $incident->potential_severity,
                'acknowledged_by' => $actorId,
                'notes' => $input['notes'] ?? null,
            ]);

            return $this->view($companyId, $incidentUuid);
        });
    }

    /**
     * Hand an incident to somebody else without re-opening the triage question.
     *
     * Not called, and recorded here so the next audit does not report it as a missing feature. It
     * is a one-line alias of `triage()` with only an owner in the payload, and the triage screen
     * already posts there — its button reads "Reassign" once an incident is acknowledged. Giving
     * it a route of its own would be a second endpoint doing the same write.
     */
    public function reassign(int $companyId, string $incidentUuid, int $ownerId, ?int $actorId = null): array
    {
        return $this->triage($companyId, $incidentUuid, ['assigned_to' => $ownerId], $actorId);
    }

    /** @return array<string,mixed> */
    public function view(int $companyId, string $incidentUuid): array
    {
        $incident = $this->find($companyId, $incidentUuid);

        return [
            'uuid' => $incident->uuid,
            'reference' => $incident->reference,
            'status' => $incident->status,
            'assigned_to' => $incident->assigned_to !== null ? (int) $incident->assigned_to : null,
            'acknowledged_at' => $incident->acknowledged_at,
            'acknowledged_by' => $incident->acknowledged_by !== null ? (int) $incident->acknowledged_by : null,
            'investigation_due_at' => $incident->investigation_due_at,
            'potential_severity' => $incident->potential_severity !== null
                ? (int) $incident->potential_severity : null,
        ];
    }

    /**
     * The deadline for this severity, measured from when the incident was reported.
     *
     * From `reported_at` rather than from now, so re-rating does not hand back time that has
     * already passed — an incident re-rated to could-have-killed on day six of a thirty-day clock
     * is one day from its deadline, not seven.
     */
    private function dueFrom(int $companyId, int $severity, object $incident): Carbon
    {
        $configured = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('investigation_sla_days');

        $days = is_string($configured) ? json_decode($configured, true) : (array) $configured;
        $days = is_array($days) ? $days : [];

        $forThis = (int) ($days[$severity]
            ?? $days[(string) $severity]
            ?? self::DEFAULT_INVESTIGATION_SLA[$severity]
            ?? self::DEFAULT_INVESTIGATION_DAYS);

        $from = $incident->reported_at ?? $incident->occurred_at ?? $incident->created_at;

        return Carbon::parse($from)->addDays($forThis);
    }

    private function belongsToCompany(int $companyId, int $userId): bool
    {
        return DB::table('users')->where('id', $userId)->where('company_id', $companyId)
            ->whereNull('deleted_at')->exists();
    }

    private function find(int $companyId, string $incidentUuid): object
    {
        $incident = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('uuid', $incidentUuid)
            ->whereNull('deleted_at')->first();

        if ($incident === null) {
            throw new RuntimeException('Incident not found.');
        }

        return $incident;
    }
}
