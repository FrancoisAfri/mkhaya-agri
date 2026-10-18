<?php

namespace App\Modules\Operations\Sheq\Ai;

use App\Modules\Operations\Sheq\Compliance\ComplianceDecisionService;
use App\Modules\Operations\Sheq\Compliance\ReasonCode;
use App\Modules\Operations\Sheq\Services\SiteRiskScoreService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The six questions §15 names, answered from the records rather than from a model (AI-1 to AI-6).
 *
 * This is deliberately NOT an LLM path, and the reason is AI-2's own wording: "Response must use
 * the actual compliance engine and evidence." A language model asked "why is ABC Construction
 * blocked" will produce a fluent, well-structured, plausible answer whether or not ABC Construction
 * exists — and a safety practitioner who acts on an invented reason has been actively misled by
 * the system that was supposed to tell them the truth.
 *
 * All six are questions with exact answers. Routing them through a model is strictly worse on every
 * axis that matters here: it can hallucinate a contractor name, it costs tokens, it is slower, and
 * it cannot cite the row it came from. So each one is a query, each answer carries the records it
 * was built from, and the model is never consulted.
 *
 * `SheqAgents::chat()` stays where it is for genuinely open questions. This sits in front of it:
 * where a question matches one of these shapes it is answered from the database, and where it does
 * not, the chat agent gets it. A practitioner cannot tell the difference except that these answers
 * are instant and always correct.
 *
 * Matching is on intent, not on exact phrasing. People do not type the requirement's example
 * sentences — they type "who's blocked", "blocked contractors", "show me blocked suppliers" — and
 * a router that only recognised the documented wording would be a feature nobody could find.
 */
class SheqAnswerService
{
    /**
     * The six intents, with the words that signal each one.
     *
     * Ordered: the first match wins, and the more specific intents come first. "Why is ABC
     * blocked" contains "blocked" and must not be answered as "list the blocked contractors",
     * which is a different question with a different answer.
     *
     * @var array<string, array{needs_all?:list<list<string>>, needs_any?:list<string>,
     *     requirement:string, label:string}>
     */
    public const INTENTS = [
        'WHY_BLOCKED' => [
            // Both a "why" word and a "blocked" word: this is the specific-subject question.
            'needs_all' => [['why', 'reason', 'reasons'], ['block', 'blocked', 'refus', 'denied']],
            'requirement' => 'AI-2',
            'label' => 'Why a contractor is blocked',
        ],
        'ACTIVITY_INELIGIBLE' => [
            'needs_all' => [['who', 'which', 'workers', 'people'],
                ['cannot', 'can not', "can't", 'not allowed', 'ineligible', 'unable']],
            'requirement' => 'AI-3',
            'label' => 'Who may not do a particular activity today',
        ],
        'BLOCKED_CONTRACTORS' => [
            'needs_any' => ['blocked contractor', 'contractors blocked', 'which contractors',
                'who is blocked', "who's blocked", 'blocked suppliers', 'blocked'],
            'requirement' => 'AI-1',
            'label' => 'Contractors currently blocked',
        ],
        'RECURRING_HAZARDS' => [
            'needs_any' => ['recurring hazard', 'recurring hazards', 'repeat hazard',
                'same hazard', 'keeps happening', 'recurring'],
            'requirement' => 'AI-4',
            'label' => 'Recurring hazards',
        ],
        'OVERDUE_ACTIONS' => [
            'needs_any' => ['overdue action', 'overdue capa', 'actions overdue', 'late action',
                'overdue corrective', 'overdue'],
            'requirement' => 'AI-5',
            'label' => 'Overdue corrective actions',
        ],
        'MONTHLY_REPORT' => [
            'needs_any' => ['monthly report', 'monthly sheq report', 'prepare my report',
                'month end report', 'management report', 'sheq report'],
            'requirement' => 'AI-6',
            'label' => 'A draft monthly SHEQ report',
        ],
    ];

    public function __construct(
        private readonly ComplianceDecisionService $compliance,
        private readonly SiteRiskScoreService $siteRisk,
    ) {}

