<?php

namespace App\Modules\Operations\Sheq\Access;

use App\Modules\Operations\Sheq\Events\EventPublisher;
use App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService;
use App\Modules\Operations\Sheq\Compliance\ReasonCode;
use App\Modules\Operations\Sheq\Services\ContractorNotifier;
use App\Modules\Operations\Sheq\Services\MedicalSurveillanceService;
use App\Modules\Operations\Sheq\Services\SiteAttendanceService;
use App\Modules\Operations\Sheq\Services\TrainingTrackingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Decides whether a person may come through the gate, and says why not.
 *
 * This is the piece that turns SHEQ from a reporting system into a control. Everything else in the
 * module already knows whether somebody's induction is valid, their medical current, their
 * contractor approved — and none of it stops them walking in. A compliance figure that nothing acts
 * on is a number in a report; the boom is where it becomes real.
 *
 * Three properties matter more than the checks themselves:
 *
 * **Every refusal names what is wrong and when it expired.** "Access denied" starts an argument at
 * the boom with a queue behind it. "Site induction expired 12 March 2026" ends the argument and
 * tells the person what to fix. The guard reads this off a screen and cannot be expected to
 * interpret a code.
 *
 * **Every decision is recorded, including the ones that let people through.** The refusals are what
 * tell a safety manager the induction backlog is real; the approvals are what answers "who was on
 * site when it happened".
 *
 * **Monitor mode is the default.** Turning on enforcement at a live gate before the data is clean
 * strands a shift outside in the dark, and the response to that is always to disable the system
 * permanently. A site runs in monitor mode, sees who *would* have been refused, fixes the backlog,
 * then enforces.
 */
class AccessDecisionService
{
    public function __construct(
        private readonly MedicalSurveillanceService $medical,
        private readonly TrainingTrackingService $training,
    ) {}

    /**
     * May this person enter?
     *
     * `$identifier` is whatever the reader presents — an employee number, an ID number, a badge
     * code or a biometric reference. Matching is deliberately broad because gate hardware is
     * inconsistent about which one it sends, and a person refused because the turnstile sent a
     * payroll number instead of an ID number is refused for no safety reason at all.
     *
     * @return array{allowed:bool, outcome:string, codes:list<string>, reasons:list<string>,
     *               subject_type:?string, subject_id:?int, person_name:?string,
     *               warnings:list<string>}
     */
    public function decide(int $companyId, string $identifier, ?int $siteId = null,
        ?string $activity = null, ?Carbon $at = null): array
    {
        $at ??= Carbon::now();
        $subject = $this->identify($companyId, $identifier);

        if ($subject === null) {
            return [
                'allowed' => false,
                'outcome' => ComplianceDecisionService::DENIED,
                'codes' => [ReasonCode::WORKER_UNKNOWN],
                // Not "unknown card". The person at the boom needs to know what to do next.
                'reasons' => ['This identifier is not registered on site. Report to reception to '
                    .'be signed in as a visitor.'],
                'subject_type' => null, 'subject_id' => null, 'person_name' => null,
                'warnings' => [],
            ];
        }

        $reasons = [];
        $warnings = [];
        $codes = [];
        $outcome = null;

        match ($subject['type']) {
            'EMPLOYEE' => $this->checkEmployee($companyId, $subject, $siteId, $reasons, $warnings),
            'CONTRACTOR_WORKER' => $this->checkContractorWorker($companyId, $subject, $siteId,
                $activity, $at, $reasons, $warnings, $codes, $outcome),
            'VISITOR' => $this->checkVisitor($companyId, $subject, $reasons),
            default => null,
        };

        /*
         * Employees and visitors are still decided here, and honestly so: neither has a
         * contractor, a safety file or a site approval, and routing them through a service built
         * around those would mean thirteen checks of which ten said "not applicable".
         *
         * They get the outcome vocabulary all the same, so one caller can read `outcome` for
         * everybody rather than branching on who walked up to the boom.
         */
        $outcome ??= $reasons === []
            ? ComplianceDecisionService::ALLOWED
            : ComplianceDecisionService::DENIED;

        return [
            'allowed' => $outcome === ComplianceDecisionService::ALLOWED,
            'outcome' => $outcome,
            // Machine-readable, for everything that counts, charts, routes or translates a
            // refusal (COM-2). The sentences above are for the person at the boom.
            'codes' => $codes,
            'reasons' => $reasons,
            'warnings' => $warnings,
            'subject_type' => $subject['type'],
            'subject_id' => $subject['id'],
            'person_name' => $subject['name'],
            // Echoed so the event log records what was asked, not only what was answered: an
            // audit of "were they eligible when they entered" needs the question.
            'activity' => $activity,
            'evaluated_at' => $at->toDateTimeString(),
        ];
    }

