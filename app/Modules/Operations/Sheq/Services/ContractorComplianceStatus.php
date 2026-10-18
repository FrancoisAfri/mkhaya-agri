<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a contractor's standing is, worked out rather than typed (CON-9, CON-10).
 *
 * The status was a column somebody could in principle set, computed from documents alone. CON-9
 * requires it to be calculated from everything that bears on whether these people may work, and
 * CON-10 requires the conditions that refuse them to be configurable with an exact reason — because
 * "access denied" at a boom with a queue behind it starts an argument, and "working-at-height
 * competency expired on 3 March" ends one.
 *
 * Two distinctions the vocabulary turns on, and they are the reason this is not one boolean:
 *
 * **NON_COMPLIANT is not BLOCKED.** Something required is missing, and whether that refuses entry
 * is the host's decision. A mine blocks on an expired medical; an office park records it and lets
 * the consultant in. Collapsing the two means either every omission stops work or none does.
 *
 * **PENDING_APPROVAL is not COMPLIANT.** Every document can be in place while nobody has yet
 * accepted the file. Reporting that as compliant would make the whole review workflow decorative.
 *
 * Precedence runs: SUSPENDED, BLOCKED, PENDING_APPROVAL, NON_COMPLIANT, EXPIRING, COMPLIANT. An
 * explicit refusal outranks a computed one, and a computed refusal outranks anything advisory.
 *
 * The rules default to what the module already enforced before they were configurable. An upgrade
 * that changed who may come on site would be the worst possible way to ship a configuration
 * feature.
 */
class ContractorComplianceStatus
{
    public const COMPLIANT = 'COMPLIANT';

    public const EXPIRING = 'EXPIRING';

    public const NON_COMPLIANT = 'NON_COMPLIANT';

    public const BLOCKED = 'BLOCKED';

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const SUSPENDED = 'SUSPENDED';

    public const STATUSES = [
        self::COMPLIANT, self::EXPIRING, self::NON_COMPLIANT, self::BLOCKED,
        self::PENDING_APPROVAL, self::SUSPENDED,
    ];

    /**
     * The conditions CON-10 lists, and whether each refuses access by default.
     *
     * The document rules default on because they are what the module already enforced: a missing
     * or expired mandatory document has always blocked, and switching that off on upgrade would
     * admit contractors who were refused the day before.
     *
     * The worker rules default **off**. They are evaluated against credentials the contractor
     * supplies, and on the day this ships no contractor has supplied any — so defaulting them on
     * would block every contractor on the estate for want of data nobody has been asked for yet.
     * A host that wants them switches them on once their contractors have filed.
     */
    public const RULES = [
        'EXPIRED_MANDATORY_DOCUMENT' => true,
        'MISSING_MANDATORY_DOCUMENT' => true,
        'EXPIRED_COID' => true,
        'EXPIRED_INSURANCE' => true,
        'REJECTED_SAFETY_FILE' => true,
        'SUSPENDED_SAFETY_FILE' => true,
        'EXPIRED_SAFETY_FILE' => true,
        'MISSING_RISK_ASSESSMENT' => false,
        /*
         * Whether a site that has NEVER DECIDED refuses entry (CON-14). Ships off, because on the
         * day per-site approval arrives no site has decided anything and switching it on would
         * block every contractor on the estate. A host turns it on to say "nobody works at a site
         * until that site has said so".
         *
         * Note what this rule is NOT: a site that has explicitly BLOCKED a contractor refuses
         * whatever this says. See SITE_BLOCKED below.
         */
        'MISSING_SITE_APPROVAL' => false,
        'EXPIRED_COMPETENCY' => false,
        /*
         * A worker without a ticket the named activity requires (CON-15). Distinct from
         * EXPIRED_COMPETENCY, which is about a ticket they hold that has run out: the gap this
         * rule reports is a ticket that was never filed, and it only exists once a host has
         * catalogued what the work takes. Ships off, like the other worker rules, because on
         * the day it arrives nobody has filed a credential against it.
         */
        'MISSING_ACTIVITY_COMPETENCY' => false,
        'EXPIRED_MEDICAL' => false,
        'INCOMPLETE_INDUCTION' => false,

        /*
         * What the WORK requires, as against what the contractor or the person does (CON-26).
         *
         * An activity declaring that it needs a method statement is already the host's decision —
         * the flag defaults off and somebody had to tick it. These rules are the second half of
         * that decision: whether a missing one refuses the crew or is recorded against them.
         *
         * All off, and `MISSING_PERMIT` is the reason the pair is needed rather than the flag
         * alone. `requires_permit` has shipped set on nine high-risk activities since the
         * catalogue arrived, and nothing acted on it. Acting on it now, on upgrade, would turn
         * away every working-at-height crew on every site that does not raise permits in this
         * system yet — which is most of them, on the morning they upgrade.
         */
        'MISSING_MEDICAL' => false,
        'MISSING_METHOD_STATEMENT' => false,
        'MISSING_PERMIT' => false,
        'MISSING_EQUIPMENT_CERTIFICATE' => false,

        /*
         * A site that has explicitly blocked this contractor. Not switchable, and listed here
         * only so `rulesFor()` answers for it: a site bans a contractor for a reason the
         * company-wide file knows nothing about — an incident there, a hazard only there — and a
         * head-office switch must not be able to walk them back through that gate.
         */
        'SITE_BLOCKED' => true,
    ];