    /**
     * Answer a question from the records, or say this is not one of ours.
     *
     * Returns null where no intent matches, which is the caller's signal to fall through to the
     * chat agent. Null rather than a guess: a router that half-matched would answer the wrong
     * question confidently, which is the failure this whole class exists to avoid.
     *
     * @return array{intent:string, requirement:string, answer:string,
     *     citations:list<array<string,mixed>>, data:array<string,mixed>, source:string}|null
     */
    public function answer(int $companyId, string $question, ?int $siteId = null): ?array
    {
        $intent = $this->intentOf($question);

        if ($intent === null) {
            return null;
        }

        $result = match ($intent) {
            'WHY_BLOCKED' => $this->whyBlocked($companyId, $question, $siteId),
            'ACTIVITY_INELIGIBLE' => $this->whoCannotWork($companyId, $question, $siteId),
            'BLOCKED_CONTRACTORS' => $this->blockedContractors($companyId, $siteId),
            'RECURRING_HAZARDS' => $this->recurringHazards($companyId, $siteId),
            'OVERDUE_ACTIONS' => $this->overdueActions($companyId, $siteId),
            'MONTHLY_REPORT' => $this->monthlyReport($companyId, $siteId),
        };

        if ($result === null) {
            return null;
        }

        return array_merge($result, [
            'intent' => $intent,
            'requirement' => self::INTENTS[$intent]['requirement'],
            // Said on every answer. A practitioner is entitled to know whether they are reading
            // the register or a model's reading of it, and the whole value of these six is that
            // they are the former.
            'source' => 'RECORDS',
        ]);
    }

    /** Which of the six, if any. */
    public function intentOf(string $question): ?string
    {
        $text = Str::lower(trim($question));

        if ($text === '') {
            return null;
        }

        foreach (self::INTENTS as $intent => $spec) {
            if (isset($spec['needs_all'])) {
                $all = true;

                foreach ($spec['needs_all'] as $group) {
                    if (! $this->containsAny($text, $group)) {
                        $all = false;

                        break;
                    }
                }

                if ($all) {
                    return $intent;
                }

                continue;
            }

            if ($this->containsAny($text, $spec['needs_any'] ?? [])) {
                return $intent;
            }
        }

        return null;
    }

    // ── AI-1 ──────────────────────────────────────────────────────────────────────────────────

    private function blockedContractors(int $companyId, ?int $siteId): array
    {
        $rows = DB::table('sheq_contractors as c')
            ->where('c.company_id', $companyId)
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            ->whereIn('c.access_status', ['BLOCKED', 'SUSPENDED'])
            ->when($siteId !== null, fn ($q) => $q->whereExists(fn ($e) => $e
                ->from('sheq_contractor_site_approvals as a')
                ->whereColumn('a.contractor_id', 'c.id')
                ->where('a.site_id', $siteId)->where('a.company_id', $companyId)))
            ->orderBy('c.name')
            ->get(['c.uuid', 'c.name', 'c.access_status', 'c.updated_at']);

        if ($rows->isEmpty()) {
            return [
                'answer' => 'No contractor is currently blocked'
                    .($siteId === null ? '.' : ' for this site.'),
                'citations' => [],
                'data' => ['count' => 0, 'contractors' => []],
            ];
        }

        $lines = $rows->map(fn ($r) => sprintf('- %s (%s)', $r->name,
            strtolower(str_replace('_', ' ', $r->access_status))))->implode("\n");

        return [
            'answer' => sprintf("%d contractor%s currently blocked:\n\n%s\n\nAsk \"why is "
                ."<name> blocked\" for the reasons behind any of them.",
                $rows->count(), $rows->count() === 1 ? ' is' : 's are', $lines),
            // Every answer carries the rows it came from, so the figure can be checked rather
            // than trusted.
            'citations' => $rows->map(fn ($r) => [
                'type' => 'CONTRACTOR', 'uuid' => $r->uuid, 'label' => $r->name,
                'link' => '/sheq/contractors',
            ])->all(),
            'data' => ['count' => $rows->count(),
                'contractors' => $rows->map(fn ($r) => (array) $r)->all()],
        ];
    }