    /**
     * Decide and record, applying the connection's enforcement setting.
     *
     * The returned `allowed` is what the hardware acts on. In monitor mode it is always true, and
     * the refusal that *would* have happened is still written to the log — which is the whole point
     * of monitor mode.
     */
    public function decideAndRecord(int $companyId, string $identifier, array $context = []): array
    {
        $siteId = $context['site_id'] ?? null;
        $activity = $context['activity'] ?? null;
        $at = isset($context['occurred_at']) ? Carbon::parse($context['occurred_at']) : Carbon::now();

        $decision = $this->decide($companyId, $identifier, $siteId, $activity, $at);

        $monitorOnly = ! ($context['enforce'] ?? false);

        /*
         * Two different facts, kept apart (CON-16).
         *
         * The event records the VERDICT, because that is what "would enforcement have refused
         * anybody" counts and what the impact report a site manager reads before flipping the
         * switch is built from. The register follows what the BOOM DID: in monitor mode a
         * refusal still opens it, so the person is inside, and a roll-call built on the verdict
         * would leave them off the evacuation list — the one circumstance where being wrong
         * costs somebody.
         */
        $openGate = $decision['allowed'] || $monitorOnly;

        $written = app(SiteAttendanceService::class)->record($companyId, [
            'integration_id' => $context['integration_id'] ?? null,
            'site_id' => $siteId,
            'subject_type' => $decision['subject_type'] ?? 'UNKNOWN',
            'subject_id' => $decision['subject_id'],
            // The employer, so "which contractors had people on site that day" stops being a
            // join nobody writes.
            'contractor_id' => $this->employerOf($companyId, $decision),
            'person_name' => $decision['person_name'],
            'contractor_name' => $this->employerName($companyId, $decision),
            'identifier' => $identifier,
            'direction' => $context['direction'] ?? 'ENTRY',
            // A reader that identified itself is a card read; anything else came through
            // the API. Not guessed beyond that: an invented method is worse than none.
            'method' => $context['method'] ?? (empty($context['integration_id']) ? 'API' : 'CARD'),
            'activity' => $activity,
            // The verdict, which is what an enforcement-impact report counts.
            'allowed' => $decision['allowed'],
            // And what the boom actually did, which is what the roll-call follows.
            'admitted' => $openGate,
            'reasons' => array_merge($decision['reasons'], $decision['warnings']),
            'was_monitor_only' => $monitorOnly && ! $decision['allowed'],
            'device_ref' => $context['device_ref'] ?? null,
            'occurred_at' => $at,
        ]);

        /*
         * Tell the employer their person was turned away (CON-19).
         *
         * At the gate, because that is when it happened and nobody else is going to say so: a
         * worker refused at 06:00 tells their supervisor, who tells the contractor's office
         * some time that morning, with whatever version of the reason survived two retellings.
         *
         * Only a genuine refusal, and only in enforcing mode — monitor mode exists to find out
         * who WOULD be refused, and sending a contractor a message about a gate that opened
         * anyway would teach them the message means nothing.
         *
         * Deduped on the worker and the reason, so a card tapped three times is one message
         * and a different reason tomorrow is a new one.
         */
        if (! $decision['allowed'] && ! $monitorOnly
            && ($decision['subject_type'] ?? null) === 'CONTRACTOR_WORKER') {
            $this->announceWorkerRefusal($companyId, $decision);
        }

        return $decision + [
            // What the turnstile is told. A refusal in monitor mode still opens the boom.
            'enforced' => $decision['allowed'] || ! $monitorOnly,
            'open_gate' => $openGate,
            'on_site' => $written['opened'] || (! $written['closed'] && $written['attendance_uuid'] !== null),
        ];
    }

