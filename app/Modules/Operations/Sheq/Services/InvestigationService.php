<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Investigating an incident (SHEQ-INC-7 … SHEQ-INC-10).
 *
 * The rules here exist because investigations fail in predictable ways, and each guard is aimed at
 * one of them:
 *
 * - **Depth follows potential, not outcome** (SHEQ-INC-7). A near miss that could have killed
 *   somebody gets the rigour of a fatality. Judging by what happened rather than what nearly
 *   happened is how an organisation learns nothing until the day it gets unlucky.
 * - **A method must be completed, not merely chosen** (SHEQ-INC-8). Selecting "ICAM" and writing
 *   two lines is not an ICAM investigation, so each method declares a minimum and submission is
 *   refused below it.
 * - **"The operator made a mistake" is not a root cause.** ICAM's individual-action layer cannot
 *   be the deepest finding; an investigation whose only root cause blames a person is refused.
 * - **Closure is blocked by open work** (SHEQ-INC-9). An incident closed while its actions are
 *   outstanding and its statutory obligations pending is a record saying the organisation dealt
 *   with something it did not.
 * - **Reopening supersedes, never edits** (SHEQ-INC-10). What was concluded in March stays
 *   readable after April contradicts it.
 */
class InvestigationService
{
    /**
     * Minimum causes each method must produce before it can be submitted.
     *
     * A 5-Why that stops at two levels has not reached a system cause; ICAM without an
     * organisational finding has described an event rather than explained it.
     */
    private const METHOD_MINIMUMS = [
        'FIVE_WHY' => 3,
        'FISHBONE' => 3,
        'ICAM' => 4,
        'BOWTIE' => 3,
        'TAPROOT' => 4,
    ];

    /**
     * Potential severity → the rigour the pack demands (SHEQ-INC-7).
     *
     * Deliberately a floor rather than an exact match: an investigator may always go deeper than
     * required, never shallower.
     */
    private const DEPTH_BY_POTENTIAL = [
        5 => 'ICAM',       // could have killed
        4 => 'ICAM',
        3 => 'FISHBONE',
        2 => 'FIVE_WHY',
        1 => 'FIVE_WHY',
    ];

    /** ICAM's layers, shallowest first. The last one is where a real fix lives. */
    private const CAUSE_LAYERS = [
        'ABSENT_FAILED_DEFENCE',
        'INDIVIDUAL_TEAM_ACTION',
        'TASK_ENVIRONMENT',
        'ORGANISATIONAL',
    ];

    public function __construct(
        private readonly EventPublisher $events,
        private readonly \App\Modules\Operations\Sheq\Approvals\ApprovalService $approvals,
    ) {}

