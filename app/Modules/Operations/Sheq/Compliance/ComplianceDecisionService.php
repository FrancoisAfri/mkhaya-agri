<?php

namespace App\Modules\Operations\Sheq\Compliance;

use App\Modules\Operations\Sheq\Services\ActivityService;
use App\Modules\Operations\Sheq\Services\ContractorComplianceStatus;
use App\Modules\Operations\Sheq\Services\ContractorDocumentEngine;
use App\Modules\Operations\Sheq\Services\ContractorService;
use App\Modules\Operations\Sheq\Services\SiteApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * May this person do this work, at this site, right now (COM-1)?
 *
 * One question, asked in one place. Before this the module answered it three times over, and the
 * three could disagree.
 *
 * The gate had its own checks. The QR pass verification had its own, written separately, reading
 * the same registers in a different order with a different idea of what a lapsed site approval
 * meant. The contractor register computed a third verdict for the status column. Nobody had done
 * anything wrong — each was built for the screen in front of it — but the result was a system that
 * could refuse a worker at the boom, show them compliant in the register, and print them a pass
 * that scanned green. Whichever answer a host saw first became the one they believed, and the
 * other two looked like bugs.
 *
 * What this service does NOT do is re-derive what already exists. The document rules live in
 * `ContractorService::complianceFile`, the blocking configuration in `ContractorComplianceStatus`,
 * the activity requirements in `ActivityService`. A second implementation of any of those would
 * recreate exactly the disagreement this is here to end. It composes them, at the right grain, and
 * adds the checks none of them did: this one person's induction, medical and tickets, and what the
 * work itself requires.
 *
 * Three properties the callers depend on:
 *
 * **Every refusal carries a code and a sentence.** The code is for the system — counted, charted,
 * routed, translated. The sentence is for the person at the boom, who cannot be handed
 * `MEDICAL_EXPIRED` and told to interpret it. See `ReasonCode`.
 *
 * **A check that could not run is not a check that passed** (COM-3). Unverifiable evidence gets
 * its own outcome, and what that outcome means is the host's decision — not silence.
 *
 * **It shows its work.** Every check is returned, including the ones that passed and the ones that
 * did not apply, because "why was this person blocked" (COM-5) and "how was this score calculated"
 * (CON-20) are both the same question asked of the same evidence, and an answer that only lists
 * the failures cannot distinguish "we checked and it was fine" from "we never looked".
 */
class ComplianceDecisionService
{
    /** Cleared. Every mandatory check ran and passed. */
    public const ALLOWED = 'ALLOWED';

    /** At least one mandatory check failed, with a reason. */
    public const DENIED = 'DENIED';

    /**
     * Nothing failed, but something could not be established, and the host wants a human.
     *
     * The useful middle for a staffed gate: the guard phones the safety office rather than
     * refusing a crew or waving them through.
     */
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';

    /**
     * Nothing failed, something could not be established, and the host wants it recorded only.
     *
     * Still not a pass — `allowed` is false — but it carries no instruction. For an unstaffed
     * reader where the realistic alternatives are "open on no information" and "strand a shift",
     * and the honest thing is to say which one happened.
     */
    public const UNKNOWN = 'UNKNOWN';

    public const OUTCOMES = [self::ALLOWED, self::DENIED, self::REVIEW_REQUIRED, self::UNKNOWN];

    /** What an unverifiable mandatory check does. Configured per tenant (COM-3). */
    public const UNVERIFIED_OUTCOMES = [self::REVIEW_REQUIRED, self::DENIED, self::UNKNOWN];

    /**
     * The shipped answer to "we could not tell".
     *
     * REVIEW_REQUIRED rather than DENIED, and the reasoning is the same one that makes monitor
     * mode the default for enforcement. DENIED on unverifiable evidence means the first time a
     * register is slow, a column is renamed by a migration or a date arrives in an unreadable
     * shape, a shift stands outside in the dark — and the response to that is always to switch
     * the whole system off, permanently. REVIEW_REQUIRED refuses to answer and says so, which is
     * what COM-3 is actually asking for: not silently granting.
     *
     * A host running an unstaffed reader with nobody to escalate to sets UNKNOWN. A host who
     * would rather turn people away than admit one unverified person sets DENIED. Both are
     * defensible; neither is a safe default for everybody.
     */
    public const DEFAULT_UNVERIFIED_OUTCOME = self::REVIEW_REQUIRED;

    /** A check passed, failed, could not be run, or did not apply. */
    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNVERIFIED = 'UNVERIFIED';

    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    /**
     * The twelve inputs COM-1 lists, in the order a decision reads best.
     *
     * Declared rather than implied by whichever methods happen to exist, so `ComplianceDecisionTest`
     * can assert the engine actually evaluates all twelve. A requirement that lists its inputs and
     * an implementation that quietly checks eleven of them is the failure this guards: nothing
     * errors, and the twelfth condition never refuses anybody.
     */
    public const CHECKS = [
        'CONTRACTOR' => 'The contractor is active and not blocked or suspended.',
        'DOCUMENTS' => 'The mandatory documents are on file, accepted and in date.',
        'SAFETY_FILE' => 'The safety file has been approved and has not lapsed.',
        'SITE_APPROVAL' => 'This site has approved this contractor.',
        'WORKER' => 'The person is on the contractor\'s active roster.',
        'INDUCTION' => 'The person holds a current site induction.',
        'MEDICAL' => 'The person holds a current certificate of fitness.',
        'COMPETENCY' => 'The person holds the tickets this work requires.',
        'RISK_ASSESSMENT' => 'A risk assessment is in force for this work.',
        'METHOD_STATEMENT' => 'A method statement has been supplied for this work.',
        'PERMIT' => 'A permit to work is open for this work.',
        'PPE' => 'The PPE this work requires has been issued.',
        'EQUIPMENT_CERTIFICATION' => 'The equipment this work requires is certified.',
    ];