    // ── AI-2 ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Why a named contractor is blocked, from the compliance engine itself (AI-2).
     *
     * The requirement is explicit that this must use the actual engine and the actual evidence,
     * and the reason is that this is the question where a plausible invention does the most harm:
     * somebody reads "their letter of good standing expired" and goes to fetch a letter of good
     * standing, when the real reason was a lapsed medical on one worker.
     */
    private function whyBlocked(int $companyId, string $question, ?int $siteId): ?array
    {
        $contractor = $this->findContractorIn($companyId, $question);

        if ($contractor === null) {
            /*
             * Null, not a guess. Falling through to the chat agent is right here — it can ask
             * which contractor is meant — and answering about the wrong one would be worse than
             * not answering.
             */
            return null;
        }

        $decision = $this->compliance->forContractor($companyId, (int) $contractor->id, $siteId);

        /*
         * The engine's own definition of blocking: `severity`, defaulting to BLOCKING. Filtering
         * on anything else here would make this answer disagree with the compliance screen, and
         * AI-2 exists precisely so that it cannot.
         */
        $blocking = array_values(array_filter($decision['findings'] ?? [],
            fn (array $f) => ($f['severity'] ?? 'BLOCKING') === 'BLOCKING'));

        if ($decision['outcome'] === ComplianceDecisionService::ALLOWED) {
            return [
                'answer' => sprintf('%s is not blocked. The compliance engine allows them on '
                    .'site%s.', $contractor->name,
                    $siteId === null ? '' : ' at this site'),
                'citations' => [['type' => 'CONTRACTOR', 'uuid' => $contractor->uuid,
                    'label' => $contractor->name, 'link' => '/sheq/compliance']],
                'data' => ['contractor' => $contractor->name,
                    'outcome' => $decision['outcome']],
            ];
        }

        $lines = [];

        foreach ($blocking as $finding) {
            $line = '- '.($finding['detail'] ?? 'A requirement is not met.');

            // The remedy, from the same catalogue the blocking email and the refused-access
            // screen use, so all three say the same thing.
            if (! empty($finding['code'])) {
                $line .= "\n  To fix it: ".ReasonCode::remedyFor($finding['code']);
            }

            $lines[] = $line;
        }

        return [
            'answer' => sprintf("%s: %s.\n\n%s",
                $contractor->name,
                strtolower(str_replace('_', ' ', (string) $decision['outcome'])),
                $lines === [] ? 'The engine gives no specific reason, which is itself worth '
                    .'raising with your system administrator.' : implode("\n", $lines)),
            'citations' => array_merge(
                [['type' => 'CONTRACTOR', 'uuid' => $contractor->uuid,
                    'label' => $contractor->name, 'link' => '/sheq/compliance']],
                array_map(fn (array $f) => [
                    'type' => 'COMPLIANCE_FINDING',
                    'code' => $f['code'] ?? null,
                    'label' => $f['detail'] ?? '',
                    'link' => '/sheq/compliance',
                ], $blocking),
            ),
            'data' => [
                'contractor' => $contractor->name,
                'outcome' => $decision['outcome'],
                // The engine's own output, verbatim. Anybody checking this answer against the
                // compliance screen must see the same thing.
                'findings' => $blocking,
            ],
        ];
    }

    // ── AI-3 ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Who may not do a named activity today (AI-3).
     *
     * Answered per worker through the engine, because "cannot perform working-at-height work" is
     * exactly the compound question the engine was built for: the competency, the medical, the
     * induction and the activity's own requirements, together.
     */
    private function whoCannotWork(int $companyId, string $question, ?int $siteId): ?array
    {
        $activity = $this->findActivityIn($companyId, $question);

        if ($activity === null) {
            return null;
        }

        $workers = DB::table('sheq_contractor_workers as w')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'w.contractor_id')
            ->where('w.company_id', $companyId)
            ->whereNull('w.deleted_at')->where('w.is_active', true)
            ->when($siteId !== null, fn ($q) => $q->where(fn ($w) => $w
                ->where('w.site_id', $siteId)->orWhereNull('w.site_id')))
            // Bounded. Running thirteen checks across an estate of nine thousand workers to
            // answer a chat question is a request that times out and a database that notices.
            ->orderBy('w.name')->limit(300)
            ->get(['w.id', 'w.uuid', 'w.name', 'c.name as contractor_name']);