    /**
     * Queue the message telling a contractor one of their people was refused (CON-19).
     *
     * Named, with the reasons. "One of your workers was turned away" sends a contractor to
     * check all forty; "T. Mokoena was refused: medical expired on 3 March" is something they
     * can act on before the next shift.
     */
    private function announceWorkerRefusal(int $companyId, array $decision): void
    {
        $contractorId = $this->employerOf($companyId, $decision);

        if ($contractorId === null) {
            return;
        }

        $contractor = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('id', $contractorId)
            ->first(['id', 'name', 'contact_email', 'contact_phone']);

        if ($contractor === null) {
            return;
        }

        app(ContractorNotifier::class)->workerBlocked($companyId, $contractor,
            (string) ($decision['person_name'] ?? 'A worker'),
            (int) $decision['subject_id'], $decision['reasons']);

        /*
         * The same fact as an event (INT-5).
         *
         * A different event from `contractor.blocked`, and acting on the wrong one of the two
         * turns away a whole crew or none of it: this contractor may still be on site, and only
         * this one person may not.
         *
         * Subscription-gated for the same reason as the gate events: a refusal happens whenever
         * somebody walks up to a boom with a lapsed certificate, which on a large site is several
         * times a day.
         */
        app(EventPublisher::class)->publishIfSubscribed(
            $companyId, 'sheq.contractor.worker_blocked', 'CONTRACTOR_WORKER',
            (int) $decision['subject_id'], [
                'contractor_id' => (int) $contractor->id,
                'contractor_name' => $contractor->name,
                'worker_id' => (int) $decision['subject_id'],
                'worker_name' => $decision['person_name'] ?? null,
                'reasons' => array_values($decision['reasons'] ?? []),
            ],
        );
    }

    /**
     * The contractor a worker belongs to, for the event and the register.
     *
     * `identify()` already carries it in `ref` for a contractor worker — it is how the employer
     * check runs — so this costs nothing and stops the log needing a join to answer the question
     * every incident report asks.
     */
    private function employerOf(int $companyId, array $decision): ?int
    {
        if (($decision['subject_type'] ?? null) !== 'CONTRACTOR_WORKER'
            || ($decision['subject_id'] ?? null) === null) {
            return null;
        }

        $id = DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('id', $decision['subject_id'])
            ->value('contractor_id');

        return $id === null ? null : (int) $id;
    }

    /** Copied onto the stay so a roll-call still names the employer after a roster is tidied. */
    private function employerName(int $companyId, array $decision): ?string
    {
        $id = $this->employerOf($companyId, $decision);

        return $id === null ? null : DB::table('sheq_contractors')
            ->where('company_id', $companyId)->where('id', $id)->value('name');
    }