    /**
     * Which host switch governs each refusal, where one does (CON-10).
     *
     * A code absent from this map always refuses. Those are the ones that are not a
     * configuration question: a contractor somebody explicitly blocked, a person who is not on
     * any roster, a ticket that has run out. A head-office switch that could wave those through
     * would make the explicit decision decorative.
     *
     * The rest default off, so declaring a requirement records it before it refuses anybody. The
     * sequence matters on a live site: switch the requirement on, read for a fortnight who
     * *would* be refused, fix the backlog, then switch the rule on. The alternative is finding
     * out at 06:00 on Monday with a crew of twelve at the boom, and the response to that is
     * always to turn the whole system off.
     *
     * @var array<string,string>
     */
    private const GOVERNING_RULE = [
        /*
         * A site that has never decided, and one whose approval has lapsed, are both governed by
         * MISSING_SITE_APPROVAL — which ships off, because on the day per-site approval arrived
         * no site had decided anything. A site that explicitly BLOCKED somebody is deliberately
         * absent from this map: that is a decision taken at the site, for a reason the
         * company-wide file knows nothing about, and no head-office switch may walk them back
         * through that gate.
         */
        ReasonCode::SITE_NOT_APPROVED => 'MISSING_SITE_APPROVAL',
        ReasonCode::SITE_APPROVAL_EXPIRED => 'MISSING_SITE_APPROVAL',
        ReasonCode::MEDICAL_REQUIRED => 'MISSING_MEDICAL',
        /*
         * MEDICAL_EXPIRED is deliberately NOT here, and neither is COMPETENCY_EXPIRED.
         *
         * A certificate that has run out is not a gap in the filing — it is a qualification that
         * has lapsed, and refusing on it is the entire purpose of recording an expiry date. The
         * configurable `EXPIRED_MEDICAL` rule still governs how an expired medical affects the
         * contractor's STANDING in the register, which is a different question: whether the firm
         * is in good order, as against whether this person may start work this morning.
         */
        ReasonCode::COMPETENCY_MISSING => 'MISSING_ACTIVITY_COMPETENCY',
        ReasonCode::COMPETENCY_NOT_ACCEPTED => 'MISSING_ACTIVITY_COMPETENCY',
        ReasonCode::RISK_ASSESSMENT_REQUIRED => 'MISSING_RISK_ASSESSMENT',
        ReasonCode::METHOD_STATEMENT_REQUIRED => 'MISSING_METHOD_STATEMENT',
        ReasonCode::PERMIT_REQUIRED => 'MISSING_PERMIT',
        ReasonCode::EQUIPMENT_CERTIFICATION_REQUIRED => 'MISSING_EQUIPMENT_CERTIFICATE',
    ];

    public function __construct(
        private readonly ContractorService $contractors,
        private readonly ContractorComplianceStatus $rules,
        private readonly ActivityService $activities,
    ) {}

    /**
     * Decide for one contractor worker.
     *
     * `$at` rather than "now" throughout, because the requirement says *right now* and an audit
     * asks the same question about a moment that has passed: were they eligible when they walked
     * in at 05:48? A decision that can only speak about the present cannot answer that, and the
     * answer is the whole of CON-22.
     *
     * @return array<string,mixed>
     */
    public function forWorker(int $companyId, int $workerId, ?int $siteId = null,
        ?string $activityCode = null, ?Carbon $at = null): array
    {
        $at ??= Carbon::now();

        $worker = $this->worker($companyId, $workerId);

        if ($worker === null) {
            return $this->decision($companyId, [
                $this->failed('WORKER', ReasonCode::WORKER_UNKNOWN,
                    'This person is not on any contractor roster in this company.'),
            ], $at, null, null, $siteId, $activityCode);
        }

        $contractor = $this->contractorRow($companyId, (int) $worker->contractor_id);

        if ($contractor === null) {
            return $this->decision($companyId, [
                $this->failed('CONTRACTOR', ReasonCode::CONTRACTOR_UNKNOWN, sprintf(
                    '%s is recorded against a contractor that no longer exists.', $worker->name)),
            ], $at, $worker, null, $siteId, $activityCode);
        }

        $checks = array_merge(
            $this->contractorScopeChecks($companyId, $contractor, $siteId, $at),
            $this->workerScopeChecks($companyId, $worker, $contractor, $siteId, $activityCode, $at),
            $this->workScopeChecks($companyId, $contractor, $siteId, $activityCode, $at),
        );

        return $this->decision($companyId, $checks, $at, $worker, $contractor, $siteId, $activityCode);
    }

