<?php

namespace App\Modules\Operations\Sheq\Services;

use App\Modules\Operations\Sheq\Approvals\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The safety file's own state, from draft to approved and out again (CON-7).
 *
 * A computed compliance percentage answers "is every demanded document on file and in date". It
 * cannot answer the question a client's auditor actually asks, which is whether a competent person
 * looked at the file and accepted it — because a file can hold every certificate and still be
 * rubbish: the method statement describes different work, the appointment names somebody who left.
 *
 * DRAFT → SUBMITTED → UNDER_REVIEW → CHANGES_REQUIRED → APPROVED → SUSPENDED → EXPIRED / BLOCKED.
 *
 * Three decisions worth stating:
 *
 * **Versions are the unit of review.** Changes requested on version 1 means the next submission is
 * version 2. Without that, "rejected then fixed" has nowhere to live: the rejection reason gets
 * overwritten by the approval, and the history an auditor wants is the one thing lost.
 *
 * **The decision goes through `ApprovalService`.** It already owns steps, segregation of duties,
 * delegation, signatures and a frozen workflow snapshot, and `CONTRACTOR` is already one of its
 * subject types. A second decision mechanism beside it would be a second answer to "who may
 * approve". Where a tenant has configured no workflow the reviewer decides directly against their
 * entitlement — refusing to let anybody approve until a workflow exists would make the feature
 * unusable on the day it ships.
 *
 * **Only legal moves are allowed, and the refusal says what is possible.** A state machine whose
 * illegal transitions fail silently is a state machine that eventually holds every state at once.
 */
class SafetyFileWorkflow
{
    public const DRAFT = 'DRAFT';

    public const SUBMITTED = 'SUBMITTED';

    public const UNDER_REVIEW = 'UNDER_REVIEW';

    public const CHANGES_REQUIRED = 'CHANGES_REQUIRED';

    public const APPROVED = 'APPROVED';

    public const SUSPENDED = 'SUSPENDED';

    public const EXPIRED = 'EXPIRED';

    public const BLOCKED = 'BLOCKED';

    public const STATES = [
        self::DRAFT, self::SUBMITTED, self::UNDER_REVIEW, self::CHANGES_REQUIRED,
        self::APPROVED, self::SUSPENDED, self::EXPIRED, self::BLOCKED,
    ];

    /**
     * What may follow what.
     *
     * CHANGES_REQUIRED goes to SUBMITTED rather than straight to APPROVED: a file that was sent
     * back is re-submitted as a new version and reviewed again, which is the point of sending it
     * back. EXPIRED and BLOCKED both return to SUBMITTED, because the way out of either is a fresh
     * file rather than somebody reinstating the old one.
     */
    private const TRANSITIONS = [
        self::DRAFT => [self::SUBMITTED],
        self::SUBMITTED => [self::UNDER_REVIEW, self::CHANGES_REQUIRED, self::APPROVED, self::BLOCKED],
        self::UNDER_REVIEW => [self::CHANGES_REQUIRED, self::APPROVED, self::BLOCKED],
        self::CHANGES_REQUIRED => [self::SUBMITTED, self::BLOCKED],
        self::APPROVED => [self::SUSPENDED, self::EXPIRED, self::BLOCKED],
        self::SUSPENDED => [self::APPROVED, self::BLOCKED, self::EXPIRED],
        self::EXPIRED => [self::SUBMITTED],
        self::BLOCKED => [self::SUBMITTED],
    ];