    /**
     * Open an investigation, with its required depth stamped on from the incident's potential.
     *
     * @return array<string,mixed>
     */
    public function open(int $companyId, string $incidentUuid, ?string $method = null,
        ?int $leadInvestigatorId = null): array
    {
        return DB::transaction(function () use ($companyId, $incidentUuid, $method, $leadInvestigatorId) {
            $incident = $this->incident($companyId, $incidentUuid);

            $live = DB::table('sheq_investigations')
                ->where('company_id', $companyId)->where('incident_id', $incident->id)
                ->whereIn('status', ['DRAFT', 'PENDING_APPROVAL', 'APPROVED'])
                ->first(['uuid']);

            if ($live !== null) {
                // Reopening is a deliberate act with a reason (SHEQ-INC-10), not a side effect of
                // opening again.
                throw new RuntimeException(
                    'This incident already has a live investigation. Reopen it if it needs revisiting.'
                );
            }

            $potential = (int) ($incident->potential_severity ?? $incident->actual_severity ?? 1);
            $required = self::DEPTH_BY_POTENTIAL[$potential] ?? 'FIVE_WHY';
            $chosen = strtoupper($method ?? $required);

            $this->assertKnownMethod($chosen);

            $uuid = (string) Str::uuid();

            $id = DB::table('sheq_investigations')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'incident_id' => $incident->id,
                'revision' => 1,
                'method' => $chosen,
                'required_method' => $required,
                'minimum_causes' => self::METHOD_MINIMUMS[$required] ?? 3,
                'potential_severity' => $potential,
                'status' => 'DRAFT',
                'lead_investigator_id' => $leadInvestigatorId,
                'opened_at' => Timestamps::utc(Carbon::now()),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('sheq_incidents')->where('id', $incident->id)->update([
                'status' => 'UNDER_INVESTIGATION',
                'investigation_method' => $chosen,
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $leadInvestigatorId, 'investigation.opened', $id, null, [
                'method' => $chosen, 'required' => $required, 'potential_severity' => $potential,
            ]);

            return $this->view($companyId, $uuid);
        });
    }

    /**
     * Open an investigation into something that is not an incident (QLT-5).
     *
     * A non-conformance needs the same discipline an incident does: a named method, a minimum
     * number of causes for that method, and the rule that "the operator made a mistake" is not a
     * root cause. Building a second engine for quality would mean two sets of those rules, and
     * within a release they would disagree about what an adequate investigation is — one would
     * accept a 5-Why that stopped at two levels and the other would not.
     *
     * `$depth` stands in for an incident's potential severity. It is what decides which method is
     * REQUIRED, so a critical non-conformance gets an ICAM rather than three whys. The caller
     * maps its own severity onto the same 1-5 scale, because the depth table belongs here with
     * the rest of the method rules.
     *
     * @return array<string,mixed>
     */
    public function openForSubject(int $companyId, string $subjectType, int $subjectId,
        int $depth, ?string $method = null, ?int $leadInvestigatorId = null): array
    {
        $subjectType = strtoupper(trim($subjectType));

        return DB::transaction(function () use (
            $companyId, $subjectType, $subjectId, $depth, $method, $leadInvestigatorId
        ) {
            $live = DB::table('sheq_investigations')
                ->where('company_id', $companyId)
                ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
                ->whereIn('status', ['DRAFT', 'PENDING_APPROVAL', 'APPROVED'])
                ->first(['uuid']);

            if ($live !== null) {
                // Reopening is a deliberate act with a reason, not a side effect of opening
                // again — the same rule incidents have had since this service was written.
                throw new RuntimeException(
                    'This already has a live investigation. Reopen it if it needs revisiting.'
                );
            }

            $potential = max(1, min(5, $depth));
            $required = self::DEPTH_BY_POTENTIAL[$potential] ?? 'FIVE_WHY';
            $chosen = strtoupper($method ?? $required);

            $this->assertKnownMethod($chosen);

            $uuid = (string) Str::uuid();

            $id = DB::table('sheq_investigations')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                // No incident. This is why the column had to become nullable.
                'incident_id' => null,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'revision' => 1,
                'method' => $chosen,
                'required_method' => $required,
                'minimum_causes' => self::METHOD_MINIMUMS[$required] ?? 3,
                'potential_severity' => $potential,
                'status' => 'DRAFT',
                'lead_investigator_id' => $leadInvestigatorId,
                'opened_at' => Timestamps::utc(Carbon::now()),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->audit($companyId, $leadInvestigatorId, 'investigation.opened', $id, null, [
                'method' => $chosen, 'required' => $required,
                'subject_type' => $subjectType, 'subject_id' => $subjectId,
            ]);

            return $this->view($companyId, $uuid);
        });
    }

    /**
     * The live investigation into a subject, or null.
     *
     * @return array<string,mixed>|null
     */
    public function forSubject(int $companyId, string $subjectType, int $subjectId): ?array
    {
        $row = DB::table('sheq_investigations')
            ->where('company_id', $companyId)
            ->where('subject_type', strtoupper($subjectType))->where('subject_id', $subjectId)
            ->orderByDesc('revision')->orderByDesc('id')
            ->first(['uuid']);

        return $row === null ? null : $this->view($companyId, $row->uuid);
    }

    /** Record a cause. Causes accumulate; the method decides how many are enough. */
    public function addCause(int $companyId, string $investigationUuid, array $input,
        ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $investigationUuid, $input, $actorId) {
            $investigation = $this->find($companyId, $investigationUuid);

            $this->assertEditable($investigation);

            $category = strtoupper((string) ($input['category'] ?? ''));

            if (! in_array($category, self::CAUSE_LAYERS, true)) {
                throw new RuntimeException(
                    "'{$category}' is not an ICAM layer. Expected one of: "
                    .implode(', ', self::CAUSE_LAYERS).'.'
                );
            }

            if (trim((string) ($input['description'] ?? '')) === '') {
                throw new RuntimeException('A cause needs a description.');
            }

            DB::table('sheq_investigation_causes')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'investigation_id' => $investigation->id,
                'category' => $category,
                'cause_type' => strtoupper($input['cause_type'] ?? 'CONTRIBUTING') === 'ROOT'
                    ? 'ROOT' : 'CONTRIBUTING',
                'description' => trim($input['description']),
                'evidence' => $input['evidence'] ?? null,
                'depth' => $input['depth'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'investigation.cause_added', $investigation->id,
                null, ['category' => $category]);

            return $this->view($companyId, $investigationUuid);
        });
    }

    /** Update the narrative and the method's own working (the 5-Why chain, the ICAM grid). */
    public function update(int $companyId, string $investigationUuid, array $input,
        ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $investigationUuid, $input) {
            $investigation = $this->find($companyId, $investigationUuid);
            $this->assertEditable($investigation);

            DB::table('sheq_investigations')->where('id', $investigation->id)->update(array_filter([
                'summary' => $input['summary'] ?? null,
                'sequence_of_events' => $input['sequence_of_events'] ?? null,
                'method_payload' => isset($input['method_payload'])
                    ? json_encode($input['method_payload']) : null,
            ], fn ($v) => $v !== null) + ['updated_at' => now()]);

            return $this->view($companyId, $investigationUuid);
        });
    }

    /**
     * Submit for approval (SHEQ-INC-8: minimum completeness enforced here).
     *
     * @return array<string,mixed>
     */
    public function submit(int $companyId, string $investigationUuid, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $investigationUuid, $actorId) {
            $investigation = $this->find($companyId, $investigationUuid);
            $this->assertEditable($investigation);

            $shortfalls = $this->completenessShortfalls($companyId, $investigation);

            if ($shortfalls !== []) {
                throw new RuntimeException(implode(' ', $shortfalls));
            }

            DB::table('sheq_investigations')->where('id', $investigation->id)->update([
                'status' => 'PENDING_APPROVAL',
                'submitted_at' => Timestamps::utc(Carbon::now()),
                'updated_at' => now(),
            ]);

            DB::table('sheq_incidents')->where('id', $investigation->incident_id)
                ->update(['status' => 'PENDING_APPROVAL', 'updated_at' => now()]);

            $this->audit($companyId, $actorId, 'investigation.submitted', $investigation->id,
                ['status' => 'DRAFT'], ['status' => 'PENDING_APPROVAL']);

            return $this->view($companyId, $investigationUuid);
        });
    }

    /**
     * Approve the findings.
     *
     * Separate from submission on purpose: the person who investigated should not be the only one
     * who decides the investigation is sound.
     */
    public function approve(int $companyId, string $investigationUuid, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $investigationUuid, $actorId) {
            $investigation = $this->find($companyId, $investigationUuid);

            if ($investigation->status !== 'PENDING_APPROVAL') {
                throw new RuntimeException('Only an investigation awaiting approval can be approved.');
            }

            /*
             * Approval is a second pair of eyes, so it needs a first pair to compare against.
             *
             * The check used to be skipped entirely when the actor was unknown, which made "who
             * approved this" answerable with "nobody" — and an unattributed approval is exactly the
             * one a lead investigator would use to sign off their own findings. The HTTP path
             * always has a user; an API or console caller may not, and that was the hole.
             */
            if ($actorId === null) {
                throw new RuntimeException(
                    'An investigation must be approved by a named person, so that it is not the '
                    .'lead investigator approving their own findings.'
                );
            }

            if ((int) $investigation->lead_investigator_id === $actorId) {
                throw new RuntimeException(
                    'The lead investigator cannot approve their own investigation.'
                );
            }

            DB::table('sheq_investigations')->where('id', $investigation->id)->update([
                'status' => 'APPROVED',
                'approved_at' => Timestamps::utc(Carbon::now()),
                'approved_by' => $actorId,
                'updated_at' => now(),
            ]);

            DB::table('sheq_incidents')->where('id', $investigation->incident_id)
                ->update(['status' => 'ACTIONS_PENDING', 'updated_at' => now()]);

            /*
             * Raised when the findings are approved, which is the first moment closure is even
             * possible. Raising it at report time would put an approval in somebody's queue for an
             * incident nobody has investigated yet.
             */
            $this->approvals->openIfConfigured($companyId, [
                'subject_type' => 'INCIDENT_CLOSURE',
                'subject_id' => (int) $investigation->incident_id,
                // Looked up rather than assumed off the investigation row, which does not carry
                // it — an approval labelled "Closure of incident " helps nobody decide anything.
                'subject_label' => 'Closure of incident '.(DB::table('sheq_incidents')
                    ->where('id', $investigation->incident_id)->value('reference') ?? ''),
                'requested_by' => (int) ($actorId ?? 0),
            ]);

            $this->audit($companyId, $actorId, 'investigation.approved', $investigation->id,
                ['status' => 'PENDING_APPROVAL'], ['status' => 'APPROVED']);

            return $this->view($companyId, $investigationUuid);
        });
    }

    /**
     * What still stops this incident being closed (SHEQ-INC-9).
     *
     * Returned as reasons rather than a boolean, because "you cannot close this" without saying
     * why is the kind of block that gets worked around.
     *
     * @return list<string>
     */
    public function closureBlockers(int $companyId, string $incidentUuid): array
    {
        $incident = $this->incident($companyId, $incidentUuid);
        $blockers = [];

        $openActions = DB::table('sheq_actions')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('source_type', 'INCIDENT')->where('source_id', $incident->id)
            ->whereNotIn('status', ['VERIFIED', 'VERIFIED_CLOSED', 'CLOSED'])
            ->count();

        if ($openActions > 0) {
            $blockers[] = "{$openActions} corrective action(s) are still open.";
        }

        $pendingObligations = DB::table('sheq_statutory_obligations')
            ->where('company_id', $companyId)
            ->where('subject_type', 'INCIDENT')->where('subject_id', $incident->id)
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->count();

        if ($pendingObligations > 0) {
            $blockers[] = "{$pendingObligations} statutory obligation(s) are undischarged.";
        }

        $approved = DB::table('sheq_investigations')
            ->where('company_id', $companyId)->where('incident_id', $incident->id)
            ->where('status', 'APPROVED')->first(['id']);

        if ($approved === null) {
            $blockers[] = 'The investigation has no approved findings.';
        } else {
            $roots = DB::table('sheq_investigation_causes')
                ->where('investigation_id', $approved->id)->where('cause_type', 'ROOT')->count();

            if ($roots === 0) {
                $blockers[] = 'The investigation has no approved root cause.';
            }
        }

        return $blockers;
    }

    /** Close the incident, if nothing outstanding stops it. */
    public function closeIncident(int $companyId, string $incidentUuid, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $incidentUuid, $actorId) {
            $blockers = $this->closureBlockers($companyId, $incidentUuid);

            if ($blockers !== []) {
                throw new RuntimeException(
                    'This incident cannot be closed yet. '.implode(' ', $blockers)
                );
            }

            $incident = $this->incident($companyId, $incidentUuid);

            /*
             * An approval workflow for closure, where the tenant configured one.
             *
             * The Approvals screen has always offered "who agrees an incident may be closed" and
             * nothing read it. Checked here rather than opened here: the chain is opened when the
             * findings are approved, and this is the moment it has to be honoured.
             *
             * With no workflow configured this is a no-op, so a tenant that set none up keeps the
             * behaviour they had.
             */
            $blocked = $this->approvals->blockingReason(
                $companyId, 'INCIDENT_CLOSURE', (int) $incident->id, null, $incident->site_id);

            if ($blocked !== null) {
                throw new RuntimeException($blocked);
            }


            DB::table('sheq_incidents')->where('id', $incident->id)->update([
                'status' => 'CLOSED',
                'closed_at' => Timestamps::utc(Carbon::now()),
                'closed_by' => $actorId,
                'updated_at' => now(),
            ]);

            $investigation = DB::table('sheq_investigations')
                ->where('company_id', $companyId)->where('incident_id', $incident->id)
                ->where('status', 'APPROVED')->first(['id']);

            $this->events->publish($companyId, 'sheq.incident.closed', 'INCIDENT',
                (int) $incident->id, [
                    'incident_id' => (int) $incident->id,
                    'reference' => $incident->reference,
                    'closed_at' => Timestamps::utc(Carbon::now())->toIso8601String(),
                    'root_causes' => DB::table('sheq_investigation_causes')
                        ->where('investigation_id', $investigation?->id)
                        ->where('cause_type', 'ROOT')
                        ->pluck('description')->all(),
                    'actions' => DB::table('sheq_actions')
                        ->where('company_id', $companyId)
                        ->where('source_type', 'INCIDENT')->where('source_id', $incident->id)
                        ->pluck('reference')->all(),
                ], ['actor_id' => $actorId]);

            $this->audit($companyId, $actorId, 'incident.closed', (int) $incident->id,
                ['status' => $incident->status], ['status' => 'CLOSED']);

            return ['incident_uuid' => $incidentUuid, 'status' => 'CLOSED'];
        });
    }

    /**
     * Reopen (SHEQ-INC-10): a new revision, the prior one preserved verbatim.
     *
     * The old investigation is marked superseded rather than edited, because what was concluded
     * then is evidence — including, sometimes, evidence that the first investigation was wrong.
     */
    public function reopen(int $companyId, string $incidentUuid, string $reason,
        ?int $actorId = null): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Reopening an investigation needs a reason.');
        }

        return DB::transaction(function () use ($companyId, $incidentUuid, $reason, $actorId) {
            $incident = $this->incident($companyId, $incidentUuid);

            $previous = DB::table('sheq_investigations')
                ->where('company_id', $companyId)->where('incident_id', $incident->id)
                ->whereIn('status', ['APPROVED', 'PENDING_APPROVAL', 'DRAFT'])
                ->orderByDesc('revision')->first();

            if ($previous === null) {
                throw new RuntimeException('There is no investigation to reopen.');
            }

            DB::table('sheq_investigations')->where('id', $previous->id)->update([
                'status' => 'SUPERSEDED',
                'superseded_at' => Timestamps::utc(Carbon::now()),
                'updated_at' => now(),
            ]);

            $uuid = (string) Str::uuid();

            // The new revision starts from the previous one's method and depth, so reopening does
            // not quietly downgrade the rigour that was required.
            $id = DB::table('sheq_investigations')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'incident_id' => $incident->id,
                'revision' => (int) $previous->revision + 1,
                'supersedes_id' => $previous->id,
                'method' => $previous->method,
                'required_method' => $previous->required_method,
                'minimum_causes' => $previous->minimum_causes,
                'potential_severity' => $previous->potential_severity,
                'summary' => $previous->summary,
                'sequence_of_events' => $previous->sequence_of_events,
                'method_payload' => $previous->method_payload,
                'status' => 'DRAFT',
                'lead_investigator_id' => $actorId,
                'opened_at' => Timestamps::utc(Carbon::now()),
                'reopen_reason' => trim($reason),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // Causes are carried forward as a starting point; the new revision may keep, change or
            // contradict them, and the superseded revision still holds the originals.
            foreach (DB::table('sheq_investigation_causes')
                ->where('investigation_id', $previous->id)->get() as $cause) {
                DB::table('sheq_investigation_causes')->insert([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'investigation_id' => $id,
                    'category' => $cause->category,
                    'cause_type' => $cause->cause_type,
                    'description' => $cause->description,
                    'evidence' => $cause->evidence,
                    'depth' => $cause->depth,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('sheq_incidents')->where('id', $incident->id)->update([
                'status' => 'REOPENED', 'closed_at' => null, 'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'investigation.reopened', $id,
                ['revision' => $previous->revision], ['revision' => (int) $previous->revision + 1,
                    'reason' => $reason]);

            return $this->view($companyId, $uuid);
        });
    }

    /**
     * Why this investigation cannot yet be submitted.
     *
     * @return list<string>
     */
    private function completenessShortfalls(int $companyId, object $investigation): array
    {
        $shortfalls = [];

        $causes = DB::table('sheq_investigation_causes')
            ->where('investigation_id', $investigation->id)
            ->get(['category', 'cause_type']);

        $minimum = (int) $investigation->minimum_causes;

        if ($causes->count() < $minimum) {
            $shortfalls[] = sprintf(
                'A %s investigation needs at least %d causes; %d recorded.',
                $investigation->method, $minimum, $causes->count()
            );
        }

        $roots = $causes->where('cause_type', 'ROOT');

        if ($roots->isEmpty()) {
            $shortfalls[] = 'No root cause has been identified.';
        }

        /**
         * The finding that matters most. An investigation whose only root cause is
         * "the individual did the wrong thing" has stopped at the person and not reached the system
         * that let them — which is the difference between blame and prevention.
         */
        if ($roots->isNotEmpty() && $roots->every(fn ($c) => $c->category === 'INDIVIDUAL_TEAM_ACTION')) {
            $shortfalls[] = 'Every root cause blames an individual or team. Look for the task, '
                .'environmental or organisational condition that allowed it.';
        }

        if (trim((string) $investigation->summary) === '') {
            $shortfalls[] = 'The investigation has no summary.';
        }

        return $shortfalls;
    }

    /** @return array<string,mixed> */
    public function view(int $companyId, string $investigationUuid): array
    {
        $investigation = $this->find($companyId, $investigationUuid);

        /*
         * Null for an investigation that hangs off something other than an incident (QLT-5).
         * `incident_id` became nullable when non-conformances started using this engine, so a
         * lookup on it must not assume a row comes back.
         */
        $incident = $investigation->incident_id === null ? null
            : DB::table('sheq_incidents')->where('id', $investigation->incident_id)
                ->first(['id', 'uuid', 'reference', 'status', 'description', 'occurred_at',
                    'actual_severity', 'potential_severity', 'statutory_category']);

        return [
            'uuid' => $investigation->uuid,
            'revision' => (int) $investigation->revision,
            'method' => $investigation->method,
            'required_method' => $investigation->required_method,
            'minimum_causes' => (int) $investigation->minimum_causes,
            'potential_severity' => $investigation->potential_severity,
            'status' => $investigation->status,
            'summary' => $investigation->summary,
            'sequence_of_events' => $investigation->sequence_of_events,
            'method_payload' => json_decode((string) $investigation->method_payload, true),
            'reopen_reason' => $investigation->reopen_reason,
            'opened_at' => $investigation->opened_at,
            'submitted_at' => $investigation->submitted_at,
            'approved_at' => $investigation->approved_at,
            'incident' => $incident === null ? null : (array) $incident,
            // What this investigation is about, where it is not an incident.
            'subject_type' => $investigation->subject_type,
            'subject_id' => $investigation->subject_id === null
                ? null : (int) $investigation->subject_id,
            'causes' => DB::table('sheq_investigation_causes')
                ->where('company_id', $companyId)->where('investigation_id', $investigation->id)
                ->orderBy('id')
                ->get(['uuid', 'category', 'cause_type', 'description', 'evidence', 'depth'])
                ->map(fn ($c) => (array) $c)->all(),
            // Shown continuously so an investigator knows what is missing before they submit,
            // rather than being told at the point of refusal.
            'shortfalls' => $investigation->status === 'DRAFT'
                ? $this->completenessShortfalls($companyId, $investigation) : [],
            'layers' => self::CAUSE_LAYERS,
        ];
    }

    /** Every revision for an incident, newest first. @return list<array<string,mixed>> */
    public function history(int $companyId, string $incidentUuid): array
    {
        $incident = $this->incident($companyId, $incidentUuid);

        return DB::table('sheq_investigations')
            ->where('company_id', $companyId)->where('incident_id', $incident->id)
            ->orderByDesc('revision')
            ->get(['uuid', 'revision', 'method', 'status', 'opened_at', 'approved_at',
                'superseded_at', 'reopen_reason'])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function assertKnownMethod(string $method): void
    {
        if (! array_key_exists($method, self::METHOD_MINIMUMS)) {
            throw new RuntimeException(
                "'{$method}' is not a supported investigation method. Expected one of: "
                .implode(', ', array_keys(self::METHOD_MINIMUMS)).'.'
            );
        }
    }

    private function assertEditable(object $investigation): void
    {
        if ($investigation->status !== 'DRAFT') {
            throw new RuntimeException(
                'This investigation is no longer a draft. Reopen the incident to revise it.'
            );
        }
    }

    private function find(int $companyId, string $uuid): object
    {
        $row = DB::table('sheq_investigations')
            ->where('company_id', $companyId)->where('uuid', $uuid)->first();

        if ($row === null) {
            throw new RuntimeException('Investigation not found.');
        }

        return $row;
    }

    private function incident(int $companyId, string $uuid): object
    {
        $row = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('uuid', $uuid)
            ->whereNull('deleted_at')->first();

        if ($row === null) {
            throw new RuntimeException('Incident not found.');
        }

        return $row;
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before, ?array $after): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'Investigation',
            'subject_id' => $subjectId,
            'before' => $before === null ? null : json_encode($before),
            'after' => json_encode($after),
        ]);
    }
}
