<?php

namespace App\Modules\Operations\Sheq\Approvals;

use App\Modules\Operations\Sheq\Support\Timestamps;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The one place a SHEQ approval happens.
 *
 * Every module routes through `open()` and `decide()`. That matters more than it sounds: an
 * approval is the moment a named person accepts legal responsibility for something — issuing a
 * permit, letting a contractor on site, closing a non-conformance — and the rules about who may do
 * that, whether they may do it to their own request, and what counts as enough approval are exactly
 * the rules nobody wants reimplemented per feature.
 *
 * The refusals here are the product. In order of how badly each would fail if it were missing:
 *
 * 1. **Self-approval is impossible.** The requester cannot approve, at any step, ever, regardless of
 *    what roles they hold. An approval that the requester can grant themselves is a signature
 *    without a decision, and it is worse than no approval because it produces a record saying
 *    somebody checked.
 * 2. **A missing workflow blocks.** No configuration means nobody has decided who may authorise
 *    this, and treating that as "anybody" is how an unapproved hot-work permit gets issued.
 * 3. **The steps are frozen at open time.** Editing a workflow must not retroactively change what
 *    an approver already agreed to.
 */
class ApprovalService
{
    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const CANCELLED = 'CANCELLED';

    public function __construct(private readonly ApproverResolver $resolver) {}