        $refused = [];

        foreach ($workers as $worker) {
            $decision = $this->compliance->forWorker($companyId, (int) $worker->id, $siteId,
                $activity->code);

            if ($decision['outcome'] === ComplianceDecisionService::ALLOWED) {
                continue;
            }

            $refused[] = [
                'uuid' => $worker->uuid,
                'name' => $worker->name,
                'contractor' => $worker->contractor_name,
                'outcome' => $decision['outcome'],
                // The engine already flattens its blocking findings to `reasons`.
                'reasons' => array_values($decision['reasons'] ?? []),
            ];
        }

        if ($refused === []) {
            return [
                'answer' => sprintf('Every active worker on record may currently do %s.',
                    strtolower($activity->name)),
                'citations' => [['type' => 'ACTIVITY', 'uuid' => $activity->uuid,
                    'label' => $activity->name, 'link' => '/sheq/activities']],
                'data' => ['activity' => $activity->name, 'count' => 0, 'workers' => []],
            ];
        }

        $lines = array_map(fn (array $w) => sprintf('- %s%s: %s', $w['name'],
            $w['contractor'] ? ' ('.$w['contractor'].')' : '',
            $w['reasons'] === [] ? strtolower($w['outcome'])
                : implode('; ', array_slice($w['reasons'], 0, 2))), $refused);