    /**
     * Decide for a contractor as a whole, with no particular person.
     *
     * "May this contractor work at this site" — the question the register column answers and the
     * one a site manager asks before a crew is booked. It runs the contractor-scope checks only:
     * the person-scope ones have no subject, and answering them for every worker on the roster
     * would turn one question into forty.
     *
     * @return array<string,mixed>
     */
    public function forContractor(int $companyId, int $contractorId, ?int $siteId = null,
        ?Carbon $at = null): array
    {
        $at ??= Carbon::now();
        $contractor = $this->contractorRow($companyId, $contractorId);

        if ($contractor === null) {
            return $this->decision($companyId, [
                $this->failed('CONTRACTOR', ReasonCode::CONTRACTOR_UNKNOWN,
                    'There is no such contractor in this company.'),
            ], $at, null, null, $siteId, null);
        }

        return $this->decision($companyId,
            $this->contractorScopeChecks($companyId, $contractor, $siteId, $at),
            $at, null, $contractor, $siteId, null);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // The contractor's own standing: itself, its documents, its file, this site.
    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function contractorScopeChecks(int $companyId, object $contractor, ?int $siteId,
        Carbon $at): array
    {
        $checks = [$this->contractorCheck($contractor)];

        /*
         * The document, safety-file and site findings come from the services that own them, asked
         * WITHOUT an activity.
         *
         * Without an activity on purpose. `ContractorComplianceStatus` answers for the whole
         * roster when given one — right for a contractor's standing, wrong here: it would refuse
         * the person at the boom because a different worker's medical had lapsed. This one
         * person's tickets are checked below, where their name can be put to the finding.
         */
        $read = $this->safely(function () use ($companyId, $contractor, $siteId) {
            $file = $this->contractors->complianceFile($companyId, $contractor->uuid, [], $siteId);

            return $this->rules->evaluate($companyId, (int) $contractor->id, $file, $siteId);
        });

        if (! $read['ok']) {
            /*
             * COM-3 in its most literal form. The documents are the bulk of what a contractor is
             * judged on, so a failure to read them cannot come back as nothing-to-report.
             */
            $e = $read['error'];

            return array_merge($checks, [
                $this->unverifiable('DOCUMENTS', 'The document register could not be read.', $e),
                $this->unverifiable('SAFETY_FILE', 'The safety file could not be read.', $e),
                $this->unverifiable('SITE_APPROVAL', 'Site approvals could not be read.', $e),
            ]);
        }

        $status = $read['value'];

        $coded = $this->codeFindings($status);

        $checks[] = $this->fromFindings('DOCUMENTS', $coded, 'DOCUMENT');
        $checks[] = $this->fromFindings('SAFETY_FILE', $coded, 'SAFETY_FILE');
        $checks[] = $this->siteApprovalCheck($companyId, $contractor, $siteId);

        return $checks;
    }

    /**
     * What THIS site decided about this contractor (CON-14).
     *
     * Asked of `SiteApprovalService` rather than read out of the compliance file, even though the
     * file carries a site finding too. The decision belongs to the site service, and the file's
     * version of it exists for the register's benefit — routing the gate through the file would
     * mean the sentence a guard reads at the boom is composed two services away from the decision
     * it describes, and the reason a site gave for a ban is the most useful thing on that screen.
     */
    private function siteApprovalCheck(int $companyId, object $contractor, ?int $siteId): array
    {
        if ($siteId === null) {
            // Said plainly rather than passed silently: a decision taken without a site did not
            // check the site, and a screen showing a green tick there would be lying.
            return $this->skipped('SITE_APPROVAL',
                'No site was named, so no site approval was checked.');
        }

        $read = $this->safely(fn () => app(SiteApprovalService::class)
            ->statusFor($companyId, (int) $contractor->id, $siteId));

        if (! $read['ok']) {
            return $this->unverifiable('SITE_APPROVAL', 'Site approvals could not be read.',
                $read['error']);
        }

        $decision = $read['value'];

        if ($decision['status'] === SiteApprovalService::BLOCKED) {
            return $this->failed('SITE_APPROVAL', ReasonCode::SITE_BLOCKED, sprintf(
                '%s is blocked at this site: %s', $contractor->name,
                $decision['reason'] ?: 'no reason recorded.'));
        }

        if ($decision['status'] === SiteApprovalService::EXPIRED) {
            return $this->failed('SITE_APPROVAL', ReasonCode::SITE_APPROVAL_EXPIRED, sprintf(
                '%s was approved for this site until %s. The approval has lapsed.',
                $contractor->name, $decision['valid_until']));
        }

        if ($decision['status'] === SiteApprovalService::UNDECIDED) {
            return $this->failed('SITE_APPROVAL', ReasonCode::SITE_NOT_APPROVED,
                sprintf('This site has not approved %s.', $contractor->name));
        }

        /*
         * Conditions are printed, never enforced. "Escorted at all times" is a condition a human
         * enforces by walking alongside somebody; a turnstile cannot check it, and a system that
         * refused on it would be refusing work the site expressly approved.
         */
        if (! empty($decision['conditions'])) {
            return [
                'key' => 'SITE_APPROVAL', 'state' => self::PASS,
                'note' => 'Conditions of entry: '.$decision['conditions'],
                'evidence' => ['conditions' => $decision['conditions']],
                'findings' => [[
                    'code' => ReasonCode::SITE_NOT_APPROVED, 'subject' => 'SITE',
                    'severity' => 'ADVISORY',
                    'detail' => 'Conditions of entry: '.$decision['conditions'],
                ]],
            ];
        }

        return $this->passed('SITE_APPROVAL',
            sprintf('This site has approved %s.', $contractor->name));
    }

    private function contractorCheck(object $contractor): array
    {
        $status = strtoupper((string) $contractor->access_status);

        if (! $contractor->is_active) {
            return $this->failed('CONTRACTOR', ReasonCode::CONTRACTOR_INACTIVE,
                sprintf('%s is no longer an active contractor.', $contractor->name));
        }

        if ($status === 'SUSPENDED') {
            return $this->failed('CONTRACTOR', ReasonCode::CONTRACTOR_SUSPENDED,
                sprintf('%s is suspended — their SHEQ compliance is not in order.',
                    $contractor->name));
        }

        if ($status === 'BLOCKED') {
            return $this->failed('CONTRACTOR', ReasonCode::CONTRACTOR_BLOCKED,
                sprintf('%s is blocked — their SHEQ compliance is not in order.', $contractor->name));
        }

        return $this->passed('CONTRACTOR', sprintf('%s is an active contractor.', $contractor->name));
    }

    /**
     * Turn the rule-shaped findings into coded ones.
     *
     * `ContractorComplianceStatus` reports a `rule` (which host switch governs this) and a
     * `reason` (what kind of problem it is). Neither is a denial code: a rule is a configuration
     * key, and several different problems share one. The code is derived from the pair.
     *
     * @return list<array<string,mixed>>
     */
    private function codeFindings(array $status): array
    {
        $out = [];

        foreach ([self::FAIL => 'blocking', self::PASS => 'advisory'] as $state => $key) {
            foreach ($status[$key] ?? [] as $finding) {
                $code = $this->codeFor((string) $finding['rule'], (string) ($finding['reason'] ?? ''));

                /*
                 * Site findings are dropped here and asked of the site service instead. Keeping
                 * both would report an unapproved site twice, in two different wordings, which
                 * reads as two problems.
                 */
                if (ReasonCode::subjectOf($code) === 'SITE') {
                    continue;
                }

                $out[] = [
                    'code' => $code,
                    'subject' => ReasonCode::subjectOf($code),
                    // A finding in the advisory list is one the host chose not to refuse for. It
                    // is still reported — dropping it would make the switch mean "stop checking"
                    // — but it cannot deny.
                    'severity' => $state === self::FAIL ? 'BLOCKING' : 'ADVISORY',
                    'detail' => (string) ($finding['detail'] ?? ''),
                    'rule' => (string) $finding['rule'],
                ];
            }
        }

        return $out;
    }

    private function codeFor(string $rule, string $reason): string
    {
        return match (true) {
            $rule === 'SITE_BLOCKED' => ReasonCode::SITE_BLOCKED,
            $rule === 'MISSING_SITE_APPROVAL' => $reason === 'EXPIRED'
                ? ReasonCode::SITE_APPROVAL_EXPIRED : ReasonCode::SITE_NOT_APPROVED,
            $rule === 'EXPIRED_SAFETY_FILE' => ReasonCode::SAFETY_FILE_EXPIRED,
            $rule === 'SUSPENDED_SAFETY_FILE' => ReasonCode::SAFETY_FILE_SUSPENDED,
            $rule === 'REJECTED_SAFETY_FILE' => ReasonCode::SAFETY_FILE_NOT_APPROVED,
            $rule === 'MISSING_RISK_ASSESSMENT' => ReasonCode::RISK_ASSESSMENT_REQUIRED,
            $rule === 'EXPIRED_MEDICAL' => ReasonCode::MEDICAL_EXPIRED,
            $rule === 'EXPIRED_COMPETENCY' => ReasonCode::COMPETENCY_EXPIRED,
            $rule === 'MISSING_ACTIVITY_COMPETENCY' => ReasonCode::COMPETENCY_MISSING,
            $rule === 'INCOMPLETE_INDUCTION' => ReasonCode::INDUCTION_REQUIRED,
            $reason === 'EXPIRED' => ReasonCode::DOCUMENT_EXPIRED,
            $reason === 'NOT_ACCEPTED' => ReasonCode::DOCUMENT_NOT_ACCEPTED,
            $reason === 'REJECTED' => ReasonCode::DOCUMENT_REJECTED,
            default => ReasonCode::DOCUMENT_MISSING,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // This one person: on the roster, inducted, medically fit, ticketed.
    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function workerScopeChecks(int $companyId, object $worker, object $contractor,
        ?int $siteId, ?string $activityCode, Carbon $at): array
    {
        $checks = [];

        $checks[] = $worker->is_active
            ? $this->passed('WORKER', sprintf('%s is on %s\'s active roster.',
                $worker->name, $contractor->name))
            : $this->failed('WORKER', ReasonCode::WORKER_INACTIVE,
                sprintf('%s is no longer on %s\'s roster.', $worker->name, $contractor->name));

        $checks[] = $this->inductionCheck($companyId, $worker, $siteId, $at);
        $checks[] = $this->medicalCheck($companyId, $worker, $activityCode, $at);
        $checks[] = $this->competencyCheck($companyId, $worker, $activityCode, $at);

        return $checks;
    }

    /**
     * A current site induction, from either source.
     *
     * Two places hold this and both are evidence: a date imported from the contractor's own
     * paperwork onto the roster row, and a completion recorded in this system. Honouring only one
     * would turn somebody away who holds the other, which is pedantry at a boom.
     */
    private function inductionCheck(int $companyId, object $worker, ?int $siteId, Carbon $at): array
    {
        /*
         * The roster's own dates count only when there is a date they were inducted. An expiry
         * with no induction date behind it is a half-filled import, not evidence — and treating
         * it as evidence would admit somebody on the strength of a field a spreadsheet happened
         * to populate.
         */
        if ($worker->inducted_on !== null && $worker->induction_expires !== null) {
            $expires = Carbon::parse($worker->induction_expires);

            return $expires->lt($at)
                ? $this->failed('INDUCTION', ReasonCode::INDUCTION_EXPIRED,
                    sprintf('%s\'s site induction expired on %s.',
                        $worker->name, $expires->format('j M Y')),
                    ['expires_on' => $expires->toDateString()])
                : $this->passed('INDUCTION', sprintf('Inducted until %s.', $expires->format('j M Y')),
                    ['expires_on' => $expires->toDateString()]);
        }

        $read = $this->safely(fn () => DB::table('sheq_induction_completions as c')
                ->join('sheq_inductions as i', 'i.id', '=', 'c.induction_id')
                ->where('c.company_id', $companyId)
                ->where('c.subject_type', 'CONTRACTOR_WORKER')
                ->where('c.subject_id', $worker->id)
                ->where('c.passed', true)
                ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('i.site_id')
                    ->orWhere('i.site_id', $siteId)))
                ->orderByDesc('c.completed_at')
                ->first(['c.expires_on', 'c.completed_at']));

        if (! $read['ok']) {
            return $this->unverifiable('INDUCTION', 'Induction records could not be read.',
                $read['error']);
        }

        $completion = $read['value'];

        if ($completion === null) {
            /*
             * Refused, with no escape hatch for a host who has published no induction.
             *
             * The employee path has that escape hatch and needs it: adopting this module must not
             * lock a workforce out of its own site because nobody has authored an induction yet.
             * A contractor worker is a different case. Their induction is evidence the CONTRACTOR
             * supplies — that is what the date on the roster row is for, filled from their own
             * paperwork — so neither source holding anything is a gap in the contractor's filing,
             * not in the host's configuration. Waving it through would mean the one piece of
             * evidence a contractor is always asked for is the one nobody has to produce.
             */
            return $this->failed('INDUCTION', ReasonCode::INDUCTION_REQUIRED,
                sprintf('%s has no current site induction on record.', $worker->name));
        }

        if ($completion->expires_on !== null && Carbon::parse($completion->expires_on)->lt($at)) {
            return $this->failed('INDUCTION', ReasonCode::INDUCTION_EXPIRED,
                sprintf('%s\'s site induction expired on %s.', $worker->name,
                    Carbon::parse($completion->expires_on)->format('j M Y')),
                ['expires_on' => $completion->expires_on]);
        }

        return $this->passed('INDUCTION', sprintf('Inducted on %s.',
            Carbon::parse($completion->completed_at)->format('j M Y')));
    }

    /**
     * A certificate of fitness.
     *
     * Absence and expiry are separated because they are different conversations and only one of
     * them is the contractor's fault yet: never supplied is a filing gap, and lapsed is a person
     * who was cleared and now is not. Absence refuses only where the work asks for a medical —
     * `MEDICAL_REQUIRED` is activity-specific in the catalogue for that reason. A general
     * labourer with no medical on file is not non-compliant; one entering a confined space is.
     */
    private function medicalCheck(int $companyId, object $worker, ?string $activityCode,
        Carbon $at): array
    {
        $read = $this->safely(fn () => DB::table('sheq_contractor_worker_credentials')
            ->where('company_id', $companyId)->where('worker_id', $worker->id)
            ->where('kind', 'MEDICAL')->whereNull('deleted_at')
            ->get(['title', 'status', 'expires_on']));

        if (! $read['ok']) {
            return $this->unverifiable('MEDICAL', 'Medical records could not be read.',
                $read['error']);
        }

        $credentials = $read['value'];

        $accepted = $credentials->filter(fn ($c) => strtoupper((string) $c->status)
            === ContractorDocumentEngine::ACCEPTED);

        if ($accepted->isEmpty()) {
            $required = $this->activityRequires($companyId, $activityCode, 'requires_medical');

            if (! $required) {
                return $this->skipped('MEDICAL', $credentials->isEmpty()
                    // "Nothing on record" rather than "no medical required": the row is shown
                    // either way, and a guard reading it needs to know this system was not told
                    // anything, not that it decided nothing was needed.
                    ? 'Nothing on record.'
                    : 'A medical has been supplied and not yet accepted.');
            }

            return $this->failed('MEDICAL', ReasonCode::MEDICAL_REQUIRED, sprintf(
                '%s holds no accepted certificate of fitness, which this work requires.',
                $worker->name));
        }

        $live = $accepted->filter(fn ($c) => $c->expires_on === null
            || Carbon::parse($c->expires_on)->gte($at));

        if ($live->isNotEmpty()) {
            $next = $live->sortBy('expires_on')->first();

            return $this->passed('MEDICAL', $next->expires_on === null
                ? 'Certificate of fitness on file.'
                : sprintf('Fit until %s.', Carbon::parse($next->expires_on)->format('j M Y')),
                ['expires_on' => $next->expires_on]);
        }

        $lapsed = $accepted->sortByDesc('expires_on')->first();

        /*
         * The date as stored, not prettied. A guard reads this off a phone and may have to type
         * it into a message or repeat it over a radio; 2026-03-14 cannot be misheard as a
         * different year the way "14 Mar" can.
         */
        return $this->failed('MEDICAL', ReasonCode::MEDICAL_EXPIRED, sprintf(
            '%s: %s expired on %s.', $worker->name, $lapsed->title ?: 'certificate of fitness',
            $lapsed->expires_on),
            ['expires_on' => $lapsed->expires_on]);
    }

    /**
     * The tickets this work takes.
     *
     * Asked of the activity catalogue, downwards — the direction is the whole of CON-15. Asking
     * the certificates on file what they authorise can only report what somebody has; it can
     * never report what they are missing, so a worker holding nothing at all came out eligible.
     */
    private function competencyCheck(int $companyId, object $worker, ?string $activityCode,
        Carbon $at): array
    {
        if ($activityCode === null || trim($activityCode) === '') {
            return $this->skipped('COMPETENCY',
                'No work was named, so no particular ticket was required.');
        }

        /*
         * First, a ticket they HOLD for this work that has run out.
         *
         * Asked of the credential rather than of the catalogue, and that is not a duplicate of
         * the check below. The catalogue answers "what does this work require", which it can only
         * do for work somebody has catalogued. A credential answers "my rigging ticket, which
         * says it covers lifting, expired last month" — and that is true whether or not LIFTING
         * has ever been entered into the catalogue. Most hosts catalogue a handful of activities
         * and their contractors file certificates naming dozens, so dropping this would mean an
         * expired ticket silently stopped mattering for every activity nobody had got round to
         * listing.
         */
        $read = $this->safely(fn () => DB::table('sheq_contractor_worker_credentials')
            ->where('company_id', $companyId)->where('worker_id', $worker->id)
            ->where('kind', 'COMPETENCY')->whereNull('deleted_at')
            ->where('status', ContractorDocumentEngine::ACCEPTED)
            ->whereNotNull('expires_on')->where('expires_on', '<', $at->toDateString())
            ->get(['code', 'title', 'activities', 'expires_on'])
            ->filter(fn ($c) => $this->credentialCovers($c, $activityCode)));

        if (! $read['ok']) {
            return $this->unverifiable('COMPETENCY', 'Competency records could not be read.',
                $read['error']);
        }

        $lapsed = $read['value'];

        if ($lapsed->isNotEmpty()) {
            return $this->failedWith('COMPETENCY', $lapsed->map(fn ($c) => [
                'code' => ReasonCode::COMPETENCY_EXPIRED,
                'detail' => sprintf('%s: %s expired on %s.', $worker->name,
                    $c->title ?: $c->code, $c->expires_on),
                'credential' => $c->code,
                'expires_on' => $c->expires_on,
            ])->values()->all());
        }

        $read = $this->safely(fn () => $this->activities->eligibility($companyId,
            (int) $worker->id, $activityCode, $at));

        if (! $read['ok']) {
            return $this->unverifiable('COMPETENCY', 'Competency records could not be read.',
                $read['error']);
        }

        $eligible = $read['value'];

        if (! $eligible['known']) {
            /*
             * An activity nobody has catalogued is reported, never refused. A reader at a boom
             * sends whatever string it was configured with years ago, and shutting a site over a
             * typo in a turnstile is not a safety control.
             */
            return $this->skipped('COMPETENCY', $eligible['note']);
        }

        if ($eligible['missing'] === []) {
            return $this->passed('COMPETENCY', $eligible['note'] !== ''
                ? $eligible['note']
                : sprintf('%s holds every ticket %s requires.', $worker->name,
                    $eligible['activity']));
        }

        $gaps = [];

        foreach ($eligible['missing'] as $gap) {
            $gaps[] = [
                'code' => match ($gap['reason']) {
                    'EXPIRED' => ReasonCode::COMPETENCY_EXPIRED,
                    'NOT_ACCEPTED' => ReasonCode::COMPETENCY_NOT_ACCEPTED,
                    default => ReasonCode::COMPETENCY_MISSING,
                },
                'detail' => sprintf('%s: %s is required for %s and is not %s.',
                    $worker->name,
                    $gap['title'] ?? ucfirst(strtolower(str_replace('_', ' ', $gap['code']))),
                    $eligible['activity'], $gap['reason'] === 'EXPIRED' ? 'in date' : 'held'),
                'credential' => $gap['code'],
                'expires_on' => $gap['expires_on'] ?? null,
            ];
        }

        return $this->failedWith('COMPETENCY', $gaps);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // The work itself: what the activity demands before anybody may start (CON-26).
    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function workScopeChecks(int $companyId, object $contractor, ?int $siteId,
        ?string $activityCode, Carbon $at): array
    {
        $activity = $activityCode === null ? null : $this->activities->find($companyId, $activityCode);

        if ($activity === null) {
            $note = $activityCode === null
                ? 'No work was named.'
                : 'This work is not in the activity catalogue.';

            return array_map(fn (string $key) => $this->skipped($key, $note),
                ['RISK_ASSESSMENT', 'METHOD_STATEMENT', 'PERMIT', 'PPE', 'EQUIPMENT_CERTIFICATION']);
        }

        return [
            $this->riskAssessmentCheck($companyId, $activity, $siteId, $at),
            $this->documentBackedCheck($companyId, $contractor, $activity, 'requires_method_statement',
                'METHOD_STATEMENT', ReasonCode::METHOD_STATEMENT_REQUIRED, 'METHOD_STATEMENT',
                'a method statement', $at),
            $this->permitCheck($companyId, $activity, $siteId, $at),
            $this->ppeCheck($companyId, $activity),
            $this->documentBackedCheck($companyId, $contractor, $activity,
                'requires_equipment_certification', 'EQUIPMENT_CERTIFICATION',
                ReasonCode::EQUIPMENT_CERTIFICATION_REQUIRED, 'EQUIPMENT_CERTIFICATE',
                'an equipment certificate', $at),
        ];
    }

    private function riskAssessmentCheck(int $companyId, object $activity, ?int $siteId,
        Carbon $at): array
    {
        if (! ($activity->requires_risk_assessment ?? false)) {
            return $this->skipped('RISK_ASSESSMENT',
                sprintf('%s does not require a risk assessment.', $activity->name));
        }

        $read = $this->safely(fn () => DB::table('sheq_risk_assessments')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('status', 'APPROVED')
                /*
                 * A company-wide assessment counts for every site. Requiring one per site would
                 * refuse work at a site covered by a generic assessment that genuinely applies —
                 * and the most common real arrangement is one baseline plus site addenda.
                 */
                ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')
                    ->orWhere('site_id', $siteId)))
                ->where(fn ($q) => $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $at->toDateString()))
                ->orderByDesc('effective_from')
                ->first(['uuid', 'reference', 'title', 'review_due']));

