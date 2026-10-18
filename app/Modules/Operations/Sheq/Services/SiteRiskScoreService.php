<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The site safety risk score (§16).
 *
 * §16 asks for a configurable score with eleven named inputs, and for it to be explainable. The
 * explainability is the requirement, not the number: a score nobody can argue with is a score
 * nobody acts on, because the first thing a site manager does with a bad figure is ask which part
 * of it is theirs. So every computation stores its own breakdown — the count, the weight, the
 * points and the share of the total for each signal — and that stored breakdown is what the screen
 * reads back. Recomputing an explanation on read would explain the present rather than the month
 * the figure is labelled with, and the two would drift apart in silence.
 *
 * **This score runs the opposite way to the contractor score.** `ContractorPerformanceService`
 * gives 0-100 where ninety is good. This gives 0-100 where eighty-two is dangerous, because that
 * is what §16 asks for — "Site Safety Risk: 82/100 — HIGH". Two scores in one module pointing in
 * opposite directions is a trap however they are named, so everything here says RISK: the column
 * is `risk_score`, the bands are `LOW`/`MODERATE`/`HIGH`/`SEVERE`, and the service never returns a
 * bare `score` key that could be mistaken for the other one.
 *
 * Three decisions about the arithmetic that are worth stating, because each of them is a way the
 * figure could have been quietly useless:
 *
 * **It is a rate, not a count.** A site with forty incidents and nine thousand people on it is
 * safer than one with four and twelve, and a score built on raw counts says the opposite. So the
 * counts are normalised against the people who were actually there.
 *
 * **Near misses and hazards carry low weight on purpose.** They are reports, and penalising a
 * site for reporting is how reporting stops — after which the score improves while the site gets
 * more dangerous. This is the single most dangerous property a safety score can have and it is
 * easy to arrive at by treating every input as a negative with equal sincerity.
 *
 * **A site with no data scores null, not zero.** Zero is the best possible score and would put a
 * site that has never reported anything at the top of the board, which is exactly backwards: a
 * site reporting nothing is either empty or not reporting.
 */
class SiteRiskScoreService
{
    /**
     * The eleven inputs §16 names, plus the two the first one splits into.
     *
     * `weight` is points per event per hundred people per month. `direction` is always UP — every
     * signal here makes a site more dangerous, never less — and is stated anyway so that adding a
     * mitigating signal later is an explicit change rather than a sign error.
     */
    public const SIGNALS = [
        'incidents' => [
            'weight' => 14, 'label' => 'Incidents',
            'note' => 'Incidents that actually hurt somebody or damaged something, excluding near '
                .'misses. The heaviest single signal, because it is the only one that is not a '
                .'prediction.',
        ],
        'high_potential_incidents' => [
            'weight' => 10, 'label' => 'High-potential incidents',
            'note' => 'The event that could have killed somebody and happened not to. Weighted '
                .'close to an actual injury because the difference between them is luck.',
        ],
        'near_misses' => [
            'weight' => 1, 'label' => 'Near misses reported',
            'note' => 'Weighted lightly and deliberately. A site penalised for reporting near '
                .'misses stops reporting them, and then this score improves while the site gets '
                .'more dangerous. The number is here because its ABSENCE is the warning.',
        ],
        'hazards' => [
            'weight' => 1, 'label' => 'Hazards reported',
            'note' => 'Same reasoning as near misses: a reporting signal, not a failure signal.',
        ],
        'inspection_failures' => [
            'weight' => 6, 'label' => 'Failed inspections',
            'note' => 'An inspection that did not pass is a control that was checked and found '
                .'wanting, which is different from one nobody has looked at.',
        ],
        'unsafe_observations' => [
            'weight' => 4, 'label' => 'Unsafe behaviour observed',
            'note' => 'Observations recorded as unsafe acts or conditions. Safe observations are '
                .'not counted at all — they are the denominator of a behavioural programme, not '
                .'a penalty.',
        ],
        'contractor_compliance_gaps' => [
            'weight' => 8, 'label' => 'Contractor compliance',
            'note' => 'Contractors working on this site who are blocked or non-compliant. '
                .'Heavily weighted because it is the one signal that is entirely preventable with '
                .'paperwork and entirely predictive of who gets hurt.',
        ],
        'overdue_actions' => [
            'weight' => 7, 'label' => 'Overdue CAPA',
            'note' => 'Corrective actions past their due date and not yet verified. A site that '
                .'knows what is wrong and has not fixed it.',
        ],
        'permit_violations' => [
            'weight' => 9, 'label' => 'Permit violations',
            'note' => 'Permits suspended, or issued with the competency check overridden. A '
                .'documented departure from the procedure on somebody\'s authority — not an '
                .'expired permit, which is paperwork.',
        ],
        'competency_failures' => [
            'weight' => 8, 'label' => 'Competency failures at the gate',
            'note' => 'People refused entry for a competency they did not hold. Each one is '
                .'somebody who turned up expecting to do work they are not qualified for.',
        ],
        'ncrs' => [
            'weight' => 4, 'label' => 'Non-conformances',
            'note' => 'Quality NCRs raised on this site. Quality and safety fail together more '
                .'often than either profession likes to admit — the same concrete pour that '
                .'fails a strength test is the one that was rushed.',
        ],
        'repeated_control_failures' => [
            'weight' => 12, 'label' => 'Controls that keep failing',
            'note' => 'Controls an approved assessment still counts as implemented and the '
                .'evidence says have failed repeatedly. The heaviest predictive signal there is, '
                .'because it means the risk assessment protecting people is wrong.',
        ],
    ];