        return [
            'answer' => sprintf("%d worker%s cannot do %s today:\n\n%s%s",
                count($refused), count($refused) === 1 ? '' : 's',
                strtolower($activity->name), implode("\n", array_slice($lines, 0, 25)),
                count($lines) > 25 ? "\n\n(the first 25 of ".count($lines).')' : ''),
            'citations' => array_merge(
                [['type' => 'ACTIVITY', 'uuid' => $activity->uuid, 'label' => $activity->name,
                    'link' => '/sheq/activities']],
                array_map(fn (array $w) => ['type' => 'WORKER', 'uuid' => $w['uuid'],
                    'label' => $w['name'], 'link' => '/sheq/compliance'], $refused),
            ),
            'data' => ['activity' => $activity->name, 'count' => count($refused),
                'workers' => $refused,
                'checked' => $workers->count(),
                // Said, because an answer about 300 of 9,000 workers that looks like an answer
                // about all of them is the kind of thing somebody plans a shift on.
                'truncated' => $workers->count() >= 300],
        ];
    }

    // ── AI-4 ──────────────────────────────────────────────────────────────────────────────────

    private function recurringHazards(int $companyId, ?int $siteId): array
    {
        $from = Carbon::today()->subMonths(6);

        $rows = DB::table('sheq_hazards')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->where('reported_at', '>=', $from)
            ->whereNotNull('hazard_category')
            ->groupBy('hazard_category')
            ->havingRaw('count(*) > 1')
            ->orderByDesc(DB::raw('count(*)'))
            ->limit(15)
            ->get(['hazard_category', DB::raw('count(*) as total'),
                DB::raw('count(*) filter (where status <> \'CLOSED\') as still_open')]);

        if ($rows->isEmpty()) {
            return [
                'answer' => 'No hazard category has been reported more than once in the last six '
                    .'months. That is either a quiet six months or a reporting problem — a site '
                    .'with no repeat hazards at all is unusual.',
                'citations' => [],
                'data' => ['from' => $from->toDateString(), 'categories' => []],
            ];
        }

        $lines = $rows->map(fn ($r) => sprintf('- %s: %d reported, %d still open',
            strtolower(str_replace('_', ' ', $r->hazard_category)),
            $r->total, $r->still_open))->implode("\n");

        return [
            'answer' => sprintf("Reported more than once in the last six months:\n\n%s\n\nThe "
                ."ones with reports still open are where the control is not holding.", $lines),
            'citations' => $rows->map(fn ($r) => [
                'type' => 'HAZARD_CATEGORY', 'label' => $r->hazard_category,
                'count' => (int) $r->total, 'link' => '/sheq/hazards',
            ])->all(),
            'data' => ['from' => $from->toDateString(),
                'categories' => $rows->map(fn ($r) => (array) $r)->all()],
        ];
    }

    // ── AI-5 ──────────────────────────────────────────────────────────────────────────────────

    private function overdueActions(int $companyId, ?int $siteId): array
    {
        $today = Carbon::today();

        $rows = DB::table('sheq_actions as a')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->where('a.company_id', $companyId)->whereNull('a.deleted_at')
            ->whereNotIn('a.status', ['VERIFIED', 'CLOSED', 'CANCELLED'])
            ->whereNotNull('a.due_date')->whereDate('a.due_date', '<', $today)
            ->when($siteId !== null, fn ($q) => $q->where('a.site_id', $siteId))
            ->orderBy('a.due_date')
            ->limit(50)
            ->get(['a.uuid', 'a.reference', 'a.title', 'a.due_date', 'a.status',
                's.name as site_name']);

        if ($rows->isEmpty()) {
            return [
                'answer' => 'No corrective action is overdue.',
                'citations' => [],
                'data' => ['count' => 0, 'actions' => []],
            ];
        }

        $lines = $rows->map(fn ($r) => sprintf('- %s %s — due %s (%d days ago)%s',
            $r->reference, $r->title, $r->due_date,
            (int) $today->diffInDays(Carbon::parse($r->due_date)),
            $r->site_name ? ', '.$r->site_name : ''))->implode("\n");

        return [
            'answer' => sprintf("%d corrective action%s overdue:\n\n%s",
                $rows->count(), $rows->count() === 1 ? ' is' : 's are', $lines),
            'citations' => $rows->map(fn ($r) => [
                'type' => 'ACTION', 'uuid' => $r->uuid, 'label' => $r->reference,
                'link' => '/sheq/actions',
            ])->all(),
            'data' => ['count' => $rows->count(),
                'actions' => $rows->map(fn ($r) => (array) $r)->all()],
        ];
    }

    // ── AI-6 ──────────────────────────────────────────────────────────────────────────────────

    /**
     * A draft monthly report, assembled from the projections (AI-6).
     *
     * A draft, and it says so. The requirement asks for one with citations, and the citations are
     * the point: a month-end report is read by people who were not there, and every figure in it
     * has to be traceable to the screen it came from or the first question in the meeting
     * cannot be answered.
     */
    private function monthlyReport(int $companyId, ?int $siteId): array
    {
        $period = Carbon::now()->format('Y-m');

        $snapshot = DB::table('sheq_kpi_snapshots')
            ->where('company_id', $companyId)
            ->where('period_year', (int) Carbon::now()->year)
            ->where('period_month', (int) Carbon::now()->month)
            ->when($siteId === null, fn ($q) => $q->whereNull('site_id'),
                fn ($q) => $q->where('site_id', $siteId))
            ->first(['metrics', 'computed_at', 'exposure_estimated']);

        $metrics = $snapshot === null ? [] : (json_decode((string) $snapshot->metrics, true) ?: []);
        $lagging = $metrics['lagging'] ?? [];
        $leading = $metrics['leading'] ?? [];

        $board = $this->siteRisk->board($companyId, $period, 5);
        $overdue = $this->overdueActions($companyId, $siteId)['data']['count'] ?? 0;
        $blocked = $this->blockedContractors($companyId, $siteId)['data']['count'] ?? 0;

        $sections = [
            sprintf('# Draft SHEQ report — %s', $period),
            '',
            'This is a draft assembled from the registers. Every figure below links to the screen '
            .'it came from; check them before this goes to a meeting.',
            '',
            '## What happened',
            sprintf('- Incidents: %s', $lagging['total_incidents'] ?? 'no projection for this period'),
            sprintf('- Fatalities: %s', $lagging['fatalities'] ?? '—'),
            sprintf('- Lost-time injuries: %s', $lagging['lost_time_injuries'] ?? '—'),
            sprintf('- Days lost: %s', $lagging['days_lost'] ?? '—'),
            '',
            '## What predicts the next one',
            sprintf('- Hazards reported: %s', $leading['hazards_reported'] ?? '—'),
            sprintf('- Unsafe observations: %s', $leading['unsafe_observations'] ?? '—'),
            sprintf('- Inspection pass rate: %s%%', $leading['inspection_pass_rate'] ?? '—'),
            sprintf('- Contractor compliance: %s%%', $leading['contractor_compliance_percent'] ?? '—'),
            sprintf('- Competencies lapsing in 90 days that stop work: %s',
                $leading['competencies_expiring_blocking_90d'] ?? '—'),
            '',
            '## Outstanding',
            sprintf('- Overdue corrective actions: %d', $overdue),
            sprintf('- Contractors blocked: %d', $blocked),
        ];

        if ($board !== []) {
            $sections[] = '';
            $sections[] = '## Sites by risk';

            foreach ($board as $site) {
                $sections[] = sprintf('- %s: %s%s', $site['site_name'],
                    $site['risk_score'] === null ? 'no score'
                        : $site['risk_score'].'/100 '.$site['risk_band'],
                    $site['breakdown'] === [] ? ''
                        : ' — mostly '.strtolower((string) $site['breakdown'][0]['label'])
                            .' ('.$site['breakdown'][0]['share'].'%)');
            }
        }

        if ($snapshot === null) {
            $sections[] = '';
            $sections[] = '> The KPI projection has not run for this period, so the injury '
                .'figures above are blank rather than zero. That is a missing projection, not a '
                .'month without incidents.';
        } elseif ($snapshot->exposure_estimated) {
            $sections[] = '';
            $sections[] = '> Hours worked for this period are estimated, so any rate derived '
                .'from them is an estimate. Say so if this report is presented.';
        }

        return [
            'answer' => implode("\n", $sections),
            'citations' => array_merge(
                [['type' => 'KPI_SNAPSHOT', 'label' => 'KPI projection '.$period,
                    'link' => '/sheq/command-centre',
                    'computed_at' => $snapshot->computed_at ?? null]],
                [['type' => 'ACTIONS', 'label' => $overdue.' overdue actions',
                    'link' => '/sheq/actions']],
                [['type' => 'CONTRACTORS', 'label' => $blocked.' blocked contractors',
                    'link' => '/sheq/contractors']],
                array_map(fn (array $s) => ['type' => 'SITE_RISK',
                    'label' => $s['site_name'].' '.($s['risk_score'] ?? 'no score'),
                    'link' => '/sheq/command-centre'], $board),
            ),
            'data' => ['period' => $period, 'is_draft' => true,
                'projection_missing' => $snapshot === null,
                'exposure_estimated' => (bool) ($snapshot->exposure_estimated ?? false)],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The contractor somebody named in a question.
     *
     * Matched on the longest name that appears in the text, so "ABC Construction" wins over "ABC"
     * where a tenant has both — the shorter match would answer about the wrong company and read
     * entirely convincingly.
     */
    private function findContractorIn(int $companyId, string $question): ?object
    {
        $text = Str::lower($question);

        $candidates = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->orderByRaw('length(name) desc')
            ->get(['id', 'uuid', 'name']);

        foreach ($candidates as $contractor) {
            $name = Str::lower(trim((string) $contractor->name));

            if ($name !== '' && str_contains($text, $name)) {
                return $contractor;
            }
        }

        return null;
    }

    /** The activity somebody named, by name or by code. */
    private function findActivityIn(int $companyId, string $question): ?object
    {
        $text = Str::lower($question);

        $candidates = DB::table('sheq_activities')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderByRaw('length(name) desc')
            ->get(['id', 'uuid', 'code', 'name']);

        foreach ($candidates as $activity) {
            foreach ([Str::lower((string) $activity->name),
                Str::lower(str_replace('_', ' ', (string) $activity->code))] as $needle) {
                if ($needle !== '' && str_contains($text, $needle)) {
                    return $activity;
                }
            }
        }

        return null;
    }

    /** @param  list<string>  $needles */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