    /** How long an approval stands when the tenant has not said. */
    private const DEFAULT_VALIDITY_MONTHS = 12;

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly SafetyFileService $safetyFile,
    ) {}

    /**
     * The version in play, created as a draft if there is none.
     *
     * @return object the submission row
     */
    public function current(int $companyId, string $contractorUuid, ?int $actorId = null): object
    {
        $contractorId = $this->contractorId($companyId, $contractorUuid);

        $existing = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')
            ->orderByDesc('version')->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->openVersion($companyId, $contractorId, 1, $actorId);
    }

    /**
     * Hand the file in for review.
     *
     * Refused while sections the host demands are unsatisfied: submitting a file that cannot
     * possibly be approved wastes the reviewer's time and teaches contractors that submission is a
     * formality. The refusal names what is missing, so it is actionable rather than a door closing.
     */
    public function submit(int $companyId, string $contractorUuid, ?int $actorId = null,
        ?string $comment = null): object
    {
        $submission = $this->current($companyId, $contractorUuid, $actorId);

        if (in_array($submission->state, [self::SUBMITTED, self::UNDER_REVIEW], true)) {
            throw new RuntimeException('That safety file is already with a reviewer.');
        }

        if ($submission->state === self::APPROVED) {
            throw new RuntimeException(
                'That safety file is already approved. Submit a new version only when something '
                .'in it has changed.'
            );
        }

        $file = $this->safetyFile->assemble($companyId, $contractorUuid);
        $missing = $file['summary']['missing'];

        if ($missing !== []) {
            throw new RuntimeException(sprintf(
                'This file cannot be submitted yet: %s. Submitting a file that cannot be approved '
                .'wastes the reviewer\'s time and teaches everybody that submission is a formality.',
                implode('; ', array_map(fn (array $gap) => $gap['name'], $missing)),
            ));
        }

        /*
         * A version that was sent back is re-submitted as the next version.
         *
         * The previous one keeps its rejection reason and its requested changes, which is the
         * history an auditor is looking for — "what did you send first, and what did we say about
         * it".
         */
        if (in_array($submission->state, [self::CHANGES_REQUIRED, self::EXPIRED, self::BLOCKED], true)) {
            $submission = $this->openVersion($companyId, (int) $submission->contractor_id,
                (int) $submission->version + 1, $actorId);
        }

        return DB::transaction(function () use ($companyId, $submission, $actorId, $comment, $contractorUuid) {
            DB::table('sheq_safety_file_submissions')->where('id', $submission->id)->update([
                'state' => self::SUBMITTED,
                'submitted_by' => $actorId,
                'submitted_at' => now(),
                'comments' => $comment,
                'updated_at' => now(),
            ]);

            $this->record($companyId, (int) $submission->id, $submission->state, self::SUBMITTED,
                $actorId, null, $comment);

            /*
             * The review chain, where the tenant configured one.
             *
             * `openIfConfigured` is a no-op without a workflow, so a tenant that has not set one up
             * is not blocked from ever approving a file — the reviewer decides directly against
             * their entitlement instead.
             */
            $request = $this->approvals->openIfConfigured($companyId, [
                'subject_type' => 'CONTRACTOR',
                'subject_id' => (int) $submission->contractor_id,
                'subject_label' => 'Safety file v'.$submission->version,
                'requested_by' => (int) ($actorId ?? 0),
                'note' => $comment,
            ]);

            if ($request !== null) {
                DB::table('sheq_safety_file_submissions')->where('id', $submission->id)->update([
                    'state' => self::UNDER_REVIEW,
                    'approval_request_id' => $request['id'],
                    'updated_at' => now(),
                ]);

                $this->record($companyId, (int) $submission->id, self::SUBMITTED, self::UNDER_REVIEW,
                    $actorId, 'An approval chain is configured, so the file went to it.', null);
            }

            $this->audit($companyId, $actorId, 'safety_file.submitted',
                (int) $submission->contractor_id, ['state' => $submission->state],
                ['state' => $request !== null ? self::UNDER_REVIEW : self::SUBMITTED,
                    'version' => $submission->version]);

            $this->refreshContractor($companyId, $contractorUuid, $actorId);

            return $this->reload((int) $submission->id);
        });
    }

    /** Pick it up for review, so the contractor can see somebody has it. */
    public function startReview(int $companyId, string $contractorUuid, ?int $actorId = null): object
    {
        return $this->move($companyId, $contractorUuid, self::UNDER_REVIEW, $actorId, null, null,
            fn (array $changes) => $changes + ['reviewer_id' => $actorId]);
    }

    /**
     * Send it back with a list of what has to change.
     *
     * The list is separate from the reason: a reason is for the record, requested changes are a
     * list somebody works through, and collapsing them into one text box loses the second.
     */
    public function requestChanges(int $companyId, string $contractorUuid, string $reason,
        array $changes = [], ?int $actorId = null): object
    {
        if (trim($reason) === '') {
            throw new RuntimeException(
                'Sending a safety file back needs a reason. "Rejected" on its own starts a phone '
                .'call rather than a corrected file.'
            );
        }

        return $this->move($companyId, $contractorUuid, self::CHANGES_REQUIRED, $actorId, $reason,
            null, fn (array $base) => $base + [
                'reviewer_id' => $actorId,
                'reviewed_at' => now(),
                'decision_reason' => $reason,
                'requested_changes' => $changes === [] ? null : json_encode(array_values($changes)),
            ]);
    }

    /**
     * Accept the file, for a stated period.
     *
     * Where an approval chain is configured, this refuses until the chain has cleared — the chain
     * is the decision, and letting a reviewer approve around it would make segregation of duties
     * advisory.
     */
    public function approve(int $companyId, string $contractorUuid, ?int $actorId = null,
        ?string $validUntil = null, ?string $comment = null): object
    {
        $submission = $this->current($companyId, $contractorUuid, $actorId);

        if ($submission->approval_request_id !== null) {
            $blocked = $this->approvals->blockingReason($companyId, 'CONTRACTOR',
                (int) $submission->contractor_id);

            if ($blocked !== null) {
                throw new RuntimeException($blocked);
            }
        }

        $months = $this->validityMonths($companyId);

        return $this->move($companyId, $contractorUuid, self::APPROVED, $actorId, null, $comment,
            fn (array $base) => $base + [
                'reviewer_id' => $actorId,
                'reviewed_at' => now(),
                'decision_reason' => null,
                'valid_until' => $validUntil
                    ?? Carbon::today()->addMonths($months)->toDateString(),
                'comments' => $comment,
            ]);
    }

    /** Stop it standing, without refusing it outright. */
    public function suspend(int $companyId, string $contractorUuid, string $reason,
        ?int $actorId = null): object
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Suspending a safety file needs a reason.');
        }

        return $this->move($companyId, $contractorUuid, self::SUSPENDED, $actorId, $reason, null,
            fn (array $base) => $base + ['decision_reason' => $reason]);
    }

    /** Let it stand again. */
    public function reinstate(int $companyId, string $contractorUuid, ?int $actorId = null,
        ?string $comment = null): object
    {
        return $this->move($companyId, $contractorUuid, self::APPROVED, $actorId, null, $comment,
            fn (array $base) => $base + ['decision_reason' => null]);
    }

    /**
     * Expire every approval whose validity has passed, for one tenant.
     *
     * Time does this, not a person: an approval that stood until March stops standing in April
     * whether or not anybody opens the screen. Returns how many moved, so a command can report it.
     */
    public function expireLapsed(int $companyId, ?Carbon $today = null): int
    {
        $today ??= Carbon::today();

        $lapsed = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $companyId)->where('state', self::APPROVED)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $today->toDateString())
            ->whereNull('deleted_at')
            ->get(['id', 'contractor_id', 'valid_until']);

        foreach ($lapsed as $submission) {
            DB::table('sheq_safety_file_submissions')->where('id', $submission->id)
                ->update(['state' => self::EXPIRED, 'updated_at' => now()]);

            $this->record($companyId, (int) $submission->id, self::APPROVED, self::EXPIRED, null,
                sprintf('The approval stood until %s.', $submission->valid_until), null);

            $this->audit($companyId, null, 'safety_file.expired', (int) $submission->contractor_id,
                ['state' => self::APPROVED], ['state' => self::EXPIRED],
                sprintf('The approval stood until %s.', $submission->valid_until));
        }

        return $lapsed->count();
    }

    /**
     * Every version and every transition, newest first.
     *
     * The approval history CON-7 asks for. Read from the transitions table rather than the audit
     * log because this is a screen's list and the audit log is the estate's tamper-evident record —
     * different jobs, and querying the second for the first couples a UI to a security artefact.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $companyId, string $contractorUuid): array
    {
        $contractorId = $this->contractorId($companyId, $contractorUuid);

        $submissions = DB::table('sheq_safety_file_submissions as s')
            ->leftJoin('users as submitter', 'submitter.id', '=', 's.submitted_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 's.reviewer_id')
            ->where('s.company_id', $companyId)->where('s.contractor_id', $contractorId)
            ->whereNull('s.deleted_at')
            ->orderByDesc('s.version')
            ->get(['s.id', 's.uuid', 's.version', 's.state', 's.submitted_at', 's.reviewed_at',
                's.decision_reason', 's.requested_changes', 's.comments', 's.valid_until',
                'submitter.email as submitted_by', 'reviewer.email as reviewed_by']);

        $transitions = DB::table('sheq_safety_file_transitions as t')
            ->leftJoin('users as u', 'u.id', '=', 't.actor_id')
            ->where('t.company_id', $companyId)
            ->whereIn('t.submission_id', $submissions->pluck('id'))
            ->orderByDesc('t.id')
            ->get(['t.submission_id', 't.from_state', 't.to_state', 't.occurred_at', 't.reason',
                't.comment', 'u.email as actor'])
            ->groupBy('submission_id');

        return $submissions->map(fn ($submission) => [
            'uuid' => $submission->uuid,
            'version' => (int) $submission->version,
            'state' => $submission->state,
            'submitted_at' => $submission->submitted_at,
            'submitted_by' => $submission->submitted_by,
            'reviewed_at' => $submission->reviewed_at,
            'reviewed_by' => $submission->reviewed_by,
            'decision_reason' => $submission->decision_reason,
            'requested_changes' => json_decode((string) $submission->requested_changes, true) ?: [],
            'comments' => $submission->comments,
            'valid_until' => $submission->valid_until,
            'transitions' => ($transitions[$submission->id] ?? collect())
                ->map(fn ($t) => (array) $t)->values()->all(),
        ])->values()->all();
    }

    /** The tenant's approval validity, in months. */
    public function validityMonths(int $companyId): int
    {
        $stored = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('safety_file_validity_months');

        return $stored === null ? self::DEFAULT_VALIDITY_MONTHS : (int) $stored;
    }

    // ── Internals ─────────────────────────────────────────────────────────────────────────────

    /**
     * Move the current version to a new state, or refuse and say what is possible.
     *
     * A state machine whose illegal transitions fail quietly is one that eventually holds every
     * state at once, so the refusal names the moves that are available from here.
     */
    private function move(int $companyId, string $contractorUuid, string $to, ?int $actorId,
        ?string $reason, ?string $comment, ?callable $extra = null): object
    {
        $submission = $this->current($companyId, $contractorUuid, $actorId);
        $from = (string) $submission->state;

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new RuntimeException(sprintf(
                'A safety file that is %s cannot become %s. From here it can only become: %s.',
                $this->plain($from), $this->plain($to),
                implode(', ', array_map([$this, 'plain'], self::TRANSITIONS[$from] ?? [])) ?: 'nothing',
            ));
        }

        return DB::transaction(function () use ($companyId, $submission, $from, $to, $actorId,
            $reason, $comment, $extra, $contractorUuid) {
            $changes = ['state' => $to, 'updated_at' => now()];

            if ($extra !== null) {
                $changes = $extra($changes);
            }

            DB::table('sheq_safety_file_submissions')->where('id', $submission->id)->update($changes);

            $this->record($companyId, (int) $submission->id, $from, $to, $actorId, $reason, $comment);

            $this->audit($companyId, $actorId, 'safety_file.'.strtolower($to),
                (int) $submission->contractor_id, ['state' => $from],
                ['state' => $to, 'version' => $submission->version], $reason);

            // The contractor's own status is computed from this among other things, so it moves
            // in the same breath rather than at the next document upload.
            $this->refreshContractor($companyId, $contractorUuid, $actorId);

            /*
             * And tell them what was decided (CON-19).
             *
             * Here rather than in `approve()` and `requestChanges()` separately, because every
             * transition passes through this method and a hook per caller is a hook somebody
             * forgets to add to the next one.
             *
             * Queued, not sent: this is inside a transaction that can still roll back, and a
             * contractor told their file was approved by a transaction that then failed is
             * worse off than one told nothing.
             */
            $this->announce($companyId, (int) $submission->contractor_id, $to, $reason,
                $changes);

            return $this->reload((int) $submission->id);
        });
    }

    /**
     * Tell the contractor a decision was taken on their file (CON-19).
     *
     * Only the four states that are a decision ABOUT them. SUBMITTED and UNDER_REVIEW are the
     * host's own progress and announcing them would be noise; EXPIRED is already carried by
     * the access-status change, and sending both would be the same fact twice.
     *
     * @param  array<string,mixed>  $changes  the update that was just applied
     */
    private function announce(int $companyId, int $contractorId, string $to, ?string $reason,
        array $changes): void
    {
        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('id', $contractorId)
            ->first(['id', 'name', 'contact_email', 'contact_phone']);

        if ($contractor === null) {
            return;
        }

        $notifier = app(ContractorNotifier::class);

        if ($to === self::APPROVED) {
            $notifier->safetyFileApproved($companyId, $contractor,
                $changes['valid_until'] ?? null);

            return;
        }

        /*
         * CHANGES_REQUIRED, BLOCKED and SUSPENDED are all "we are not accepting this", and a
         * contractor needs the same thing from each: what is wrong, in the reviewer's words.
         * The wording differs; the message does not.
         */
        if (in_array($to, [self::CHANGES_REQUIRED, self::BLOCKED, self::SUSPENDED], true)) {
            $decoded = isset($changes['requested_changes'])
                ? (json_decode((string) $changes['requested_changes'], true) ?: [])
                : [];

            $notifier->safetyFileRejected($companyId, $contractor, $to, $reason, $decoded);
        }
    }

    private function openVersion(int $companyId, int $contractorId, int $version,
        ?int $actorId): object
    {
        $id = DB::table('sheq_safety_file_submissions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'contractor_id' => $contractorId,
            'version' => $version,
            'state' => self::DRAFT,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->record($companyId, (int) $id, null, self::DRAFT, $actorId, null, null);

        return $this->reload((int) $id);
    }

    /** Append-only: the history is worthless if a row can be rewritten. */
    private function record(int $companyId, int $submissionId, ?string $from, string $to,
        ?int $actorId, ?string $reason, ?string $comment): void
    {
        DB::table('sheq_safety_file_transitions')->insert([
            'company_id' => $companyId,
            'submission_id' => $submissionId,
            'from_state' => $from,
            'to_state' => $to,
            'actor_id' => $actorId,
            'occurred_at' => now(),
            'reason' => $reason,
            'comment' => $comment,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reload(int $id): object
    {
        return DB::table('sheq_safety_file_submissions')->where('id', $id)->first();
    }

    private function contractorId(int $companyId, string $contractorUuid): int
    {
        $id = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('uuid', $contractorUuid)
            ->whereNull('deleted_at')->value('id');

        if ($id === null) {
            throw new RuntimeException("No contractor {$contractorUuid} in this company.");
        }

        return (int) $id;
    }

    private function refreshContractor(int $companyId, string $contractorUuid, ?int $actorId): void
    {
        app(ContractorService::class)->refreshAccessStatus($companyId, $contractorUuid, [], $actorId);
    }

    private function plain(string $state): string
    {
        return strtolower(str_replace('_', ' ', $state));
    }

    private function audit(int $companyId, ?int $actorId, string $action, int $contractorId,
        ?array $before, ?array $after, ?string $reason = null): void
    {
        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => $action,
            'subject_type' => 'SafetyFile',
            'subject_id' => $contractorId,
            'before' => $before ? json_encode($before) : null,
            'after' => $after ? json_encode($after) : null,
            'reason' => $reason,
        ]);
    }
}