    /** Bands. Every name says RISK, so an 82 is never read as a compliment. */
    public const BANDS = ['SEVERE' => 75, 'HIGH' => 50, 'MODERATE' => 25, 'LOW' => 0];

    /**
     * How many people-months one unit of weight is priced against.
     *
     * A hundred, so a weight reads as "points per event per hundred people per month" — the scale
     * a safety professional already thinks in, because frequency rates are per-hundred or
     * per-million hours. Changing this rescales every score ever computed, which is why the
     * weights are configurable and this is not.
     */
    public const EXPOSURE_BASE = 100;

    /** Below this many people on site, a rate is noise rather than a measurement. */
    public const MIN_EXPOSURE = 5;

    public function __construct(private readonly RiskIntelligenceService $risk) {}

    /** The signal keys that have a stored column. */
    public static function signalKeys(): array
    {
        return array_keys(self::SIGNALS);
    }

    /** The weights this tenant uses, with the shipped ones underneath. */
    public function weightsFor(int $companyId): array
    {
        $stored = json_decode((string) DB::table('sheq_settings')
            ->where('company_id', $companyId)->value('site_risk_weights'), true);

        $weights = [];

        foreach (self::SIGNALS as $key => $signal) {
            // Merged rather than replaced, so a stored set written before a signal existed does
            // not leave the new one undefined and silently weightless.
            $weights[$key] = isset($stored[$key]) ? max(0, min(50, (int) $stored[$key]))
                : $signal['weight'];
        }

        return $weights;
    }

