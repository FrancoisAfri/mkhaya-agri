<?php

namespace App\Modules\Operations\Sheq\Events;

use InvalidArgumentException;

/**
 * The published event schema registry (§8.2, SHEQ-INT-1).
 *
 * Every event SHEQ publishes is declared here with its version and the payload keys a consumer can
 * rely on. SHEQ-INT-1 requires schemas to be registered and backwards-compatible within a major
 * version, and a registry that only exists in documentation is one that drifts the first time
 * somebody adds a field in a hurry.
 *
 * The rule this enforces: **you may add optional keys to a version; you may not remove or repurpose
 * one.** Removing a key means a new version, because a consumer that has been reading
 * `statutory_category` for a year will break silently otherwise — and these consumers are other
 * people's integrations, not our own code.
 *
 * `required` is what the publisher is checked against at write time. Publishing an event missing a
 * declared key fails loudly here rather than reaching a subscriber as a null they did not expect.
 */
class EventRegistry
{
    /**
     * @return array<string,array{version:int, required:list<string>, describes:string}>
     */
    public function all(): array
    {
        return [
            // ── Incidents ────────────────────────────────────────────────────────────────────
            'sheq.incident.reported' => [
                'version' => 1,
                'required' => ['incident_id', 'site_id', 'type', 'actual_severity',
                    'potential_severity', 'statutory_category', 'is_reportable', 'occurred_at'],
                'describes' => 'An incident was filed. Carries the classification so a consumer '
                    .'need not ask what it means.',
            ],
            'sheq.incident.classified' => [
                'version' => 1,
                'required' => ['incident_id', 'statutory_category', 'obligations'],
                'describes' => 'Classification was refined — asynchronously, or after a pack update.',
            ],
            'sheq.incident.closed' => [
                'version' => 1,
                'required' => ['incident_id', 'closed_at', 'root_causes', 'actions'],
                'describes' => 'The investigation finished and the record closed.',
            ],

            // ── Statutory obligations ────────────────────────────────────────────────────────
            'sheq.statutory_obligation.due' => [
                'version' => 1,
                'required' => ['obligation_id', 'subject_id', 'due_at', 'responsible_role'],
                'describes' => 'A statutory deadline is approaching.',
            ],
            'sheq.statutory_obligation.breached' => [
                'version' => 1,
                'required' => ['obligation_id', 'subject_id', 'breached_at'],
                'describes' => 'A statutory deadline passed undischarged. Consumers should treat '
                    .'this as an escalation, not a notification.',
            ],

            'sheq.incident.triaged' => [
                'version' => 1,
                'required' => ['incident_id'],
                'describes' => 'Somebody read the incident, confirmed how bad it could have been, '
                    .'and took ownership of getting it to closed findings.',
            ],
            'sheq.incident.triage_overdue' => [
                'version' => 1,
                'required' => ['incident_id', 'reported_at', 'sla_hours'],
                'describes' => 'An incident nobody has acknowledged, past the hour it should have '
                    .'been read by. Reporting notifies the escalation matrix, but a notification is '
                    .'not a task — this is what notices when nobody acted on one.',
            ],
            'sheq.incident.investigation_overdue' => [
                'version' => 1,
                'required' => ['incident_id', 'due_at', 'days_overdue'],
                'describes' => 'An incident passed the date by which approved findings should have '
                    .'existed. Approved, not opened: an investigation left in draft for a month is '
                    .'the same silence as one never started.',
            ],

            'sheq.injury_case.opened' => [
                'version' => 1,
                'required' => ['incident_id'],
                'describes' => 'Somebody hurt in an incident is being followed until they are back '
                    .'on full duty.',
            ],
            'sheq.injury_case.days_changed' => [
                'version' => 1,
                'required' => ['incident_id', 'days_lost'],
                'describes' => 'The lost-day total on an incident moved. Lost days are what turn an '
                    .'injury into a reportable one under most rulebooks, so this is a compliance '
                    .'event rather than a bookkeeping one.',
            ],
            'sheq.safety_alert.issued' => [
                'version' => 1,
                'required' => ['alert_id', 'title'],
                'describes' => 'A finding was carried to the people exposed to the same hazard. '
                    .'The point of investigating: a conclusion that reaches nobody is an audit '
                    .'artefact, and this is what makes it reach the next crew doing the same job.',
            ],

            // ── Hazards and actions ──────────────────────────────────────────────────────────
            'sheq.hazard.reported' => [
                'version' => 1,
                'required' => ['hazard_id', 'site_id', 'category'],
                'describes' => 'Somebody reported an unsafe condition.',
            ],
            'sheq.action.created' => [
                'version' => 1,
                'required' => ['action_id', 'title', 'due_date'],
                'describes' => 'A corrective or preventive action was raised.',
            ],
            'sheq.action.assigned' => [
                'version' => 1,
                'required' => ['action_id', 'assigned_to'],
                'describes' => 'An action changed hands.',
            ],
            'sheq.action.overdue' => [
                'version' => 1,
                'required' => ['action_id', 'due_date', 'days_overdue'],
                'describes' => 'An action passed its due date without being completed.',
            ],
            'sheq.action.effectiveness_review_due' => [
                'version' => 1,
                'required' => ['action_id', 'due_date'],
                'describes' => 'A completed control reached the date it was to be checked against '
                    .'reality, and nobody has recorded whether it worked. The last step of CAPA and '
                    .'the easiest to skip, because by then the incident has been closed for months.',
            ],
            'sheq.action.closed' => [
                'version' => 1,
                'required' => ['action_id', 'closed_at'],
                'describes' => 'An action was verified and closed.',
            ],

            // ── Competency ───────────────────────────────────────────────────────────────────
            'sheq.competency.required' => [
                'version' => 1,
                'required' => ['hr_person_id', 'competency_id'],
                'describes' => 'A person needs a competency they do not hold. The LMS consumes this '
                    .'to enrol them.',
            ],
            'sheq.competency.expiring' => [
                'version' => 1,
                'required' => ['hr_person_id', 'competency_id', 'expires_at', 'days_remaining'],
                'describes' => 'A competency lapses soon.',
            ],
            'sheq.competency.expired' => [
                'version' => 1,
                'required' => ['hr_person_id', 'competency_id'],
                'describes' => 'A competency lapsed. Where it blocks work, the holder must stop.',
            ],

            // ── Permits, inspections, contractors ────────────────────────────────────────────
            'sheq.permit.approved' => [
                'version' => 1, 'required' => ['permit_id', 'permit_type'],
                'describes' => 'A permit cleared its approvals.',
            ],
            'sheq.permit.activated' => [
                'version' => 1, 'required' => ['permit_id', 'valid_to'],
                'describes' => 'Work under the permit began.',
            ],
            'sheq.permit.expiring' => [
                'version' => 1, 'required' => ['permit_id', 'valid_to', 'minutes_left'],
                'describes' => 'A permit is about to pass its validity, in time for the issuer to '
                    .'extend it or for the work to be wound up. Minutes, not days: a permit is '
                    .'valid for an afternoon, so the warning that still leaves room to act is an '
                    .'hour out rather than a month.',
            ],
            'sheq.permit.expired' => [
                'version' => 1, 'required' => ['permit_id'],
                'describes' => 'A permit passed its validity. Work under it is no longer authorised.',
            ],
            'sheq.permit.suspended' => [
                'version' => 1, 'required' => ['permit_id', 'reason'],
                'describes' => 'A permit was suspended — work must stop immediately.',
            ],
            'sheq.inspection.completed' => [
                'version' => 1,
                'required' => ['inspection_id', 'subject_id', 'score', 'passed', 'failed_items'],
                'describes' => 'An inspection was completed, with what failed.',
            ],
            'sheq.contractor.blocked' => [
                'version' => 1, 'required' => ['contractor_id', 'reasons'],
                'describes' => 'A contractor may no longer be on site.',
            ],
            /*
             * INT-5's remaining five. The requirement lists eight events an integration should be
             * able to subscribe to and only three existed, so a gate controller could be told
             * when a contractor was blocked and never when the block was lifted — which leaves a
             * crew standing at a boom that the system has already decided to open.
             */
            'sheq.contractor.approved' => [
                'version' => 1, 'required' => ['contractor_id', 'status'],
                'describes' => 'A contractor may be on site again. The counterpart to '
                    .'`blocked`, and the one an access-control system needs most: without it a '
                    .'gate stays shut after the paperwork is fixed.',
            ],
            'sheq.contractor.worker_blocked' => [
                'version' => 1, 'required' => ['contractor_id', 'worker_id', 'reasons'],
                'describes' => 'One named worker may no longer be on site, while their '
                    .'contractor still may. A different fact from blocking the contractor, and '
                    .'acting on the wrong one of the two turns away a whole crew or none of it.',
            ],
            'sheq.compliance.changed' => [
                'version' => 1,
                'required' => ['subject_type', 'subject_id', 'from', 'to'],
                'describes' => 'A compliance verdict moved, in either direction, for a '
                    .'contractor or a worker. Broader than blocked/approved: a move from '
                    .'COMPLIANT to NON_COMPLIANT matters to a planner before it reaches a gate.',
            ],
            'sheq.access.approved' => [
                'version' => 1,
                'required' => ['subject_type', 'subject_id', 'site_id', 'occurred_at'],
                'describes' => 'A pass was scanned and the person was admitted. High volume by '
                    .'nature — one per entry per person per day — so it is only published where '
                    .'something is actually subscribed to it.',
            ],
            'sheq.access.denied' => [
                'version' => 1,
                'required' => ['subject_type', 'subject_id', 'site_id', 'occurred_at', 'reasons'],
                'describes' => 'A pass was scanned and the person was refused, with the reasons. '
                    .'The event a turnstile integration exists for.',
            ],
            'sheq.risk.threshold_breached' => [
                'version' => 1, 'required' => ['site_id', 'metric', 'value', 'threshold'],
                'describes' => 'A leading or lagging indicator crossed its threshold.',
            ],
        ];
    }

    /** @return array{version:int, required:list<string>, describes:string} */
    public function get(string $eventType): array
    {
        $schema = $this->all()[$eventType] ?? null;

        if ($schema === null) {
            throw new InvalidArgumentException(
                "'{$eventType}' is not a registered SHEQ event. Add it to the registry before "
                .'publishing it — an unregistered event has no schema for a consumer to build against.'
            );
        }

        return $schema;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->all());
    }

    /**
     * Check a payload against the declared schema.
     *
     * @return list<string> the required keys that are missing
     */
    public function missingKeys(string $eventType, array $payload): array
    {
        return array_values(array_diff($this->get($eventType)['required'], array_keys($payload)));
    }
}