        if (! $read['ok']) {
            return $this->unverifiable('RISK_ASSESSMENT', 'Risk assessments could not be read.',
                $read['error']);
        }

        $live = $read['value'];

        if ($live === null) {
            return $this->failed('RISK_ASSESSMENT', ReasonCode::RISK_ASSESSMENT_REQUIRED, sprintf(
                '%s requires an approved risk assessment and none is in force.', $activity->name));
        }

        // Past its review date is reported, not refused. An assessment overdue for review is
        // still the assessment the work is being done under, and refusing on it would stop work
        // over an administrative date while the controls themselves are unchanged.
        if ($live->review_due !== null && Carbon::parse($live->review_due)->lt($at)) {
            return $this->passed('RISK_ASSESSMENT', sprintf(
                '%s is in force, and was due for review on %s.', $live->reference,
                Carbon::parse($live->review_due)->format('j M Y')),
                ['reference' => $live->reference, 'overdue_review' => true]);
        }

        return $this->passed('RISK_ASSESSMENT',
            sprintf('%s is in force.', $live->reference),
            ['reference' => $live->reference]);
    }

    /**
     * A requirement satisfied by a document on the contractor's file.
     *
     * Method statements and equipment certificates are both this shape: the activity says it
     * needs one, and the evidence is a current document of a given type. One method rather than
     * two near-identical ones, because the next requirement of this shape should not need a
     * third.
     */
    private function documentBackedCheck(int $companyId, object $contractor, object $activity,
        string $flag, string $key, string $code, string $documentType, string $noun,
        Carbon $at): array
    {
        if (! ($activity->{$flag} ?? false)) {
            return $this->skipped($key, sprintf('%s does not require %s.', $activity->name, $noun));
        }

        $read = $this->safely(fn () => DB::table('sheq_contractor_documents')
                ->where('company_id', $companyId)->where('contractor_id', $contractor->id)
                ->where('document_type', $documentType)
                ->whereNull('deleted_at')->whereNull('superseded_at')
                ->where(fn ($q) => $q->whereNull('status')
                    ->orWhere('status', ContractorDocumentEngine::ACCEPTED))
                ->orderByDesc('expires_on')
                ->first(['title', 'expires_on']));

        if (! $read['ok']) {
            return $this->unverifiable($key, 'The document register could not be read.',
                $read['error']);
        }

        $document = $read['value'];

        if ($document === null) {
            return $this->failed($key, $code,
                sprintf('%s requires %s and none is on file for %s.',
                    $activity->name, $noun, $contractor->name));
        }

        if ($document->expires_on !== null && Carbon::parse($document->expires_on)->lt($at)) {
            return $this->failed($key, $code, sprintf('%s: %s expired on %s.',
                $contractor->name, $document->title ?: $noun,
                Carbon::parse($document->expires_on)->format('j M Y')),
                ['expires_on' => $document->expires_on]);
        }

        return $this->passed($key, sprintf('%s on file.', $document->title ?: ucfirst($noun)));
    }

    /**
     * An open permit for this work.
     *
     * Checked at the grain the data supports, and no further. `sheq_permit_workers` identifies
     * people by HR person or by name, so it cannot reliably say whether THIS contractor worker is
     * named on the permit — and a check that pretended to would refuse a crew whose permit is
     * open and correct because the names were typed differently. So: is a permit for this work
     * open at this site, at this moment.
     */
    private function permitCheck(int $companyId, object $activity, ?int $siteId, Carbon $at): array
    {
        if (! $activity->requires_permit) {
            return $this->skipped('PERMIT',
                sprintf('%s does not require a permit to work.', $activity->name));
        }

        $read = $this->safely(fn () => DB::table('sheq_permits')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('status', 'ACTIVE')
                ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('site_id')
                    ->orWhere('site_id', $siteId)))
                ->whereRaw('UPPER(permit_type) = ?', [strtoupper((string) $activity->code)])
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at))
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $at))
                ->first(['uuid', 'reference', 'valid_to']));

        if (! $read['ok']) {
            return $this->unverifiable('PERMIT', 'Permits could not be read.', $read['error']);
        }

        $permit = $read['value'];

        if ($permit === null) {
            return $this->failed('PERMIT', ReasonCode::PERMIT_REQUIRED, sprintf(
                '%s requires a permit to work and none is open.', $activity->name));
        }

        return $this->passed('PERMIT', sprintf('Permit %s is open.', $permit->reference),
            ['reference' => $permit->reference, 'valid_to' => $permit->valid_to]);
    }

    /**
     * The PPE this work requires.
     *
     * Reported, never refused, and that is a deliberate limit rather than an omission. This
     * system knows what PPE the work calls for; it does not know what the person is wearing. A
     * refusal would be a guess dressed as a control, and the guess would be wrong every time
     * somebody was correctly kitted out. The list is given to the guard, who can see them.
     */
    private function ppeCheck(int $companyId, object $activity): array
    {
        $required = $this->codes($activity->required_ppe ?? null);

        if ($required === []) {
            return $this->skipped('PPE', sprintf('%s specifies no PPE.', $activity->name));
        }

        return $this->passed('PPE', sprintf('%s requires: %s. Check on sight.',
            $activity->name, implode(', ', array_map(
                fn (string $c) => strtolower(str_replace('_', ' ', $c)), $required))),
            ['required_ppe' => $required]);
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────
    // Assembling the answer.
    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The outcome, and the explanation that goes with it.
     *
     * Precedence: a failure outranks an unverifiable check. A host that chose DENIED for
     * unverifiable evidence and has one failing check as well should see the failure, because the
     * failure is the thing they can act on.
     *
     * @param  list<array<string,mixed>>  $checks
     * @return array<string,mixed>
     */
    private function decision(int $companyId, array $checks, Carbon $at, ?object $worker,
        ?object $contractor, ?int $siteId, ?string $activityCode): array
    {
        /*
         * Severity is resolved HERE, once, rather than by each check as it finds a problem.
         *
         * Two reasons. The rules are one database read, and a decision runs thirteen checks. And
         * a check that decided its own severity would be a second place the configuration is
         * interpreted — which is how a rule ends up switched off on one screen and honoured on
         * another.
         */
        $rules = $this->rules->rulesFor($companyId);
        $checks = array_map(fn (array $check) => $this->resolveSeverity($check, $rules), $checks);

        $findings = [];

        foreach ($checks as $check) {
            foreach ($check['findings'] as $finding) {
                $findings[] = $finding + ['check' => $check['key']];
            }
        }

        $blocking = array_values(array_filter($findings,
            fn (array $f) => ($f['severity'] ?? 'BLOCKING') === 'BLOCKING'));

        $unverified = array_values(array_filter($checks,
            fn (array $c) => $c['state'] === self::UNVERIFIED));

        $outcome = match (true) {
            $blocking !== [] => self::DENIED,
            $unverified !== [] => $this->unverifiedOutcome($companyId),
            default => self::ALLOWED,
        };

        return [
            'outcome' => $outcome,
            // True for ALLOWED alone. REVIEW_REQUIRED and UNKNOWN are both "we could not say yes",
            // and a caller that reads this one key must never be handed a yes for either.
            'allowed' => $outcome === self::ALLOWED,
            'codes' => array_values(array_unique(array_column($blocking, 'code'))),
            'reasons' => array_values(array_filter(array_column($blocking, 'detail'))),
            'findings' => $findings,
            'advisories' => array_values(array_filter($findings,
                fn (array $f) => ($f['severity'] ?? 'BLOCKING') === 'ADVISORY')),
            'checks' => $checks,
            'unverified' => array_column($unverified, 'key'),
            'subject' => [
                'worker_id' => $worker?->id === null ? null : (int) $worker->id,
                'worker_uuid' => $worker->uuid ?? null,
                'worker_name' => $worker->name ?? null,
                'contractor_id' => $contractor?->id === null ? null : (int) $contractor->id,
                'contractor_uuid' => $contractor->uuid ?? null,
                'contractor_name' => $contractor->name ?? null,
            ],
            'question' => [
                'site_id' => $siteId,
                'activity' => $activityCode === null ? null : strtoupper(trim($activityCode)),
                // Echoed so a stored decision records what was asked, not only what was answered.
                // An audit of "were they eligible when they entered" needs the question back.
                'at' => $at->toDateTimeString(),
            ],
        ];
    }

    /**
     * Apply the host's rules to one check's findings.
     *
     * A check whose every finding turns out to be advisory is not a failing check. It reports,
     * the screen shows it, and the gate opens — which is what switching a rule off means. The
     * alternative reading, "stop checking", is never what anybody intends by it: a host who
     * decided not to refuse for an expired medical still needs to know whose medical expired.
     *
     * @param  array<string,mixed>  $check
     * @param  array<string,bool>  $rules
     * @return array<string,mixed>
     */
    private function resolveSeverity(array $check, array $rules): array
    {
        if ($check['findings'] === []) {
            return $check;
        }

        $check['findings'] = array_map(function (array $finding) use ($rules) {
            if (isset($finding['severity'])) {
                return $finding;
            }

            $rule = self::GOVERNING_RULE[$finding['code']] ?? null;

            return $finding + [
                // No governing rule means no switch: it refuses, full stop.
                'severity' => $rule === null || ($rules[$rule] ?? false) ? 'BLOCKING' : 'ADVISORY',
                'rule' => $rule,
            ];
        }, $check['findings']);

        $blocking = array_filter($check['findings'],
            fn (array $f) => $f['severity'] === 'BLOCKING');

        if ($blocking === [] && $check['state'] === self::FAIL) {
            $check['state'] = self::PASS;
        }

        return $check;
    }

    /** What this tenant wants done when a mandatory check cannot be run (COM-3). */
    public function unverifiedOutcome(int $companyId): string
    {
        $stored = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('compliance_unverified_outcome');

        return in_array((string) $stored, self::UNVERIFIED_OUTCOMES, true)
            ? (string) $stored
            : self::DEFAULT_UNVERIFIED_OUTCOME;
    }

    // ── Check constructors. One shape, so the screens can render any of them. ──────────────────

    private function passed(string $key, string $note, array $evidence = []): array
    {
        return ['key' => $key, 'state' => self::PASS, 'note' => $note,
            'evidence' => $evidence, 'findings' => []];
    }

    private function skipped(string $key, string $note): array
    {
        return ['key' => $key, 'state' => self::NOT_APPLICABLE, 'note' => $note,
            'evidence' => [], 'findings' => []];
    }

    /**
     * A failing check.
     *
     * Deliberately does NOT set a severity. `resolveSeverity` decides that from the host's rules
     * once the whole decision is assembled, so there is exactly one place the configuration is
     * read — see the note there.
     */
    private function failed(string $key, string $code, string $detail, array $evidence = []): array
    {
        return ['key' => $key, 'state' => self::FAIL, 'note' => $detail, 'evidence' => $evidence,
            'findings' => [['code' => $code, 'subject' => ReasonCode::subjectOf($code),
                'detail' => $detail] + $evidence]];
    }

    /** @param  list<array<string,mixed>>  $gaps */
    private function failedWith(string $key, array $gaps): array
    {
        return [
            'key' => $key, 'state' => self::FAIL,
            'note' => implode(' ', array_column($gaps, 'detail')),
            'evidence' => [],
            'findings' => array_map(fn (array $gap) => $gap + [
                'subject' => ReasonCode::subjectOf($gap['code']),
            ], $gaps),
        ];
    }

    /**
     * A check that could not be run (COM-3).
     *
     * The exception message is kept, because the alternative is a host staring at
     * "could not be verified" with no way to find out why. It is not shown to a guard at a boom
     * — the screens render `note` — but it is in the record, which is where somebody debugging
     * at 06:00 will look.
     */
    private function unverifiable(string $key, string $note, ?Throwable $e = null): array
    {
        return [
            'key' => $key, 'state' => self::UNVERIFIED, 'note' => $note,
            'evidence' => $e === null ? [] : ['error' => $e->getMessage()],
            'findings' => [[
                'code' => ReasonCode::EVIDENCE_UNAVAILABLE,
                'subject' => 'SYSTEM',
                // Advisory, so it does not deny by itself: COM-3 says the OUTCOME is the host's
                // configuration, and a blocking finding here would force DENIED on everybody.
                'severity' => 'ADVISORY',
                'detail' => $note,
            ]],
        ];
    }

    /**
     * Collapse a check's findings into one check result.
     *
     * @param  list<array<string,mixed>>  $coded
     */
    private function fromFindings(string $key, array $coded, string $subject): array
    {
        $mine = array_values(array_filter($coded, fn (array $f) => $f['subject'] === $subject));

        if ($mine === []) {
            return $this->passed($key, self::CHECKS[$key]);
        }

        $blocking = array_filter($mine, fn (array $f) => $f['severity'] === 'BLOCKING');

        return [
            'key' => $key,
            // Advisory-only is a pass for the gate and still reported on the screen: the host
            // switched the rule off, which means "record it, do not refuse for it".
            'state' => $blocking === [] ? self::PASS : self::FAIL,
            'note' => implode(' ', array_column($mine, 'detail')),
            'evidence' => [],
            'findings' => array_map(fn (array $f) => $f + ['check' => $key], $mine),
        ];
    }

    /**
     * Run one check's database read so that a failure does not take the caller down with it.
     *
     * A plain try/catch is not enough on Postgres, and this is the kind of thing that only shows
     * up when you build the test that proves the fail-safe works. Inside a transaction, a
     * statement that errors puts the whole transaction into an aborted state: every subsequent
     * statement is refused with "current transaction is aborted" until somebody rolls back. So
     * catching the exception and carrying on means the next twelve checks all fail too, the
     * rollback at the end fails, and a decision engine built to be resilient takes out whatever
     * was writing when it was called.
     *
     * A savepoint is the only recovery Postgres offers. Rolling back to one is permitted even in
     * an aborted transaction, which is precisely what makes it the exit.
     *
     * Outside a transaction — a gate read, a scanned pass, the ordinary case — there is nothing
     * to protect, so no savepoint is taken and this costs one function call.
     *
     * @return array{ok:bool, value:mixed, error:?Throwable}
     */
    private function safely(callable $read): array
    {
        $nested = DB::transactionLevel() > 0;

        if ($nested) {
            DB::statement('SAVEPOINT sheq_compliance_check');
        }

        try {
            $value = $read();

            if ($nested) {
                DB::statement('RELEASE SAVEPOINT sheq_compliance_check');
            }

            return ['ok' => true, 'value' => $value, 'error' => null];
        } catch (Throwable $e) {
            if ($nested) {
                DB::statement('ROLLBACK TO SAVEPOINT sheq_compliance_check');
            }

            return ['ok' => false, 'value' => null, 'error' => $e];
        }
    }

    /**
     * Whether a credential speaks to a particular piece of work.
     *
     * A credential naming no activities covers everything — which is the right reading of a
     * first-aid certificate or a general induction, and the reading the module has always used.
     * Narrowing it would turn every unlabelled certificate into evidence of nothing.
     */
    private function credentialCovers(object $credential, string $activity): bool
    {
        $activities = json_decode((string) ($credential->activities ?? ''), true);

        if (! is_array($activities) || $activities === []) {
            return true;
        }

        return in_array(strtoupper(trim($activity)),
            array_map(fn ($a) => strtoupper((string) $a), $activities), true);
    }

    // ── Lookups ───────────────────────────────────────────────────────────────────────────────

    private function worker(int $companyId, int $workerId): ?object
    {
        if (! Schema::hasTable('sheq_contractor_workers')) {
            return null;
        }

        return DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('id', $workerId)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'name', 'contractor_id', 'is_active', 'induction_expires',
                'inducted_on', 'trade', 'site_id']);
    }

    private function contractorRow(int $companyId, int $contractorId): ?object
    {
        return DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('id', $contractorId)->whereNull('deleted_at')
            ->first(['id', 'uuid', 'name', 'access_status', 'is_active', 'site_id']);
    }

    /** Whether the named activity sets a given requirement flag. */
    private function activityRequires(int $companyId, ?string $activityCode, string $flag): bool
    {
        if ($activityCode === null) {
            return false;
        }

        $activity = $this->activities->find($companyId, $activityCode);

        return (bool) ($activity->{$flag} ?? false);
    }

    /** @return list<string> */
    private function codes(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($c) => strtoupper(trim((string) $c)), $decoded)));
    }
}