    /**
     * Find the person behind an identifier.
     *
     * @return array{type:string, id:int, name:string, ref:?string}|null
     */
    private function identify(int $companyId, string $identifier): ?array
    {
        $identifier = trim($identifier);

        // A live visitor badge is checked first: it is the most specific, shortest-lived
        // identifier, and a visitor may also appear in a contractor list from a previous job.
        $visit = DB::table('sheq_visits')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('badge_code', $identifier)
            ->whereIn('status', ['INDUCTED', 'ON_SITE'])
            ->first(['id', 'visitor_name', 'status']);

        if ($visit !== null) {
            return ['type' => 'VISITOR', 'id' => (int) $visit->id,
                'name' => $visit->visitor_name, 'ref' => $visit->status];
        }

        if (Schema::hasTable('sheq_contractor_workers')) {
            // Matched on a hash: the register deliberately never holds a contractor worker's ID
            // number in plaintext, so the gate hashes what the reader presented and compares.
            // Lookup works; a database read still yields nobody's identity number.
            $worker = DB::table('sheq_contractor_workers')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('is_active', true)
                ->where('id_number_hash', self::hashIdentifier($identifier))
                ->first(['id', 'name', 'contractor_id', 'induction_expires']);

            if ($worker !== null) {
                return ['type' => 'CONTRACTOR_WORKER', 'id' => (int) $worker->id,
                    'name' => $worker->name, 'ref' => (string) $worker->contractor_id,
                    'induction_expires' => $worker->induction_expires];
            }
        }

        $person = DB::table('hr_people')
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('id_number', $identifier)
                ->orWhere('employee_number', $identifier))
            ->first(['id', 'first_name', 'surname', 'user_id']);

        if ($person !== null) {
            return ['type' => 'EMPLOYEE', 'id' => (int) $person->id,
                'name' => trim($person->first_name.' '.$person->surname),
                'ref' => $person->user_id === null ? null : (string) $person->user_id];
        }