    /** Rules a host may change. `SITE_BLOCKED` is deliberately absent. */
    public const CONFIGURABLE_RULES = [
        'EXPIRED_MANDATORY_DOCUMENT', 'MISSING_MANDATORY_DOCUMENT', 'EXPIRED_COID',
        'EXPIRED_INSURANCE', 'REJECTED_SAFETY_FILE', 'SUSPENDED_SAFETY_FILE',
        'EXPIRED_SAFETY_FILE', 'MISSING_RISK_ASSESSMENT', 'MISSING_SITE_APPROVAL',
        'EXPIRED_COMPETENCY', 'EXPIRED_MEDICAL', 'INCOMPLETE_INDUCTION',
        'MISSING_ACTIVITY_COMPETENCY', 'MISSING_MEDICAL', 'MISSING_METHOD_STATEMENT',
        'MISSING_PERMIT', 'MISSING_EQUIPMENT_CERTIFICATE',
    ];

    /** Document types that count as proof of COID and of insurance, for those two rules. */
    private const COID_TYPES = ['COIDA_LETTER'];

    private const INSURANCE_TYPES = ['PUBLIC_LIABILITY', 'PROOF_OF_INSURANCE'];

    public function __construct(private readonly ContractorDocumentEngine $documents) {}

    /**
     * Evaluate one contractor, now.
     *
     * @param  array<string,mixed>  $file  the compliance file, so this never re-queries documents
     * @return array{status:string, blocking:list<array<string,mixed>>, advisory:list<array<string,mixed>>,
     *               safety_file_state:?string, rules:array<string,bool>}
     */
    public function evaluate(int $companyId, int $contractorId, array $file,
        ?int $siteId = null, ?string $activity = null): array
    {
        $rules = $this->rulesFor($companyId);
        $state = $this->safetyFileState($companyId, $contractorId);

        $blocking = [];
        $advisory = [];

        foreach ($this->conditions($companyId, $contractorId, $file, $state, $siteId, $activity) as $finding) {
            /*
             * Every finding is reported either way; the rule decides which list it lands in.
             *
             * A condition switched off is not hidden — it is recorded as non-compliance without
             * refusing entry, because a host who chose not to block on it still needs to know.
             * Dropping it entirely would make the switch mean "stop checking", which is not what
             * anybody intends by it.
             */
            if ($rules[$finding['rule']] ?? false) {
                $blocking[] = $finding;

                continue;
            }

            $advisory[] = $finding;
        }

        /*
         * A live override is a named person accepting the risk of an incomplete file, for a stated
         * period, on the record. It is an explicit decision and therefore outranks the computed
         * refusal — which is the same reasoning that puts a suspension above everything else, in
         * the other direction.
         *
         * The findings are not discarded. They move to advisory, because the file is still short
         * and the screen, the dashboard and the next auditor all need to say so.
         */
        if (($file['overridden'] ?? false) && $state !== SafetyFileWorkflow::SUSPENDED) {
            $advisory = array_merge($advisory, $blocking);
            $blocking = [];
        }

        return [
            'status' => $this->statusFrom($state, $blocking, $advisory, $file),
            'blocking' => $blocking,
            'advisory' => $advisory,
            'safety_file_state' => $state,
            'rules' => $rules,
        ];
    }