    public function saveWeights(int $companyId, array $weights, ?int $actorId = null): array
    {
        $clean = [];

        foreach (self::SIGNALS as $key => $signal) {
            // Capped at fifty. A weight of nine thousand does not express strong feeling, it just
            // makes every site with one incident score 100 and the figure stops distinguishing
            // them — which is the same as not having a score.
            $clean[$key] = max(0, min(50, (int) ($weights[$key] ?? $signal['weight'])));
        }

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $companyId],
            ['site_risk_weights' => json_encode($clean), 'updated_at' => now()],
        );

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $actorId, 'actor_type' => 'USER',
            'action' => 'SITE_RISK_WEIGHTS_CHANGED', 'subject_type' => 'Settings',
            'subject_id' => $companyId, 'after' => json_encode($clean),
        ]);

        return $clean;
    }

    /**
     * Compute and store one site's score for one month.
     *
     * Returns the same shape the screen reads, so a caller that has just computed a score does
     * not have to go and fetch it again.
     */
    public function compute(int $companyId, int $siteId, ?string $period = null): array
    {
        $period ??= Carbon::now()->format('Y-m');
        [$from, $to] = $this->window($period);

        $counts = $this->counts($companyId, $siteId, $from, $to);
        $exposure = $this->exposure($companyId, $siteId, $from, $to);
        $weights = $this->weightsFor($companyId);
        $scored = $this->score($counts, $exposure['people'], $weights);

        DB::table('sheq_site_risk_scores')->updateOrInsert(
            ['company_id' => $companyId, 'site_id' => $siteId, 'period' => $period],
            $counts + [
                'uuid' => (string) Str::uuid(),
                'people_on_site' => $exposure['people'],
                'hours_worked' => $exposure['hours'],
                'risk_score' => $scored['risk_score'],
                'risk_band' => $scored['risk_band'],
                'breakdown' => json_encode($scored['breakdown']),
                'weights' => json_encode($weights),
                'computed_at' => now(),
                'updated_at' => now(),
            ],
        );

        return array_merge($scored, [
            'site_id' => $siteId, 'period' => $period, 'counts' => $counts,
            'people_on_site' => $exposure['people'], 'hours_worked' => $exposure['hours'],
        ]);
    }

    /** Every active site, for the board. */
    public function computeAll(int $companyId, ?string $period = null): int
    {
        $sites = DB::table('sheq_sites')
            ->where('company_id', $companyId)->whereNull('deleted_at')->where('is_active', true)
            ->pluck('id');

        foreach ($sites as $siteId) {
            $this->compute($companyId, (int) $siteId, $period);
        }

        return $sites->count();
    }

    /**
     * The arithmetic, and the explanation of it (§16).
     *
     * Points per signal are `count / (people / 100) * weight`, capped at 100 in total. The share
     * is each signal's points as a percentage of the total — which is the shape §16's own example
     * asks for: "Contractor compliance — 28%".
     */
    public function score(array $counts, int $people, array $weights): array
    {
        /*
         * Null, not zero. Zero is the best possible score, so a site with nobody on it and
         * nothing reported would sit at the top of the board looking exemplary. A site reporting
         * nothing is either empty or not reporting, and both of those are answers the board has
         * to be able to show as "no data" rather than as "safe".
         */
        if ($people < self::MIN_EXPOSURE) {
            return ['risk_score' => null, 'risk_band' => null, 'breakdown' => [],
                'explained' => false,
                'why' => sprintf('Fewer than %d people were on this site in the period, so a rate '
                    .'per hundred people would be noise rather than a measurement.',
                    self::MIN_EXPOSURE),
            ];
        }

        $per = $people / self::EXPOSURE_BASE;
        $points = [];

        foreach (self::SIGNALS as $key => $signal) {
            $count = (int) ($counts[$key] ?? 0);
            $weight = (int) ($weights[$key] ?? $signal['weight']);

            $points[$key] = $count === 0 || $weight === 0 ? 0.0
                : round(($count / $per) * $weight, 2);
        }

        $raw = array_sum($points);
        $score = (int) min(100, round($raw));
        $breakdown = [];

        foreach ($points as $key => $value) {
            if ($value <= 0) {
                continue;
            }

            $breakdown[] = [
                'key' => $key,
                'label' => self::SIGNALS[$key]['label'],
                'note' => self::SIGNALS[$key]['note'],
                'count' => (int) ($counts[$key] ?? 0),
                'weight' => (int) ($weights[$key] ?? self::SIGNALS[$key]['weight']),
                'points' => $value,
                // The share of the figure this signal is responsible for, which is the number
                // §16's example shows and the only one a site manager can act on.
                'share' => $raw > 0 ? (int) round(($value / $raw) * 100) : 0,
            ];
        }

        // Largest contributor first: the explanation is read as a to-do list.
        usort($breakdown, fn ($a, $b) => $b['points'] <=> $a['points']);

        return [
            'risk_score' => $score,
            'risk_band' => $this->band($score),
            'breakdown' => $breakdown,
            'explained' => true,
            // Stated rather than implied, because the first question a bad score gets is whether
            // it is comparing like with like.
            'why' => sprintf('%d people on site; counts are expressed per %d people per month.',
                $people, self::EXPOSURE_BASE),
        ];
    }

    /** The board CMD-1 and the executive dashboard CMD-7 both read. */
    public function board(int $companyId, ?string $period = null, int $limit = 100): array
    {
        $period ??= Carbon::now()->format('Y-m');

        return DB::table('sheq_site_risk_scores as s')
            ->leftJoin('sheq_sites as t', 't.id', '=', 's.site_id')
            ->where('s.company_id', $companyId)->where('s.period', $period)
            // Worst first, and sites with no score at the bottom rather than treated as safe.
            ->orderByRaw('s.risk_score IS NULL')
            ->orderByDesc('s.risk_score')
            ->limit($limit)
            ->get(['s.uuid', 's.site_id', 's.period', 's.risk_score', 's.risk_band',
                's.people_on_site', 's.breakdown', 's.computed_at', 't.name as site_name'])
            ->map(fn ($r) => array_merge((array) $r, [
                'breakdown' => json_decode((string) $r->breakdown, true) ?: [],
            ]))->all();
    }

    /** One site's score with its stored explanation (§16). */
    public function explain(int $companyId, int $siteId, ?string $period = null): ?array
    {
        $period ??= Carbon::now()->format('Y-m');

        $row = DB::table('sheq_site_risk_scores')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->where('period', $period)->first();

        if ($row === null) {
            return null;
        }

        return array_merge((array) $row, [
            'breakdown' => json_decode((string) $row->breakdown, true) ?: [],
            // The weights AS AT the computation, not today's. A score recomputed against
            // reweighted inputs is a different score, and reading it back with the current
            // weights would make last month's arithmetic look wrong.
            'weights' => json_decode((string) $row->weights, true) ?: [],
        ]);
    }

    /** The trend, oldest first so a chart does not have to reverse it. */
    public function trend(int $companyId, int $siteId, int $months = 12): array
    {
        return DB::table('sheq_site_risk_scores')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->orderByDesc('period')->limit($months)
            ->get(['period', 'risk_score', 'risk_band', 'people_on_site'])
            ->reverse()->values()
            ->map(fn ($r) => (array) $r)->all();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The eleven counts.
     *
     * Every one filtered by site, which sounds too obvious to write down and is exactly the bug
     * that was in the contractor score — two of its counts filtered on the company and not on the
     * contractor, so every contractor carried every other contractor's failures.
     *
     * @return array<string,int>
     */
    private function counts(int $companyId, int $siteId, Carbon $from, Carbon $to): array
    {
        $incidents = fn () => DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->whereNull('deleted_at')->whereBetween('occurred_at', [$from, $to]);

        return [
            'incidents' => (int) $incidents()->where('incident_type', '!=', 'NEAR_MISS')->count(),
            'high_potential_incidents' => (int) $incidents()
                ->where('potential_severity', '>=', 4)->count(),
            'near_misses' => (int) $incidents()->where('incident_type', 'NEAR_MISS')->count(),

            'hazards' => (int) DB::table('sheq_hazards')
                ->where('company_id', $companyId)->where('site_id', $siteId)
                ->whereNull('deleted_at')->whereBetween('reported_at', [$from, $to])->count(),

            'inspection_failures' => (int) DB::table('sheq_inspections')
                ->where('company_id', $companyId)->where('site_id', $siteId)
                ->where('passed', false)->whereNull('deleted_at')
                ->whereBetween('completed_at', [$from, $to])->count(),

            // Unsafe only. A safe observation is the denominator of a behavioural programme and
            // counting it as a penalty would make a well-run programme look like a bad site.
            'unsafe_observations' => (int) DB::table('sheq_observations')
                ->where('company_id', $companyId)->where('site_id', $siteId)
                ->whereNull('deleted_at')
                // Derived from the `concerning` flag on the declared types rather than listed
                // here, so a fifth observation kind added later is counted or not counted by its
                // own declaration instead of by whether somebody remembered this query.
                ->whereIn('observation_type', array_keys(array_filter(
                    ObservationService::TYPES, fn (array $t) => $t['concerning'])))
                ->whereBetween('observed_at', [$from, $to])->count(),

            'contractor_compliance_gaps' => $this->contractorGaps($companyId, $siteId, $to),

            'overdue_actions' => (int) DB::table('sheq_actions')
                ->where('company_id', $companyId)->where('site_id', $siteId)
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['VERIFIED', 'CLOSED', 'CANCELLED'])
                ->whereNotNull('due_date')->where('due_date', '<', $to)
                ->count(),

            'permit_violations' => (int) DB::table('sheq_permits')
                ->where('company_id', $companyId)->where('site_id', $siteId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($from, $to) {
                    // Dated on when the departure happened, not on when the permit was raised —
                    // the same rule the contractor score uses, so the two figures agree.
                    $q->whereBetween('suspended_at', [$from, $to])
                        ->orWhereBetween('competency_overridden_at', [$from, $to]);
                })->count(),

            'competency_failures' => $this->competencyRefusals($companyId, $siteId, $from, $to),

            'ncrs' => (int) DB::table('sheq_ncrs')
                ->where('company_id', $companyId)->where('site_id', $siteId)
                ->whereNull('deleted_at')->whereNotIn('status', ['VOID'])
                ->whereBetween('raised_at', [$from, $to])->count(),

            'repeated_control_failures' => $this->repeatedControlFailures($companyId, $siteId, $from),
        ];
    }

    /** Contractors on this site whose standing is not clean, as at the end of the period. */
    private function contractorGaps(int $companyId, int $siteId, Carbon $asAt): int
    {
        return (int) DB::table('sheq_contractor_site_approvals as a')
            ->join('sheq_contractors as c', 'c.id', '=', 'a.contractor_id')
            ->where('a.company_id', $companyId)->where('a.site_id', $siteId)
            ->whereNull('c.deleted_at')->where('c.is_active', true)
            ->whereIn('c.access_status', ['BLOCKED', 'SUSPENDED', 'NON_COMPLIANT'])
            ->distinct('c.id')
            ->count('c.id');
    }

    /** People refused at a gate for a competency they did not hold. */
    private function competencyRefusals(int $companyId, int $siteId, Carbon $from, Carbon $to): int
    {
        return (int) DB::table('sheq_access_events')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->where('allowed', false)
            ->whereBetween('occurred_at', [$from, $to])
            // The reasons column holds the codes the decision produced. Matched on the two
            // competency ones rather than on every refusal, because a refusal for a lapsed
            // insurance certificate is a contractor-compliance problem and is already counted
            // there — charging it twice would double-weight paperwork over qualification.
            ->where(fn ($q) => $q
                ->where('reasons', 'like', '%COMPETENCY_MISSING%')
                ->orWhere('reasons', 'like', '%COMPETENCY_EXPIRED%')
                ->orWhere('reasons', 'like', '%MISSING_ACTIVITY_COMPETENCY%'))
            ->count();
    }

    /**
     * Controls an approved assessment still counts and the evidence says keep failing.
     *
     * Read through `RiskIntelligenceService` rather than re-queried here, so the two screens
     * cannot disagree about which controls are broken. A site manager comparing the risk
     * intelligence page with their own score and finding different numbers would be right to
     * stop trusting both.
     */
    private function repeatedControlFailures(int $companyId, int $siteId, Carbon $from): int
    {
        $ineffective = $this->risk->ineffectiveControls($companyId, $from);

        $controls = $ineffective['controls'] ?? [];

        return count(array_filter($controls,
            fn (array $c) => (int) ($c['site_id'] ?? 0) === $siteId
                && (int) ($c['consecutive_failures'] ?? 0) > 0));
    }

    /**
     * How many people were on this site, as exposure for the rate.
     *
     * Distinct people from the attendance register, which is the only count that reflects who was
     * actually there rather than who is on a payroll. Hours are carried alongside where somebody
     * has captured them, because an injury frequency rate needs hours and this score does not —
     * but a screen showing both should not have to go and find them separately.
     *
     * @return array{people:int, hours:?int}
     */
    private function exposure(int $companyId, int $siteId, Carbon $from, Carbon $to): array
    {
        $people = (int) DB::table('sheq_site_attendance')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->whereBetween('entered_at', [$from, $to])
            ->distinct()
            ->count(DB::raw("coalesce(subject_type, '') || ':' || coalesce(subject_id::text, person_name, '')"));

        $hours = DB::table('sheq_exposure_hours')
            ->where('company_id', $companyId)->where('site_id', $siteId)
            ->where('period_year', $from->year)->where('period_month', $from->month)
            ->whereNull('deleted_at')
            ->selectRaw('coalesce(employee_hours, 0) + coalesce(contractor_hours, 0) as total')
            ->value('total');

        return ['people' => $people, 'hours' => $hours === null ? null : (int) $hours];
    }

    private function band(?int $score): ?string
    {
        if ($score === null) {
            return null;
        }

        foreach (self::BANDS as $band => $floor) {
            if ($score >= $floor) {
                return $band;
            }
        }

        return 'LOW';
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function window(string $period): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $period.'-01 00:00:00')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }
}
