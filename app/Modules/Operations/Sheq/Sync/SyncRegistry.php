<?php

namespace App\Modules\Operations\Sheq\Sync;

use App\Modules\Operations\Sheq\Services\ActionService;
use App\Modules\Operations\Sheq\Services\HazardService;
use App\Modules\Operations\Sheq\Services\IncidentReporter;
use InvalidArgumentException;

/**
 * Which entities a field device may sync, and on what terms.
 *
 * Everything the merge and the transition validator need lives here as data. The important column
 * is `syncable`: a client can only ever write these fields, so no offline payload can reach
 * `statutory_category`, `is_reportable` or `regulatory_pack_version`. Those are the pack engine's
 * conclusions (SHEQ-REG-2), and a device that could overwrite them could rewrite the tenant's legal
 * position from a phone with no signal.
 *
 * `appendOnly` unions a jsonb column on the row itself, and is only inspection responses.
 * `childCollections` is the separate-table equivalent (REG-FIX-8): witness statements and
 * investigation causes, which are the collections two people genuinely fill in at the same
 * incident at the same time. Evidence is not here because it already has a better path — the
 * chunked, resumable file upload at `api/v1/sheq/files`.
 */
class SyncRegistry
{
    /** @return array<string,EntitySpec> */
    public function all(): array
    {
        return [
            'INCIDENT' => new EntitySpec(
                type: 'INCIDENT',
                table: 'sheq_incidents',
                syncable: [
                    'description', 'immediate_action', 'location_text', 'location_lat', 'location_lng',
                    'occurred_at', 'discovered_at', 'incident_type', 'activity_at_time',
                    'actual_severity', 'potential_severity', 'outcome', 'treatment_level',
                    'site_id', 'is_confidential',
                ],
                /**
                 * Still empty, and still correctly so: `appendOnly` unions a jsonb column on
                 * this row, and the incident's collections are separate tables. They are declared
                 * as `childCollections` below, which has its own append path (REG-FIX-8).
                 */
                appendOnly: [],
                // REPORTED is still the reporter's own draft: nobody has investigated it yet.
                draftStates: ['DRAFT', 'REPORTED'],
                transitions: [
                    'REPORTED' => ['UNDER_INVESTIGATION', 'REJECTED'],
                    'UNDER_INVESTIGATION' => ['ACTIONS_PENDING', 'CLOSED', 'REJECTED'],
                    'ACTIONS_PENDING' => ['CLOSED'],
                    'CLOSED' => [],
                    'REJECTED' => [],
                ],
                createVia: [IncidentReporter::class, 'report'],
                /*
                 * REG-FIX-8. Two of the three collections the requirement names; the third,
                 * evidence, already syncs through the chunked file upload at
                 * `api/v1/sheq/files`, which carries `client_id`, `device_id`,
                 * `chunks_received` and `upload_status` — a resumable path a merge cannot
                 * improve on, and one that must not be duplicated here.
                 */
                childCollections: [
                    new ChildCollectionSpec(
                        name: 'people',
                        table: 'sheq_incident_people',
                        parentKey: 'incident_id',
                        /*
                         * `statement_signed_at` is deliberately absent. A phone must not be able
                         * to assert that a statement was signed — that is a fact about a person
                         * putting their name to something, and a device that could claim it could
                         * manufacture signed evidence.
                         */
                        writable: ['role', 'hr_person_id', 'contractor_id', 'external_name',
                            'external_contact', 'body_part', 'injury_nature', 'statement'],
                        defaults: ['role' => 'WITNESS'],
                        /*
                         * One of a name or a person, enforced in the appender rather than here,
                         * because `required` means "all of these" and this is "at least one of".
                         * A witness with neither is a blank line in the statement list of a
                         * serious incident.
                         */
                        required: ['role'],
                        /*
                         * Written by a device, never sent back to one. Injury detail and witness
                         * statements are POPIA s26 special personal information encrypted at
                         * column level; a device that uploaded one has no need to download
                         * everybody else's.
                         */
                        redacted: ['injury_nature', 'body_part', 'statement', 'external_contact'],
                    ),
                    new ChildCollectionSpec(
                        name: 'causes',
                        table: 'sheq_investigation_causes',
                        // Causes hang off the investigation, not the incident. Resolved through
                        // it on the way in, and refused with a reason where none is open — a root
                        // cause before anybody has investigated is not a finding.
                        parentKey: 'investigation_id',
                        writable: ['category', 'cause_type', 'description', 'evidence', 'depth'],
                        defaults: ['cause_type' => 'CONTRIBUTING'],
                        required: ['description'],
                        viaParent: ['table' => 'sheq_investigations', 'foreign' => 'incident_id'],
                    ),
                ],
            ),

            'HAZARD' => new EntitySpec(
                type: 'HAZARD',
                table: 'sheq_hazards',
                syncable: [
                    'description', 'hazard_category', 'location_text', 'location_lat', 'location_lng',
                    'site_id', 'reported_at', 'severity_est',
                ],
                appendOnly: [],
                draftStates: ['DRAFT', 'OPEN'],
                transitions: [
                    'OPEN' => ['TRIAGED', 'REJECTED'],
                    'TRIAGED' => ['CLOSED'],
                    'CLOSED' => [],
                    'REJECTED' => [],
                ],
                createVia: [HazardService::class, 'report'],
            ),

            'ACTION' => new EntitySpec(
                type: 'ACTION',
                table: 'sheq_actions',
                syncable: ['title', 'description', 'completion_notes', 'due_date', 'priority'],
                appendOnly: [],
                draftStates: ['DRAFT', 'OPEN'],
                transitions: [
                    'OPEN' => ['IN_PROGRESS'],
                    'IN_PROGRESS' => ['COMPLETED'],
                    // Verification is a second person's job, so a device cannot self-verify.
                    'COMPLETED' => [],
                    'VERIFIED' => [],
                    'CLOSED' => [],
                ],
                createVia: [ActionService::class, 'create'],
            ),

            'INSPECTION' => new EntitySpec(
                type: 'INSPECTION',
                table: 'sheq_inspections',
                syncable: ['responses', 'started_at', 'completed_at', 'signature',
                    'location_lat', 'location_lng', 'device_id'],
                /**
                 * The one that matters most in the field. A 200-question inspection gets answered
                 * across sessions and sometimes by two people on two devices; question 12 answered
                 * on one and question 88 on the other is not a conflict, and record-level merge
                 * would throw one of them away.
                 */
                appendOnly: ['responses'],
                jsonFields: ['responses'],
                draftStates: ['DRAFT', 'IN_PROGRESS'],
                transitions: [
                    'SCHEDULED' => ['IN_PROGRESS'],
                    'IN_PROGRESS' => ['COMPLETED'],
                    'COMPLETED' => [],
                ],
                // An inspection is scheduled online from a template, then answered in the field.
                // Creating one from a device is not a flow the module supports, so there is no
                // handler and a CREATE for it is refused rather than half-built.
                createVia: null,
            ),
        ];
    }

    public function get(string $type): EntitySpec
    {
        $spec = $this->all()[strtoupper($type)] ?? null;

        if ($spec === null) {
            // Named explicitly: a client asking to sync something unknown is a version mismatch,
            // and "unknown entity" is a far better answer than silently syncing nothing.
            throw new InvalidArgumentException("'{$type}' is not a syncable SHEQ entity.");
        }

        return $spec;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->all());
    }
}