        return null;
    }

    /**
     * The lookup hash for an identity number.
     *
     * Public and static because the importer, the gate and any future enrolment path must agree on
     * it exactly — a second definition that differs by a trim or a case would silently match
     * nobody, and present as "the card does not work".
     */
    public static function hashIdentifier(string $identifier): string
    {
        return hash('sha256', strtolower(trim($identifier)));
    }

    /** Employees: medical fitness, mandatory training, and a valid site induction. */
    private function checkEmployee(int $companyId, array $subject, ?int $siteId,
        array &$reasons, array &$warnings): void
    {
        $fitness = $this->medical->isClearedForWork($companyId, $subject['id']);

        if (! $fitness['cleared']) {
            foreach ($fitness['reasons'] as $reason) {
                $reasons[] = "Medical: {$reason}";
            }
        }

        if ($subject['ref'] !== null) {
            $trained = $this->training->isTrainedForWork($companyId, (int) $subject['ref']);

            // Training gaps warn rather than refuse for employees. A person who is medically unfit
            // must not be on site at all; one whose refresher lapsed last week needs chasing, and
            // locking them out of their own workplace over it turns the safety system into the
            // reason production stopped.
            foreach ($trained['reasons'] as $reason) {
                $warnings[] = "Training: {$reason}";
            }
        }

        $this->checkInduction($companyId, 'EMPLOYEE', $subject, $siteId, $reasons);
    }

    /**
     * Contractor workers: asked of the central compliance engine (COM-1).
     *
     * This method used to make the decision itself — contractor standing, site approval, activity
     * competency, induction — and so did the QR pass verification, and so did the contractor
     * register's status column. Three implementations of one question, written at different times
     * by people solving the screen in front of them, and they could disagree: refuse a worker at
     * the boom, show them compliant in the register, and print them a pass that scanned green.
     *
     * Now there is one. What stays here is the translation into what the gate needs: a list of
     * sentences a guard can read aloud, split into the ones that refuse and the ones that only
     * warn. The codes come back too, for everything downstream that has to count or chart or
     * route them.
     */
    private function checkContractorWorker(int $companyId, array $subject, ?int $siteId,
        ?string $activity, Carbon $at, array &$reasons, array &$warnings,
        array &$codes, ?string &$outcome): void
    {
        $decision = app(ComplianceDecisionService::class)
            ->forWorker($companyId, (int) $subject['id'], $siteId, $activity, $at);

        foreach ($decision['findings'] as $finding) {
            $line = trim((string) ($finding['detail'] ?? ''));

            if ($line === '') {
                continue;
            }

            if (($finding['severity'] ?? 'BLOCKING') === 'BLOCKING') {
                $reasons[] = $line;

                continue;
            }

            $warnings[] = $line;
        }

        $codes = $decision['codes'];

        /*
         * The engine's outcome is carried through rather than recomputed from `reasons` (COM-3).
         *
         * A decision where nothing failed but something could not be checked is REVIEW_REQUIRED
         * or UNKNOWN, and both of those have an empty reason list. Rebuilding the verdict from
         * the reasons here would read that as a clean pass — which is exactly the silent grant
         * COM-3 exists to prevent.
         */
        $outcome = $decision['outcome'];
    }

    /** Visitors: already approved and inducted by the time they hold a badge. */
    private function checkVisitor(int $companyId, array $subject, array &$reasons): void
    {
        if ($subject['ref'] === 'ON_SITE') {
            // Already inside. Not a refusal — an exit, or a second read at an internal reader.
            return;
        }

        $visit = DB::table('sheq_visits')->where('company_id', $companyId)
            ->where('id', $subject['id'])->first(['expected_to', 'induction_completed_at']);

        if ($visit !== null && $visit->induction_completed_at === null) {
            $reasons[] = 'The site induction has not been completed.';
        }

        if ($visit !== null && $visit->expected_to !== null
            && Carbon::parse($visit->expected_to)->addHours(12)->isPast()) {
            $reasons[] = 'This visit was expected to end on '
                .Carbon::parse($visit->expected_to)->format('j M Y').'. Book a new visit.';
        }
    }

    /** A valid, unexpired induction for the audience and — where site-specific — the site. */
    private function checkInduction(int $companyId, string $audience, array $subject,
        ?int $siteId, array &$reasons): void
    {
        $completion = DB::table('sheq_induction_completions as c')
            ->join('sheq_inductions as i', 'i.id', '=', 'c.induction_id')
            ->where('c.company_id', $companyId)
            ->where('c.subject_type', $subject['type'])
            ->where('c.subject_id', $subject['id'])
            ->where('c.passed', true)
            ->where('i.audience', $audience)
            ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('i.site_id')
                ->orWhere('i.site_id', $siteId)))
            ->orderByDesc('c.completed_at')
            ->first(['c.expires_on', 'c.completed_at']);

        if ($completion === null) {
            // Only refuses where an induction actually exists to complete. A company that has not
            // authored one yet must not have its whole workforce locked out by that omission.
            $exists = DB::table('sheq_inductions')->where('company_id', $companyId)
                ->where('audience', $audience)->where('is_active', true)
                ->whereNull('deleted_at')->exists();

            if ($exists) {
                $reasons[] = 'No site induction on record. Complete the induction before entry.';
            }

            return;
        }

        if ($completion->expires_on !== null && Carbon::parse($completion->expires_on)->isPast()) {
            $reasons[] = 'Site induction expired on '
                .Carbon::parse($completion->expires_on)->format('j M Y').'.';
        }
    }

    /**
     * What would happen if enforcement were switched on today.
     *
     * The report a site manager reads before flipping the switch, and the reason nobody has to find
     * out by stranding a shift.
     *
     * @return array{would_refuse:int, total:int, by_reason:array<string,int>}
     */
    public function enforcementImpact(int $companyId, ?int $siteId = null, int $days = 30): array
    {
        $events = DB::table('sheq_access_events')
            ->where('company_id', $companyId)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->where('occurred_at', '>=', now()->subDays($days))
            ->get(['allowed', 'reasons']);

        $byReason = [];

        foreach ($events->where('allowed', false) as $event) {
            foreach (json_decode((string) $event->reasons, true) ?: [] as $reason) {
                // Grouped on the leading clause, so "expired on 3 March" and "expired on 9 April"
                // count as one problem rather than two hundred.
                $key = Str::before($reason, ' on ');
                $byReason[$key] = ($byReason[$key] ?? 0) + 1;
            }
        }

        arsort($byReason);

        return [
            'would_refuse' => $events->where('allowed', false)->count(),
            'total' => $events->count(),
            'by_reason' => $byReason,
        ];
    }
}