    /**
     * Start an approval.
     *
     * @param  array{subject_type:string, subject_id:int, subject_label?:?string,
     *               subject_subtype?:?string, site_id?:?int, requested_by:int,
     *               note?:?string, context?:array}  $data
     * @return array{uuid:string, id:int, status:string, current_step:?array}
     */
    public function open(int $companyId, array $data): array
    {
        $workflow = $this->workflowFor($companyId, $data['subject_type'],
            $data['subject_subtype'] ?? null, $data['site_id'] ?? null);

        if ($workflow === null) {
            throw new RuntimeException(sprintf(
                'No approval workflow is configured for %s at this company, so there is nobody '
                .'authorised to approve it. Configure one under SHEQ → Settings → Approvals. This '
                .'is refused rather than allowed through: an unconfigured approval means nobody has '
                .'decided who may sign, not that anyone may.',
                str_replace('_', ' ', strtolower($data['subject_type'])),
            ));
        }

        $steps = $this->stepsFor($workflow->id);

        if ($steps === []) {
            throw new RuntimeException(
                "The approval workflow '{$workflow->name}' has no steps, so nothing could ever "
                .'approve this. Add at least one step before using it.'
            );
        }

        return DB::transaction(function () use ($companyId, $data, $workflow, $steps) {
            $uuid = (string) Str::uuid();
            $now = Carbon::now();

            $id = DB::table('sheq_approval_requests')->insertGetId([
                'uuid' => $uuid,
                'company_id' => $companyId,
                'workflow_id' => $workflow->id,
                // Frozen: see the class note. A join to live steps would let an edit rewrite
                // history mid-flight.
                'workflow_snapshot' => json_encode($steps),
                'workflow_version' => $workflow->version,
                'subject_type' => $data['subject_type'],
                'subject_id' => $data['subject_id'],
                'subject_label' => $data['subject_label'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'status' => self::PENDING,
                'current_position' => $steps[0]['position'],
                'requested_by' => $data['requested_by'],
                'request_note' => $data['note'] ?? null,
                'context' => json_encode($data['context'] ?? []),
                'due_at' => $workflow->sla_hours
                    ? Timestamps::utc($now->copy()->addHours($workflow->sla_hours)) : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            return [
                'id' => $id, 'uuid' => $uuid, 'status' => self::PENDING,
                'current_step' => $steps[0],
            ];
        });
    }

    /**
     * Record one person's decision.
     *
     * @param  array{decision:string, note?:?string, signature?:?string, delegate_to?:?int}  $input
     * @return array{status:string, position:int, awaiting:?array}
     */
    public function decide(int $companyId, string $uuid, int $userId, array $input): array
    {
        $decision = strtoupper($input['decision'] ?? '');

        if (! in_array($decision, ['APPROVED', 'REJECTED', 'DELEGATED'], true)) {
            throw new InvalidArgumentException("'{$decision}' is not a decision.");
        }

        return DB::transaction(function () use ($companyId, $uuid, $userId, $input, $decision) {
            $request = DB::table('sheq_approval_requests')
                ->where('company_id', $companyId)->where('uuid', $uuid)
                ->whereNull('deleted_at')->lockForUpdate()->first();

            if ($request === null) {
                throw new RuntimeException("No approval request {$uuid} in this company.");
            }

            if ($request->status !== self::PENDING) {
                throw new RuntimeException(sprintf(
                    'This request was already %s. Re-deciding would overwrite the record of who '
                    .'decided what, and when.', strtolower($request->status),
                ));
            }

            // ── The rule that makes an approval mean anything ─────────────────────────────────
            if ((int) $request->requested_by === $userId) {
                throw new RuntimeException(
                    'You raised this request, so you cannot approve it. The point of an approval '
                    .'is that a second person looked at it — and a record showing you approved '
                    .'your own request would be worse than no approval at all.'
                );
            }

            $steps = json_decode((string) $request->workflow_snapshot, true) ?: [];
            $step = $this->stepAt($steps, (int) $request->current_position);

            if ($step === null) {
                throw new RuntimeException('This request is at a step that no longer exists.');
            }

            if (! $this->resolver->canAct($companyId, $step, $userId, $request)) {
                throw new RuntimeException(sprintf(
                    'This step is approved by %s, which you are not.',
                    $this->resolver->describe($step),
                ));
            }

            if (! empty($step['requires_signature']) && $decision === 'APPROVED'
                && empty($input['signature'])) {
                throw new RuntimeException(
                    "The step '{$step['name']}' requires a signature, not just an acknowledgement."
                );
            }

            DB::table('sheq_approval_decisions')->insert([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'request_id' => $request->id,
                'position' => $step['position'],
                'step_name' => $step['name'],
                'decided_by' => $userId,
                'decision' => $decision,
                'note' => $input['note'] ?? null,
                'signature' => $input['signature'] ?? null,
                'acted_as' => $this->resolver->describe($step),
                'delegated_to' => $input['delegate_to'] ?? null,
                'decided_at' => Carbon::now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            // ── A rejection ends it ───────────────────────────────────────────────────────────
            if ($decision === 'REJECTED') {
                // No "majority rejects" arithmetic: one approver refusing is enough, because the
                // question an approval answers is "is everyone required content?", and they are not.
                DB::table('sheq_approval_requests')->where('id', $request->id)->update([
                    'status' => self::REJECTED,
                    'completed_at' => Carbon::now(),
                    'outcome_note' => $input['note'] ?? null,
                    'updated_at' => now(),
                ]);

                return ['status' => self::REJECTED, 'position' => $step['position'], 'awaiting' => null];
            }

            if ($decision === 'DELEGATED') {
                return ['status' => self::PENDING, 'position' => $step['position'],
                    'awaiting' => $step];
            }

            // ── Enough approvals for this step? ───────────────────────────────────────────────
            $approvals = DB::table('sheq_approval_decisions')
                ->where('request_id', $request->id)
                ->where('position', $step['position'])
                ->where('decision', 'APPROVED')
                ->count();

            if ($approvals < (int) ($step['quorum'] ?? 1)) {
                return ['status' => self::PENDING, 'position' => $step['position'],
                    'awaiting' => $step];
            }

            $next = $this->nextStep($steps, (int) $step['position']);

            if ($next === null) {
                DB::table('sheq_approval_requests')->where('id', $request->id)->update([
                    'status' => self::APPROVED,
                    'completed_at' => Carbon::now(),
                    'updated_at' => now(),
                ]);

                return ['status' => self::APPROVED, 'position' => $step['position'], 'awaiting' => null];
            }

            DB::table('sheq_approval_requests')->where('id', $request->id)->update([
                'current_position' => $next['position'],
                'updated_at' => now(),
            ]);

            return ['status' => self::PENDING, 'position' => $next['position'], 'awaiting' => $next];
        });
    }

    /** Withdraw a request. Only the person who raised it, and only while it is undecided. */
    public function cancel(int $companyId, string $uuid, int $userId, string $reason): void
    {
        $request = DB::table('sheq_approval_requests')
            ->where('company_id', $companyId)->where('uuid', $uuid)->first(['id', 'status', 'requested_by']);

        if ($request === null) {
            throw new RuntimeException("No approval request {$uuid} in this company.");
        }

        if ($request->status !== self::PENDING) {
            throw new RuntimeException('Only a pending request can be withdrawn.');
        }

        if ((int) $request->requested_by !== $userId) {
            throw new RuntimeException('Only the person who raised a request may withdraw it.');
        }

        DB::table('sheq_approval_requests')->where('id', $request->id)->update([
            'status' => self::CANCELLED, 'completed_at' => Carbon::now(),
            'outcome_note' => $reason, 'updated_at' => now(),
        ]);
    }

    /**
     * Whether this subject can be raised at all.
     *
     * Three subjects are approval-driven by design — a visitor, a supplier onboarding, an audit
     * finding — so `open()` refuses them outright when no workflow exists, and rightly: nobody is
     * authorised to admit a visitor nobody has decided may admit them.
     *
     * The refusal was only ever reachable by trying. Somebody filled in a visitor's details,
     * pressed submit, and was told the company had no workflow — losing the form and learning the
     * prerequisite at the worst moment. A screen can ask this first and say so before anything is
     * typed.
     */
    public function canBeRaised(int $companyId, string $subjectType,
        ?string $subtype = null, ?int $siteId = null): bool
    {
        return $this->workflowFor($companyId, $subjectType, $subtype, $siteId) !== null;
    }

    /**
     * Start an approval only where the tenant has configured one.
     *
     * `open()` refuses when no workflow exists, which is right for the three subjects that are
     * approval-driven by design — a visitor with nobody authorised to admit them should not be
     * admitted. It is wrong for the six the Approvals screen also offers: risk assessments,
     * incident closure, documents, PPE issue, medical clearance and contractors all worked before
     * anybody configured a workflow, and calling `open()` from them would mean installing this
     * release broke every one of those flows for every tenant that has not set one up.
     *
     * So the rule is: configure a workflow and it is enforced; configure none and nothing changes.
     * That is also what the screen promises — "who approves an assessment before it takes effect"
     * reads as an option, not as a new obligation.
     *
     * @param  array<string,mixed>  $data  as `open()` takes it
     * @return array{uuid:string, id:int, status:string, current_step:?array}|null
     */
    public function openIfConfigured(int $companyId, array $data): ?array
    {
        $workflow = $this->workflowFor($companyId, $data['subject_type'],
            $data['subject_subtype'] ?? null, $data['site_id'] ?? null);

        if ($workflow === null) {
            return null;
        }

        // A workflow with no steps would refuse forever. That is a configuration mistake rather
        // than a decision, and `open()` says so — let it.
        return $this->open($companyId, $data);
    }

    /**
     * Whether a subject may proceed: either nothing is required of it, or what was required is done.
     *
     * The question a calling module actually has at the moment it is about to act. Asking
     * `isApproved()` alone would block every subject at a tenant with no workflow, because an
     * approval that was never opened is not approved.
     */
    public function isCleared(int $companyId, string $subjectType, int $subjectId,
        ?string $subtype = null, ?int $siteId = null): bool
    {
        if ($this->workflowFor($companyId, $subjectType, $subtype, $siteId) === null) {
            return true;
        }

        return $this->isApproved($companyId, $subjectType, $subjectId);
    }

    /**
     * Why a subject is not cleared, in a sentence somebody can act on.
     *
     * Returns null when it is cleared. "Approval outstanding" with no further detail sends people
     * to go and ask who is holding it up.
     */
    public function blockingReason(int $companyId, string $subjectType, int $subjectId,
        ?string $subtype = null, ?int $siteId = null): ?string
    {
        if ($this->isCleared($companyId, $subjectType, $subjectId, $subtype, $siteId)) {
            return null;
        }

        $status = $this->statusFor($companyId, $subjectType, $subjectId);

        if ($status === null) {
            return 'An approval is required for this and has not been started.';
        }

        // The step the chain is actually sitting on, found by position in the snapshot — there is
        // no `current_step` key; assuming one produced the generic sentence this method exists to
        // avoid.
        $position = (int) ($status['current_position'] ?? 0);

        $step = collect($status['steps'] ?? [])
            ->first(fn ($s) => (int) ($s['position'] ?? 0) === $position);

        $name = $step['name'] ?? null;

        return $name === null
            ? 'This is waiting on an approval that has not been completed.'
            : "This is waiting on the '{$name}' approval step.";
    }

    /** Whether a subject has been approved — what a calling module asks before it acts. */
    public function isApproved(int $companyId, string $subjectType, int $subjectId): bool
    {
        return DB::table('sheq_approval_requests')
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->where('status', self::APPROVED)->whereNull('deleted_at')
            ->exists();
    }

    /** The current state of a subject's approval, for a screen to render. */
    public function statusFor(int $companyId, string $subjectType, int $subjectId): ?array
    {
        $request = DB::table('sheq_approval_requests')
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereNull('deleted_at')->orderByDesc('id')->first();

        if ($request === null) {
            return null;
        }

        $steps = json_decode((string) $request->workflow_snapshot, true) ?: [];

        $decisions = DB::table('sheq_approval_decisions as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.decided_by')
            ->leftJoin('hr_people as p', 'p.user_id', '=', 'u.id')
            ->where('d.request_id', $request->id)
            ->orderBy('d.decided_at')
            ->get(['d.position', 'd.step_name', 'd.decision', 'd.note', 'd.decided_at',
                'd.acted_as', 'p.first_name', 'p.surname'])
            ->map(fn ($d) => [
                'position' => (int) $d->position,
                'step_name' => $d->step_name,
                'decision' => $d->decision,
                'note' => $d->note,
                'decided_at' => $d->decided_at,
                'acted_as' => $d->acted_as,
                'decided_by' => trim(($d->first_name ?? '').' '.($d->surname ?? '')) ?: '—',
            ])->all();

        return [
            'uuid' => $request->uuid,
            'status' => $request->status,
            'current_position' => (int) $request->current_position,
            'steps' => $steps,
            'decisions' => $decisions,
            'requested_by' => (int) $request->requested_by,
            'due_at' => $request->due_at,
            'completed_at' => $request->completed_at,
        ];
    }

    /** Requests this user can act on right now. */
    public function awaiting(int $companyId, int $userId, int $limit = 100): array
    {
        $rows = DB::table('sheq_approval_requests')
            ->where('company_id', $companyId)->where('status', self::PENDING)
            ->whereNull('deleted_at')
            // Never show somebody their own request as an action.
            ->where('requested_by', '!=', $userId)
            ->orderBy('due_at')->limit($limit)->get();

        $out = [];

        foreach ($rows as $request) {
            $steps = json_decode((string) $request->workflow_snapshot, true) ?: [];
            $step = $this->stepAt($steps, (int) $request->current_position);

            if ($step === null || ! $this->resolver->canAct($companyId, $step, $userId, $request)) {
                continue;
            }

            // Already voted at this step — waiting on someone else's half of the quorum.
            $alreadyDecided = DB::table('sheq_approval_decisions')
                ->where('request_id', $request->id)->where('position', $step['position'])
                ->where('decided_by', $userId)->exists();

            if ($alreadyDecided) {
                continue;
            }

            $out[] = [
                'uuid' => $request->uuid,
                'subject_type' => $request->subject_type,
                'subject_id' => (int) $request->subject_id,
                'subject_label' => $request->subject_label,
                'step_name' => $step['name'],
                'position' => (int) $step['position'],
                'total_steps' => count($steps),
                'requires_signature' => (bool) ($step['requires_signature'] ?? false),
                'due_at' => $request->due_at,
                'overdue' => $request->due_at !== null && Carbon::parse($request->due_at)->isPast(),
                'created_at' => $request->created_at,
            ];
        }

        return $out;
    }

    /**
     * The workflow that governs this subject, most specific first.
     *
     * Site-and-subtype beats subtype beats site beats the company default, so "confined space at
     * the shaft" can need three approvers while a general permit needs one.
     */
    public function workflowFor(int $companyId, string $subjectType, ?string $subtype = null,
        ?int $siteId = null): ?object
    {
        $candidates = DB::table('sheq_approval_workflows')
            ->where('company_id', $companyId)->where('subject_type', $subjectType)
            ->where('is_active', true)->whereNull('deleted_at')
            ->get();

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            if ($candidate->subject_subtype !== null && $candidate->subject_subtype !== $subtype) {
                continue;
            }

            if ($candidate->site_id !== null && (int) $candidate->site_id !== $siteId) {
                continue;
            }

            $score = ($candidate->subject_subtype !== null ? 2 : 0)
                + ($candidate->site_id !== null ? 1 : 0);

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @return list<array<string,mixed>> */
    public function stepsFor(int $workflowId): array
    {
        return DB::table('sheq_approval_steps')
            ->where('workflow_id', $workflowId)->orderBy('position')
            ->get(['position', 'name', 'approver_type', 'approver_ref', 'quorum',
                'sla_hours', 'is_optional', 'requires_signature'])
            ->map(fn ($s) => [
                'position' => (int) $s->position,
                'name' => $s->name,
                'approver_type' => $s->approver_type,
                'approver_ref' => $s->approver_ref,
                'quorum' => (int) $s->quorum,
                'sla_hours' => $s->sla_hours === null ? null : (int) $s->sla_hours,
                'is_optional' => (bool) $s->is_optional,
                'requires_signature' => (bool) $s->requires_signature,
            ])->all();
    }

    private function stepAt(array $steps, int $position): ?array
    {
        foreach ($steps as $step) {
            if ((int) $step['position'] === $position) {
                return $step;
            }
        }

        return null;
    }

    private function nextStep(array $steps, int $after): ?array
    {
        $next = null;

        foreach ($steps as $step) {
            if ((int) $step['position'] > $after
                && ($next === null || (int) $step['position'] < (int) $next['position'])) {
                $next = $step;
            }
        }

        return $next;
    }
}
