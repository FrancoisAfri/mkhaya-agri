<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Contracts\CompetencyLedger;
use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Permit to Work (§5.7).
 *
 * This service says no more often than any other in the module, and deliberately so. A permit is
 * the document that authorises the most dangerous work a company does; the refusals below are the
 * controls, not obstacles to it.
 *
 * - **No risk assessment, no permit** (SHEQ-PTW-1). An approved, in-force assessment must cover the
 *   work. A permit issued against no assessment is a signature on nothing.
 * - **An expired competency blocks issue** (SHEQ-COMP-9), naming the person and the ticket. It can
 *   be overridden only with a reason, and the override becomes a visible risk acceptance rather
 *   than a quiet exception.
 * - **Conflicting permits warn the issuer** (SHEQ-PTW-5). Hot work next to a permit involving
 *   flammables, in the same place at the same time, is how people die on sites that had permits.
 * - **Hot work cannot be closed during its fire watch** (SHEQ-PTW-4). The fire watch exists because
 *   smouldering material ignites after the crew leaves.
 */
class PermitService
{
    /** Permit types that must not run in the same place at the same time. */
    private const INCOMPATIBLE = [
        'HOT_WORK' => ['CONFINED_SPACE', 'BREAKING_GROUND', 'HOT_WORK'],
        'CONFINED_SPACE' => ['HOT_WORK'],
        'BREAKING_GROUND' => ['HOT_WORK', 'ELECTRICAL_ISOLATION'],
        'ELECTRICAL_ISOLATION' => ['BREAKING_GROUND'],
    ];