    /** The tenant's rules, with the shipped defaults underneath. */
    public function rulesFor(int $companyId): array
    {
        $stored = DB::table('sheq_settings')->where('company_id', $companyId)
            ->value('contractor_blocking_rules');

        $configured = json_decode((string) $stored, true);

        if (! is_array($configured)) {
            return self::RULES;
        }

        /*
         * Merged rather than replaced: a stored set written before a rule existed must not leave
         * the new rule undefined, and a rule the tenant has never seen takes its shipped default.
         *
         * A rule outside CONFIGURABLE_RULES takes its shipped value and ignores what is stored,
         * so a hand-edited or legacy settings row cannot switch off a site's explicit block.
         */
        return array_map(
            fn (string $rule) => in_array($rule, self::CONFIGURABLE_RULES, true)
                ? (bool) ($configured[$rule] ?? self::RULES[$rule])
                : self::RULES[$rule],
            array_combine(array_keys(self::RULES), array_keys(self::RULES)),
        );
    }

    /**
     * Save a tenant's rules.
     *
     * Unknown keys are dropped rather than stored: a typo'd rule name would otherwise sit in the
     * configuration for ever, looking like a switch that does nothing. So are the rules that are
     * not a host's to change — a posted `SITE_BLOCKED: false` is ignored, not saved.
     */
    public function saveRules(int $companyId, array $rules, ?int $actorId = null): array
    {
        $clean = [];

        foreach (self::CONFIGURABLE_RULES as $rule) {
            $clean[$rule] = (bool) ($rules[$rule] ?? self::RULES[$rule]);
        }

        $before = $this->rulesFor($companyId);

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $companyId],
            ['contractor_blocking_rules' => json_encode($clean), 'updated_at' => now()],
        );

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId,
            'actor_id' => $actorId,
            'actor_type' => 'USER',
            'action' => 'contractor.blocking_rules_changed',
            'subject_type' => 'SheqSettings',
            'subject_id' => 0,
            'before' => json_encode($before),
            'after' => json_encode($clean),
            'reason' => 'Which conditions refuse contractor site access.',
        ]);

        return $clean;
    }

    /**
     * Everything wrong with this contractor, each tagged with the rule that governs it.
     *
     * @return list<array{rule:string, reason:string, detail:string}>
     */
    private function conditions(int $companyId, int $contractorId, array $file, ?string $state,
        ?int $siteId, ?string $activity): array
    {
        $findings = [];

        /*
         * The document findings come from the compliance file rather than being re-derived, so the
         * gate and the file cannot disagree about which certificate is missing.
         */
        foreach ($file['blocking'] ?? [] as $entry) {
            $type = (string) ($entry['type'] ?? '');

            $rule = match (true) {
                in_array($type, self::COID_TYPES, true) => 'EXPIRED_COID',
                in_array($type, self::INSURANCE_TYPES, true) => 'EXPIRED_INSURANCE',
                $type === 'RISK_ASSESSMENT' => 'MISSING_RISK_ASSESSMENT',
                $type === 'SITE_BLOCKED' => 'SITE_BLOCKED',
                $type === 'SITE_SPECIFIC' => 'MISSING_SITE_APPROVAL',
                ($entry['reason'] ?? '') === 'EXPIRED' => 'EXPIRED_MANDATORY_DOCUMENT',
                default => 'MISSING_MANDATORY_DOCUMENT',
            };

            $findings[] = [
                'rule' => $rule,
                'reason' => (string) ($entry['reason'] ?? 'MISSING'),
                // The exact reason CON-10 demands: the document, and when it lapsed.
                'detail' => (string) ($entry['detail'] ?? $type),
            ];
        }

        if ($state === SafetyFileWorkflow::BLOCKED) {
            $findings[] = [
                'rule' => 'REJECTED_SAFETY_FILE',
                'reason' => 'SAFETY_FILE_REJECTED',
                'detail' => 'The safety file was refused and no replacement has been submitted.',
            ];
        }

        /*
         * Sent back is the host declining the file, so it belongs with a refusal rather than with
         * an approval. Without this a file returned for corrections read exactly like an approved
         * one for as long as the contractor took to resubmit, which is the whole period the
         * corrections were outstanding.
         */
        if ($state === SafetyFileWorkflow::CHANGES_REQUIRED) {
            $findings[] = [
                'rule' => 'REJECTED_SAFETY_FILE',
                'reason' => 'SAFETY_FILE_SENT_BACK',
                'detail' => 'The safety file was sent back for changes and has not been resubmitted.',
            ];
        }

        /*
         * An approval that has run out is the one state that looked compliant from the inside.
         * Nothing was missing — every document was on file and accepted — so no document rule
         * fired, and the file's own state was not consulted; the status came out COMPLIANT on a
         * file whose approval had lapsed. An approval is a statement about a file on a date, and
         * once that date passes the statement no longer holds.
         */
        if ($state === SafetyFileWorkflow::EXPIRED) {
            $findings[] = [
                'rule' => 'EXPIRED_SAFETY_FILE',
                'reason' => 'SAFETY_FILE_EXPIRED',
                'detail' => $this->approvalLapsed($companyId, $contractorId),
            ];
        }

        if ($state === SafetyFileWorkflow::SUSPENDED) {
            $findings[] = [
                'rule' => 'SUSPENDED_SAFETY_FILE',
                'reason' => 'SAFETY_FILE_SUSPENDED',
                'detail' => $this->suspensionReason($companyId, $contractorId),
            ];
        }

        foreach ($this->workerFindings($companyId, $contractorId, $siteId, $activity) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * What is wrong with the people, as opposed to the paperwork.
     *
     * Named individually. "A worker's medical has expired" sends somebody to read a list; "T.
     * Mokoena's medical expired on 3 March" is actionable at the boom, which is the whole point of
     * CON-10's exact reason.
     *
     * @return list<array{rule:string, reason:string, detail:string}>
     */
    private function workerFindings(int $companyId, int $contractorId, ?int $siteId,
        ?string $activity): array
    {
        $workers = DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->where('is_active', true)
            ->get(['id', 'name', 'inducted_on', 'induction_expires']);

        if ($workers->isEmpty()) {
            return [];
        }

        $today = Carbon::today();
        $findings = [];

        $credentials = DB::table('sheq_contractor_worker_credentials')
            ->where('company_id', $companyId)
            ->whereIn('worker_id', $workers->pluck('id'))
            ->whereNull('deleted_at')
            ->get(['worker_id', 'kind', 'title', 'expires_on', 'status', 'activities'])
            ->groupBy('worker_id');

        $inductedHere = DB::table('sheq_induction_completions as c')
            ->join('sheq_inductions as i', 'i.id', '=', 'c.induction_id')
            ->where('c.company_id', $companyId)
            ->where('c.subject_type', 'CONTRACTOR_WORKER')
            ->whereIn('c.subject_id', $workers->pluck('id'))
            ->where('c.passed', true)
            ->where(fn ($q) => $q->whereNull('c.expires_on')
                ->orWhere('c.expires_on', '>=', $today->toDateString()))
            ->pluck('c.subject_id')->map(fn ($id) => (int) $id)->all();

        foreach ($workers as $worker) {
            $held = $credentials[$worker->id] ?? collect();

            foreach (['COMPETENCY' => 'EXPIRED_COMPETENCY', 'MEDICAL' => 'EXPIRED_MEDICAL'] as $kind => $rule) {
                foreach ($held->where('kind', $kind) as $credential) {
                    // An unaccepted credential is not evidence, so it cannot be the thing that
                    // expires — it simply never counted.
                    if (strtoupper((string) $credential->status) !== ContractorDocumentEngine::ACCEPTED) {
                        continue;
                    }

                    if ($credential->expires_on === null
                        || ! Carbon::parse($credential->expires_on)->isBefore($today)) {
                        continue;
                    }

                    // Where the credential names activities and the question names one, a ticket
                    // for other work is not the ticket being asked about.
                    if ($activity !== null && ! $this->credentialCovers($credential, $activity)) {
                        continue;
                    }

                    $findings[] = [
                        'rule' => $rule,
                        'reason' => $kind === 'MEDICAL' ? 'MEDICAL_EXPIRED' : 'COMPETENCY_EXPIRED',
                        'detail' => sprintf('%s: %s expired on %s.', $worker->name,
                            $credential->title, $credential->expires_on),
                    ];
                }
            }

            /*
             * What the named activity takes, which this worker does not hold (CON-15).
             *
             * Asked of the activity catalogue rather than of the certificates on file, so a
             * worker holding nothing at all is reported by name against the ticket they are
             * missing. Only reachable when the question names an activity: "is this contractor
             * compliant" has no activity, and listing every worker's gaps for every activity in
             * the catalogue would bury the one that matters.
             */
            if ($activity !== null) {
                $eligible = app(ActivityService::class)
                    ->eligibility($companyId, (int) $worker->id, $activity);

                foreach ($eligible['missing'] as $gap) {
                    // An expired ticket is already reported above, by name and date. Reporting
                    // it twice would read as two different problems.
                    if ($gap['reason'] === 'EXPIRED') {
                        continue;
                    }

                    $findings[] = [
                        'rule' => 'MISSING_ACTIVITY_COMPETENCY',
                        'reason' => $gap['reason'],
                        'detail' => sprintf('%s: %s is required for %s and is not held.',
                            $worker->name,
                            $gap['title'] ?? ucfirst(strtolower(str_replace('_', ' ', $gap['code']))),
                            $eligible['activity'] ?? $activity),
                    ];
                }
            }

            /*
             * Induction, from either source — the register's imported dates or a completion
             * recorded here — because the access gate honours both and a status that disagreed
             * with the gate would be worse than no status.
             */
            $paperwork = $worker->inducted_on !== null
                && ($worker->induction_expires === null
                    || Carbon::parse($worker->induction_expires)->gte($today));

            if (! $paperwork && ! in_array((int) $worker->id, $inductedHere, true)) {
                $findings[] = [
                    'rule' => 'INCOMPLETE_INDUCTION',
                    'reason' => 'INDUCTION_INCOMPLETE',
                    'detail' => sprintf('%s has not completed a current site induction.',
                        $worker->name),
                ];
            }
        }

        return $findings;
    }

    private function credentialCovers(object $credential, string $activity): bool
    {
        $activities = json_decode((string) ($credential->activities ?? ''), true);

        if (! is_array($activities) || $activities === []) {
            return true;
        }

        return in_array(strtoupper($activity),
            array_map(fn ($a) => strtoupper((string) $a), $activities), true);
    }

    /** When the approval ran out, so the refusal names a date rather than a state. */
    private function approvalLapsed(int $companyId, int $contractorId): string
    {
        $until = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->orderByDesc('version')->value('valid_until');

        return $until === null
            ? 'The safety file approval has lapsed and no replacement has been submitted.'
            : sprintf('The safety file approval stood until %s and no replacement has been submitted.',
                Carbon::parse($until)->toFormattedDateString());
    }

    private function statusFrom(?string $state, array $blocking, array $advisory, array $file): string
    {
        return match (true) {
            // An explicit refusal outranks a computed one: somebody decided this, and a computed
            // status that overrode a human decision would be the wrong way round.
            $state === SafetyFileWorkflow::SUSPENDED => self::SUSPENDED,
            $blocking !== [] => self::BLOCKED,
            // Every document in place and nobody has accepted the file yet. Reporting that as
            // compliant would make the review workflow decorative.
            in_array($state, [SafetyFileWorkflow::SUBMITTED, SafetyFileWorkflow::UNDER_REVIEW], true)
                => self::PENDING_APPROVAL,
            /*
             * An overridden file reads EXPIRING, never COMPLIANT and never NON_COMPLIANT: the
             * contractor is admitted and the file is still short, and the dashboard has to keep
             * saying so for as long as the override lasts.
             */
            ($file['overridden'] ?? false) => self::EXPIRING,
            $advisory !== [] => self::NON_COMPLIANT,
            ($file['expiring'] ?? []) !== [] => self::EXPIRING,
            default => self::COMPLIANT,
        };
    }

    /**
     * The file's state, with a lapsed approval read as lapsed the moment it lapses.
     *
     * The nightly pass persists the APPROVED → EXPIRED transition, and it runs on weekday
     * mornings. On its own that leaves a window: an approval whose last day was Friday still reads
     * APPROVED to a gate on Saturday and Sunday, which on a site that works weekends is exactly
     * when nobody is in the office to notice. CON-9 says the status is calculated, so the date is
     * honoured here and the command's job is to record the transition rather than to cause it.
     */
    private function safetyFileState(int $companyId, int $contractorId): ?string
    {
        $submission = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')
            ->orderByDesc('version')->first(['state', 'valid_until']);

        if ($submission === null) {
            return null;
        }

        if ($submission->state === SafetyFileWorkflow::APPROVED
            && $submission->valid_until !== null
            && Carbon::parse($submission->valid_until)->isBefore(Carbon::today())) {
            return SafetyFileWorkflow::EXPIRED;
        }

        return $submission->state;
    }

    private function suspensionReason(int $companyId, int $contractorId): string
    {
        $reason = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->orderByDesc('version')->value('decision_reason');

        return $reason === null
            ? 'The safety file is suspended.'
            : 'The safety file is suspended: '.$reason;
    }
}