    public function __construct(
        private readonly CompetencyLedger $competencies,
        private readonly RiskAssessmentService $riskAssessments,
        private readonly EventPublisher $events,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @param  list<array<string,mixed>>  $workers
     * @return array{id:int, uuid:string, reference:string}
     */
    public function request(int $companyId, array $input, array $workers, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($companyId, $input, $workers, $actorId) {
            if (! empty($input['client_id'])) {
                $existing = DB::table('sheq_permits')
                    ->where('company_id', $companyId)->where('client_id', $input['client_id'])
                    ->first(['id', 'uuid', 'reference']);

                if ($existing) {
                    return ['id' => $existing->id, 'uuid' => $existing->uuid,
                        'reference' => $existing->reference, 'replayed' => true];
                }
            }

            $uuid = (string) Str::uuid();
            $reference = $this->nextReference($companyId);

            $id = DB::table('sheq_permits')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'reference' => $reference,
                'client_id' => $input['client_id'] ?? null,
                'permit_type' => $input['permit_type'],
                'site_id' => $input['site_id'] ?? null,
                /*
                 * Whose work this is (CON-20). Null for the host's own employees, which is most
                 * permits on most sites — stamping those with a contractor would be worse than
                 * leaving it blank.
                 */
                'contractor_id' => $input['contractor_id'] ?? null,
                'work_description' => $input['work_description'],
                'location_text' => $input['location_text'] ?? null,
                'valid_from' => Timestamps::utc($input['valid_from']),
                'valid_to' => Timestamps::utc($input['valid_to']),
                'status' => 'SUBMITTED',
                'requested_by' => $actorId,
                'risk_assessment_id' => $input['risk_assessment_id'] ?? null,
                'precondition_checks' => isset($input['precondition_checks'])
                    ? json_encode($input['precondition_checks']) : null,
                'fire_watch_minutes' => $input['fire_watch_minutes']
                    ?? ($input['permit_type'] === 'HOT_WORK' ? 60 : null),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($workers as $worker) {
                DB::table('sheq_permit_workers')->insert([
                    'company_id' => $companyId,
                    'permit_id' => $id,
                    'hr_person_id' => $worker['hr_person_id'] ?? null,
                    'user_id' => $worker['user_id'] ?? null,
                    'name' => $worker['name'],
                    'worker_role' => $worker['worker_role'] ?? 'WORKER',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            /*
             * Approval stages for the type.
             *
             * Taken from the workflow the tenant configured under Approvals, falling back to a
             * single practitioner decision. Before this, stages came only from the caller and the
             * configured workflow was never read — so a tenant could build a three-step approval
             * chain for "Permits to work", save it, see it listed, and have every permit continue
             * to ask one person. The screen described a control that did not exist.
             *
             * A permit with no configured approvers still needs one decision: an unapproved permit
             * must never become active by default.
             */
            $stages = $input['approval_stages']
                ?? $this->configuredStages($companyId, (string) $input['permit_type'], $input['site_id'] ?? null)
                ?: [['stage' => 1, 'role' => 'SHEQ_PRACTITIONER']];

            foreach ($stages as $stage) {
                DB::table('sheq_permit_approvals')->insert([
                    'company_id' => $companyId,
                    'permit_id' => $id,
                    'stage' => $stage['stage'] ?? 1,
                    'approver_role' => $stage['role'],
                    'status' => 'PENDING',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->audit($companyId, $actorId, 'permit.requested', $id, null, [
                'reference' => $reference, 'type' => $input['permit_type'],
                'valid_from' => (string) $input['valid_from'], 'valid_to' => (string) $input['valid_to'],
            ]);

            return ['id' => $id, 'uuid' => $uuid, 'reference' => $reference];
        });
    }

    /**
     * Everything that would stop this permit being issued, gathered rather than thrown one at a
     * time — an issuer needs the whole picture, not the first problem.
     *
     * @return array{blocking:list<array<string,mixed>>, warnings:list<array<string,mixed>>}
     */
    /**
     * The approval chain this tenant configured for a permit of this type.
     *
     * Most specific wins: a workflow naming the permit type beats a general one, and a workflow
     * for this site beats a company-wide one — the same precedence an approver would assume.
     *
     * @return list<array{stage:int, role:string}>
     */
    private function configuredStages(int $companyId, string $permitType, ?int $siteId): array
    {
        $workflow = DB::table('sheq_approval_workflows')
            ->where('company_id', $companyId)
            ->where('subject_type', 'PERMIT')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('subject_subtype', $permitType)->orWhereNull('subject_subtype'))
            ->when($siteId, fn ($q) => $q->where(fn ($w) => $w->where('site_id', $siteId)->orWhereNull('site_id')),
                fn ($q) => $q->whereNull('site_id'))
            ->orderByRaw('subject_subtype is null')
            ->orderByRaw('site_id is null')
            ->orderByDesc('version')
            ->first(['id']);

        if ($workflow === null) {
            return [];
        }

        return DB::table('sheq_approval_steps')
            ->where('company_id', $companyId)->where('workflow_id', $workflow->id)
            ->orderBy('position')
            ->get(['position', 'name', 'approver_type', 'approver_ref'])
            ->map(fn ($step) => [
                'stage' => (int) $step->position,
                // The role is what the permit's approval row records; a step naming a specific
                // person still records the role it stands for, so the chain reads the same.
                'role' => (string) ($step->approver_ref ?: $step->name ?: 'SHEQ_PRACTITIONER'),
            ])
            ->all();
    }

    public function preIssueChecks(int $companyId, string $uuid): array
    {
        $permit = $this->find($companyId, $uuid);
        $blocking = [];
        $warnings = [];

        // SHEQ-PTW-1 — an approved assessment must cover the work.
        $assessment = $permit->risk_assessment_id
            ? DB::table('sheq_risk_assessments')->where('company_id', $companyId)
                ->where('id', $permit->risk_assessment_id)->first()
            : null;

        if ($assessment === null) {
            $blocking[] = [
                'type' => 'NO_RISK_ASSESSMENT',
                'detail' => 'This permit references no risk assessment. A permit cannot be issued '
                          .'without one (SHEQ-PTW-1).',
            ];
        } elseif ($assessment->status !== 'APPROVED') {
            $blocking[] = [
                'type' => 'RISK_ASSESSMENT_NOT_APPROVED',
                'detail' => "Risk assessment {$assessment->reference} is {$assessment->status}, not approved.",
            ];
        } elseif ($assessment->review_due && Carbon::parse($assessment->review_due)->isPast()) {
            $blocking[] = [
                'type' => 'RISK_ASSESSMENT_EXPIRED',
                'detail' => "Risk assessment {$assessment->reference} was due for review on "
                          .Carbon::parse($assessment->review_due)->toDateString().'.',
            ];
        }

        /*
         * SHEQ-COMP-9 — competency gating, per named worker.
         *
         * A missing or expired ticket blocks. A worker the ledger cannot speak for at all — a
         * contractor's employee, who has no personnel record here — is a warning: blocking would
         * stop most high-risk work on most sites and push permits outside the system, but passing
         * in silence told the issuer the crew had been checked when nobody had looked.
         */
        foreach ($this->competencyGaps($companyId, $permit) as $gap) {
            if (($gap['blocking'] ?? true) === false) {
                $warnings[] = $gap;

                continue;
            }

            $blocking[] = $gap;
        }

        /*
         * Outstanding approvals belong in the gate, not only in `issue()`.
         *
         * `issue()` has always refused while an approval is pending, but the checks this method
         * returns are what a screen shows — so the permit screen reported "nothing is blocking
         * this permit" to an issuer whose chain was half signed, and only refused once they
         * pressed the button. The gate is the thing people read; it has to be complete.
         */
        $pending = DB::table('sheq_permit_approvals')
            ->where('company_id', $companyId)->where('permit_id', $permit->id)
            ->where('status', 'PENDING')->count();

        if ($pending > 0) {
            $blocking[] = [
                'type' => 'APPROVALS_OUTSTANDING',
                'detail' => "{$pending} approval(s) are still outstanding.",
            ];
        }

        // SHEQ-PTW-5 — conflicting permits are a warning to the issuer, not a hard block: the
        // issuer may know the two crews are separated, and blocking legitimate work teaches people
        // to work around the system.
        foreach ($this->conflictingPermits($companyId, $permit) as $conflict) {
            $warnings[] = $conflict;
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }

    /**
     * Issue the permit. Refuses while anything blocking stands, unless a competency override has
     * been recorded by someone entitled to accept that risk.
     */
    public function issue(int $companyId, string $uuid, ?int $actorId): void
    {
        $permit = $this->find($companyId, $uuid);
        $checks = $this->preIssueChecks($companyId, $uuid);

        $blocking = $permit->competency_overridden
            ? array_values(array_filter($checks['blocking'], fn ($b) => $b['type'] !== 'COMPETENCY_GAP'))
            : $checks['blocking'];

        if ($blocking !== []) {
            throw new RuntimeException(
                'This permit cannot be issued: '.implode(' ', array_column($blocking, 'detail'))
            );
        }

        $pending = DB::table('sheq_permit_approvals')
            ->where('permit_id', $permit->id)->where('status', 'PENDING')->count();

        if ($pending > 0) {
            throw new RuntimeException("{$pending} approval(s) are still outstanding on this permit.");
        }

        DB::transaction(function () use ($permit, $companyId, $actorId) {
            // Freeze each worker's competency verdict at issue time.
            foreach (DB::table('sheq_permit_workers')->where('permit_id', $permit->id)->get() as $worker) {
                if ($worker->hr_person_id === null) {
                    continue;
                }

                DB::table('sheq_permit_workers')->where('id', $worker->id)->update([
                    'competency_snapshot' => json_encode([
                        'checked_at' => now()->toIso8601String(),
                        'source_available' => $this->competencies->isAvailable(),
                        'competencies' => $this->competencies->statusFor((int) $worker->hr_person_id),
                    ]),
                    'updated_at' => now(),
                ]);
            }

            DB::table('sheq_permits')->where('id', $permit->id)->update([
                'status' => 'ACTIVE',
                'issuer_id' => $actorId,
                'activated_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'permit.issued', $permit->id,
                ['status' => $permit->status], ['status' => 'ACTIVE']);

            $this->events->publish($companyId, 'sheq.permit.activated', 'PERMIT', (int) $permit->id, [
                'permit_id' => (int) $permit->id,
                'reference' => $permit->reference ?? null,
                'permit_type' => $permit->permit_type ?? null,
                'site_id' => $permit->site_id ?? null,
                'valid_to' => $permit->valid_to ?? null,
            ], ['actor_id' => $actorId]);
        });
    }

    public function approve(int $companyId, string $uuid, int $approvalId, ?int $actorId, ?string $comments = null): void
    {
        $permit = $this->find($companyId, $uuid);

        DB::transaction(function () use ($permit, $companyId, $approvalId, $actorId, $comments) {
            DB::table('sheq_permit_approvals')
                ->where('permit_id', $permit->id)->where('id', $approvalId)
                ->update(['status' => 'APPROVED', 'approver_id' => $actorId,
                    'decided_at' => now(), 'comments' => $comments, 'updated_at' => now()]);

            $outstanding = DB::table('sheq_permit_approvals')
                ->where('permit_id', $permit->id)->where('status', 'PENDING')->count();

            if ($outstanding === 0) {
                DB::table('sheq_permits')->where('id', $permit->id)
                    ->update(['status' => 'APPROVED', 'updated_at' => now()]);

                // Only when the last approval clears. Publishing on each signature would tell a
                // subscriber the permit is approved while two approvers are still to sign.
                $this->events->publish($companyId, 'sheq.permit.approved', 'PERMIT', (int) $permit->id, [
                    'permit_id' => (int) $permit->id,
                    'reference' => $permit->reference ?? null,
                    'permit_type' => $permit->permit_type ?? null,
                ], ['actor_id' => $actorId]);
            }

            $this->audit($companyId, $actorId, 'permit.approval_recorded', $permit->id, null,
                ['approval_id' => $approvalId, 'outstanding' => $outstanding]);
        });
    }

    /**
     * Accept the risk of an expired competency (SHEQ-COMP-9). Requires a reason, and the acceptance
     * is what appears on the dashboard — the point is that someone's name is attached to it.
     */
    public function overrideCompetency(int $companyId, string $uuid, string $reason, ?int $actorId): void
    {
        if (trim($reason) === '') {
            throw new RuntimeException('A competency override needs a reason (SHEQ-COMP-9).');
        }

        $permit = $this->find($companyId, $uuid);

        DB::transaction(function () use ($permit, $companyId, $reason, $actorId) {
            DB::table('sheq_permits')->where('id', $permit->id)->update([
                'competency_overridden' => true,
                'competency_override_reason' => $reason,
                'competency_overridden_by' => $actorId,
                'competency_overridden_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'permit.competency_overridden', $permit->id, null,
                ['reason' => $reason], $reason);
        });
    }

    public function suspend(int $companyId, string $uuid, string $reason, ?int $actorId): void
    {
        $permit = $this->find($companyId, $uuid);

        /**
         * Wrapped in a transaction where it previously was not. Suspension means work stops
         * immediately, so the status change, the audit entry and the event that tells a site
         * system to down tools must land together or not at all.
         */
        DB::transaction(function () use ($permit, $companyId, $reason, $actorId) {
            DB::table('sheq_permits')->where('id', $permit->id)->update([
                'status' => 'SUSPENDED', 'suspended_at' => now(),
                'suspension_reason' => $reason, 'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'permit.suspended', $permit->id,
                ['status' => $permit->status], ['status' => 'SUSPENDED'], $reason);

            $this->events->publish($companyId, 'sheq.permit.suspended', 'PERMIT', (int) $permit->id, [
                'permit_id' => (int) $permit->id,
                'reference' => $permit->reference ?? null,
                'reason' => $reason,
            ], ['actor_id' => $actorId]);
        });
    }

    public function reinstate(int $companyId, string $uuid, ?int $actorId): void
    {
        $permit = $this->find($companyId, $uuid);

        if ($permit->status !== 'SUSPENDED') {
            throw new RuntimeException('Only a suspended permit can be reinstated.');
        }

        if (Carbon::parse($permit->valid_to)->isPast()) {
            throw new RuntimeException('This permit has passed its validity window and cannot be reinstated.');
        }

        DB::table('sheq_permits')->where('id', $permit->id)->update([
            'status' => 'ACTIVE', 'suspended_at' => null, 'suspension_reason' => null, 'updated_at' => now(),
        ]);

        $this->audit($companyId, $actorId, 'permit.reinstated', $permit->id,
            ['status' => 'SUSPENDED'], ['status' => 'ACTIVE']);
    }

    /**
     * Close and hand back. Hot work cannot be closed until its fire watch has elapsed since the
     * work stopped (SHEQ-PTW-4).
     *
     * @param  array<string,mixed>  $closureChecks
     */
    public function close(int $companyId, string $uuid, array $closureChecks, ?int $actorId): void
    {
        $permit = $this->find($companyId, $uuid);

        if ($permit->fire_watch_minutes) {
            $workCompletedAt = $closureChecks['work_completed_at'] ?? $permit->work_completed_at;

            if ($workCompletedAt === null) {
                throw new RuntimeException(
                    'Record when the work stopped before closing a permit with a fire watch.'
                );
            }

            $watchEndsAt = Carbon::parse($workCompletedAt)->addMinutes((int) $permit->fire_watch_minutes);

            if ($watchEndsAt->isFuture()) {
                throw new RuntimeException(sprintf(
                    'The fire watch runs until %s. Hot work permits cannot be closed early — '
                    .'smouldering material ignites after the crew leaves.',
                    $watchEndsAt->toDateTimeString()
                ));
            }
        }

        DB::transaction(function () use ($permit, $companyId, $closureChecks, $actorId) {
            DB::table('sheq_permits')->where('id', $permit->id)->update([
                'status' => 'CLOSED',
                'closure_checks' => json_encode($closureChecks),
                'work_completed_at' => Timestamps::utc($closureChecks['work_completed_at'] ?? $permit->work_completed_at),
                'closed_at' => now(),
                'closed_by' => $actorId,
                'updated_at' => now(),
            ]);

            $this->audit($companyId, $actorId, 'permit.closed', $permit->id,
                ['status' => $permit->status], ['status' => 'CLOSED', 'checks' => $closureChecks]);
        });
    }

    /**
     * Expire permits whose window has passed (SHEQ-PTW-4). Run on a schedule; also safe to call
     * before reading the active board so it never shows a permit that has run out.
     */
    public function expireLapsed(int $companyId): int
    {
        $lapsed = DB::table('sheq_permits')
            ->where('company_id', $companyId)
            ->whereIn('status', ['ACTIVE', 'APPROVED', 'SUSPENDED'])
            ->where('valid_to', '<', now())
            ->pluck('id');

        if ($lapsed->isEmpty()) {
            return 0;
        }

        DB::table('sheq_permits')->whereIn('id', $lapsed)
            ->update(['status' => 'EXPIRED', 'updated_at' => now()]);

        foreach ($lapsed as $id) {
            $this->audit($companyId, null, 'permit.expired', $id, null, ['status' => 'EXPIRED']);
        }

        return $lapsed->count();
    }

    /** The live board a control room actually needs (SHEQ-PTW-6). */
    public function activeNow(int $companyId, ?int $siteId = null): array
    {
        $this->expireLapsed($companyId);

        return DB::table('sheq_permits as p')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'p.site_id')
            ->where('p.company_id', $companyId)
            ->whereIn('p.status', ['ACTIVE', 'SUSPENDED'])
            ->when($siteId, fn ($q) => $q->where('p.site_id', $siteId))
            ->whereNull('p.deleted_at')
            ->orderBy('p.valid_to')
            // `site_id` as well as the name: the board resolves the risk assessment in force per
            // site, and a display name cannot key that lookup — two sites may share a name, and a
            // company-wide permit has no name at all.
            ->get(['p.uuid', 'p.reference', 'p.permit_type', 'p.work_description', 'p.location_text',
                'p.valid_from', 'p.valid_to', 'p.status', 'p.competency_overridden',
                'p.location_lat', 'p.location_lng', 'p.site_id', 's.name as site_name'])
            ->map(fn ($p) => (array) $p)->all();
    }

    /** @return list<array<string,mixed>> */
    private function competencyGaps(int $companyId, object $permit): array
    {
        $required = DB::table('sheq_permit_requirements')
            ->where('company_id', $companyId)->where('permit_type', $permit->permit_type)
            ->where('is_mandatory', true)->get(['competency_code', 'applies_to_role']);

        if ($required->isEmpty() || ! $this->competencies->isAvailable()) {
            return [];
        }

        $gaps = [];

        $unverifiable = [];

        foreach (DB::table('sheq_permit_workers')->where('permit_id', $permit->id)->get() as $worker) {
            if ($worker->hr_person_id === null) {
                /*
                 * A worker the competency ledger cannot speak for — in practice a contractor's
                 * employee, who has no personnel record here.
                 *
                 * This used to `continue`, which meant the gate said "nothing is blocking this
                 * permit" for a crew of contractor welders nobody had checked. The permit then
                 * reads as fully verified on a screen whose whole purpose is to say what has not
                 * been verified, and an issuer signs hot work on that basis.
                 *
                 * Collected as a warning rather than a block, deliberately and for the same reason
                 * conflicting permits are a warning: most high-risk work on most sites is done by
                 * contractors, so blocking here would stop the work the module exists to control
                 * and teach people to issue permits outside the system. The issuer is told, by
                 * name, who they are vouching for.
                 */
                $unverifiable[] = $worker->name;

                continue;
            }

            foreach ($required as $requirement) {
                if ($requirement->applies_to_role && $requirement->applies_to_role !== $worker->worker_role) {
                    continue;
                }

                $status = $this->competencies->statusForCompetency(
                    (int) $worker->hr_person_id, $requirement->competency_code
                );

                if ($status === null || $status['status'] === CompetencyLedger::EXPIRED) {
                    $gaps[] = [
                        'type' => 'COMPETENCY_GAP',
                        'worker' => $worker->name,
                        'hr_person_id' => $worker->hr_person_id,
                        'competency' => $requirement->competency_code,
                        'detail' => $status === null
                            ? sprintf('%s has no record of the required "%s" competency.',
                                $worker->name, $requirement->competency_code)
                            : sprintf('%s holds an expired "%s" competency (expired %s).',
                                $worker->name, $requirement->competency_code, $status['expires_at'] ?? 'unknown'),
                    ];
                }
            }
        }

        if ($unverifiable !== []) {
            $gaps[] = [
                'type' => 'COMPETENCY_UNVERIFIABLE',
                'blocking' => false,
                'workers' => $unverifiable,
                'detail' => sprintf(
                    'Competency cannot be verified for %s: %s. They have no personnel record here, '
                    .'so their tickets are the contractor\'s to evidence. Check them against the '
                    .'contractor\'s file before issuing.',
                    count($unverifiable) === 1 ? 'one worker' : count($unverifiable).' workers',
                    implode(', ', $unverifiable),
                ),
            ];
        }

        return $gaps;
    }

    /** @return list<array<string,mixed>> */
    private function conflictingPermits(int $companyId, object $permit): array
    {
        $incompatible = self::INCOMPATIBLE[$permit->permit_type] ?? [];

        if ($incompatible === [] || $permit->site_id === null) {
            return [];
        }

        return DB::table('sheq_permits')
            ->where('company_id', $companyId)
            ->where('id', '!=', $permit->id)
            ->where('site_id', $permit->site_id)
            ->whereIn('status', ['APPROVED', 'ACTIVE'])
            ->whereIn('permit_type', $incompatible)
            // Overlapping windows: starts before the other ends, and ends after the other starts.
            ->where('valid_from', '<', $permit->valid_to)
            ->where('valid_to', '>', $permit->valid_from)
            ->whereNull('deleted_at')
            ->get(['reference', 'permit_type', 'location_text', 'valid_from', 'valid_to'])
            ->map(fn ($other) => [
                'type' => 'PERMIT_CONFLICT',
                'reference' => $other->reference,
                'detail' => sprintf(
                    '%s (%s) is active at this site over the same period%s. Confirm the work areas '
                    .'are separated before issuing.',
                    $other->reference, $other->permit_type,
                    $other->location_text ? ' at '.$other->location_text : ''
                ),
            ])->all();
    }

    private function find(int $companyId, string $uuid): object
    {
        $permit = DB::table('sheq_permits')
            ->where('company_id', $companyId)->where('uuid', $uuid)->whereNull('deleted_at')->first();

        if ($permit === null) {
            throw new RuntimeException('Permit not found.');
        }

        return $permit;
    }

    private function nextReference(int $companyId): string
    {
        $prefix = DB::table('sheq_settings')->where('company_id', $companyId)->value('permit_prefix') ?? 'PTW';
        $year = Carbon::now()->year;

        $last = DB::table('sheq_permits')->where('company_id', $companyId)
            ->where('reference', 'like', "{$prefix}-{$year}-%")->orderByDesc('reference')->value('reference');

        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('%s-%d-%06d', $prefix, $year, $next);
    }

    private function audit(
        int $companyId, ?int $actorId, string $action, int $subjectId,
        ?array $before = null, ?array $after = null, ?string $reason = null
    ): void {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => $actorId === null ? 'SYSTEM' : 'USER',
            'action' => $action,
            'subject_type' => 'Permit',
            'subject_id' => $subjectId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
