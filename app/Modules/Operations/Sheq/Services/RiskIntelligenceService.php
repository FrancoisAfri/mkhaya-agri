<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Whether the risk picture is getting worse, and which controls are not working (RISK-1 to RISK-5).
 *
 * The HIRA engine is the most complete thing in the module — matrices, hierarchy-of-control
 * checks, approval authority by band, versioning, participation, review triggers. What it could
 * not do is notice that any of it had stopped working.
 *
 * That gap has a specific shape. A risk assessment records a judgement made on a day: these are
 * the hazards, these are the controls, this is the residual risk. Everything that happens
 * afterwards — the inspections, the incidents, the observations, the CAPAs reviewed as
 * ineffective — is evidence about that judgement, and none of it was connected back to it. So a
 * site could fail the same checklist question eleven times and the assessment would still read
 * "control implemented: yes", because somebody ticked that box in 2024 and nothing had
 * contradicted it since.
 *
 * The five signals RISK-1 names are all the same shape: something that was supposed to be under
 * control keeps happening. Each is counted separately rather than blended into a score, because
 * they call for different responses — a control that repeatedly fails is a control problem, and a
 * residual risk that went up after a reassessment is a judgement problem.
 */
class RiskIntelligenceService
{
    /**
     * The five deterioration signals RISK-1 lists, each with what it means.
     *
     * Declared so the screen can explain them and a test can assert the requirement is covered.
     * `severity` orders the board: a control that keeps failing outranks a count of observations,
     * because the first says a barrier is gone and the second says somebody noticed something.
     *
     * @var array<string, array{label:string, note:string, severity:int}>
     */
    public const SIGNALS = [
        'RESIDUAL_INCREASED' => [
            'label' => 'Residual risk went up',
            'note' => 'A reassessment scored the residual risk higher than before. Either the '
                .'controls stopped working or the first assessment was optimistic, and both are '
                .'worth somebody looking at.',
            'severity' => 1,
        ],
        'CONTROL_REPEATEDLY_FAILED' => [
            'label' => 'A control keeps failing',
            'note' => 'Verified as failed more than once. A barrier that fails repeatedly is not '
                .'a barrier — and the assessment still counts it as one.',
            'severity' => 1,
        ],
        'REPEATED_INCIDENT' => [
            'label' => 'The same incident again',
            'note' => 'More than one incident of the same type at the same site. One is an '
                .'event; the same one twice is a cause nobody has found.',
            'severity' => 2,
        ],
        'REPEATED_INSPECTION_FAILURE' => [
            'label' => 'The same inspection keeps failing',
            'note' => 'A template failing repeatedly at one site. The checklist is working; '
                .'something behind it is not.',
            'severity' => 2,
        ],
        'REPEATED_UNSAFE_OBSERVATION' => [
            'label' => 'The same unsafe behaviour again',
            'note' => 'A behaviour observed repeatedly. The leading signal of the three, and the '
                .'one that appears before anybody is hurt.',
            'severity' => 3,
        ],
    ];

    public const VERIFICATION_SOURCES = ['INSPECTION', 'INCIDENT', 'OBSERVATION', 'AUDIT',
        'CAPA_REVIEW', 'MANUAL'];

    public const RESULTS = ['EFFECTIVE', 'PARTIAL', 'FAILED'];

    /**
     * Record that a control was checked, and keep the control's own summary current (RISK-3).
     */
    public function verifyControl(int $companyId, int $controlId, string $result,
        array $input = [], ?int $actorId = null): void
    {
        $result = strtoupper(trim($result));

        if (! in_array($result, self::RESULTS, true)) {
            $result = 'PARTIAL';
        }

        $verifiedOn = $input['verified_on'] ?? Carbon::today()->toDateString();

        DB::table('sheq_control_verifications')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'control_id' => $controlId,
            'source_type' => in_array(strtoupper((string) ($input['source_type'] ?? '')),
                self::VERIFICATION_SOURCES, true)
                ? strtoupper((string) $input['source_type']) : 'MANUAL',
            'source_id' => $input['source_id'] ?? null,
            'source_reference' => $input['source_reference'] ?? null,
            'result' => $result,
            'verified_on' => $verifiedOn,
            'verified_by' => $actorId,
            'site_id' => $input['site_id'] ?? null,
            'note' => $input['note'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * The consecutive-failure count, maintained rather than derived.
         *
         * Derived would mean a window function over the verification history every time a risk
         * assessment is rendered, and an assessment has forty lines each with several controls.
         * Reset by an EFFECTIVE result, because what matters is whether it is failing NOW: a
         * control that failed three times last year and has been fine since is not a current
         * problem, and a count that never resets would keep insisting it is.
         */
        $consecutive = $result === 'FAILED'
            ? (int) DB::table('sheq_controls')->where('id', $controlId)
                ->value('consecutive_failures') + 1
            : 0;

        DB::table('sheq_controls')->where('id', $controlId)->update([
            'last_verified_on' => $verifiedOn,
            'last_verification_result' => $result,
            'consecutive_failures' => $consecutive,
            'updated_at' => now(),
        ]);
    }

    /** Record that a risk line's score moved (RISK-1). */
    public function recordMovement(int $companyId, array $input): void
    {
        $from = $input['from_score'] ?? null;
        $to = $input['to_score'] ?? null;

        DB::table('sheq_risk_movements')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'risk_assessment_id' => (int) $input['risk_assessment_id'],
            'risk_line_id' => $input['risk_line_id'] ?? null,
            'activity' => $input['activity'] ?? null,
            'hazard' => $input['hazard'] ?? null,
            'site_id' => $input['site_id'] ?? null,
            'from_score' => $from,
            'to_score' => $to,
            'from_band' => $input['from_band'] ?? null,
            'to_band' => $input['to_band'] ?? null,
            'direction' => match (true) {
                $from === null || $to === null => 'SET',
                (int) $to > (int) $from => 'INCREASED',
                (int) $to < (int) $from => 'DECREASED',
                default => 'UNCHANGED',
            },
            'trigger' => $input['trigger'] ?? null,
            'moved_by' => $input['moved_by'] ?? null,
            'moved_at' => $input['moved_at'] ?? now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Where the risk picture is getting worse (RISK-1).
     *
     * Five separate lists rather than one blended score, because they call for different
     * responses: a control that repeatedly fails is a control problem, a residual risk that went
     * up is a judgement problem, and a repeated observation is a behaviour problem. A single
     * "deterioration index" would mix all three into a number nobody could act on.
     *
     * @return array<string,mixed>
     */
    public function deterioration(int $companyId, ?Carbon $from = null, ?int $siteId = null): array
    {
        $from ??= Carbon::today()->subMonths(6);

        return [
            'from' => $from->toDateString(),
            'signals' => collect(self::SIGNALS)
                ->map(fn (array $meta, string $key) => $meta + ['key' => $key])
                ->values()->all(),

            'residual_increased' => DB::table('sheq_risk_movements as m')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'm.site_id')
                ->leftJoin('sheq_risk_assessments as a', 'a.id', '=', 'm.risk_assessment_id')
                ->where('m.company_id', $companyId)->where('m.direction', 'INCREASED')
                ->where('m.moved_at', '>=', $from)
                ->when($siteId !== null, fn ($q) => $q->where('m.site_id', $siteId))
                ->orderByDesc('m.moved_at')->limit(50)
                ->get(['m.uuid', 'm.activity', 'm.hazard', 'm.from_score', 'm.to_score',
                    'm.from_band', 'm.to_band', 'm.trigger', 'm.moved_at',
                    's.name as site_name', 'a.reference as assessment_reference'])
                ->map(fn ($r) => (array) $r)->all(),

            'controls_failing' => DB::table('sheq_controls as c')
                ->join('sheq_risk_lines as l', 'l.id', '=', 'c.risk_line_id')
                ->join('sheq_risk_assessments as a', 'a.id', '=', 'l.risk_assessment_id')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
                ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
                ->where('c.consecutive_failures', '>=', 2)
                ->when($siteId !== null, fn ($q) => $q->where('a.site_id', $siteId))
                ->orderByDesc('c.consecutive_failures')->limit(50)
                ->get(['c.uuid', 'c.description', 'c.hierarchy_level', 'c.consecutive_failures',
                    'c.last_verified_on', 'c.last_verification_result', 'c.is_implemented',
                    'l.activity', 'l.hazard', 'l.residual_score',
                    'a.reference as assessment_reference', 's.name as site_name'])
                ->map(fn ($r) => array_merge((array) $r, [
                    /*
                     * The contradiction worth leading with: an assessment that still counts a
                     * control as implemented while the evidence says it keeps failing.
                     */
                    'still_claimed_implemented' => (bool) $r->is_implemented,
                ]))->all(),

            'repeated_incidents' => DB::table('sheq_incidents as i')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
                ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
                ->where('i.occurred_at', '>=', $from)
                ->when($siteId !== null, fn ($q) => $q->where('i.site_id', $siteId))
                ->groupBy('i.incident_type', 'i.site_id', 's.name')
                ->havingRaw('count(*) > 1')
                ->orderByRaw('count(*) desc')
                ->selectRaw('i.incident_type, i.site_id, s.name as site_name, count(*) as total,
                    max(i.occurred_at) as last_occurred,
                    sum(case when i.potential_severity >= 4 then 1 else 0 end) as high_potential')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'repeated_inspection_failures' => DB::table('sheq_inspections as x')
                ->join('sheq_inspection_templates as t', 't.id', '=', 'x.template_id')
                ->leftJoin('sheq_sites as s', 's.id', '=', 'x.site_id')
                ->where('x.company_id', $companyId)->whereNull('x.deleted_at')
                ->where('x.passed', false)->whereNotNull('x.completed_at')
                ->where('x.completed_at', '>=', $from)
                ->when($siteId !== null, fn ($q) => $q->where('x.site_id', $siteId))
                ->groupBy('t.code', 't.title', 'x.site_id', 's.name')
                ->havingRaw('count(*) > 1')
                ->orderByRaw('count(*) desc')
                ->selectRaw('t.code, t.title, x.site_id, s.name as site_name, count(*) as total,
                    max(x.completed_at) as last_failed')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'repeated_observations' => DB::table('sheq_observations as o')
                ->leftJoin('sheq_behaviours as b', function ($join) {
                    $join->on('b.code', '=', 'o.behaviour_code')
                        ->on('b.company_id', '=', 'o.company_id');
                })
                ->leftJoin('sheq_sites as s', 's.id', '=', 'o.site_id')
                ->where('o.company_id', $companyId)->whereNull('o.deleted_at')
                // Only the concerning kinds. A positive observation recurring is good news.
                ->whereIn('o.observation_type', ['UNSAFE', 'INTERVENTION'])
                ->whereNotNull('o.behaviour_code')
                ->where('o.observed_at', '>=', $from)
                ->when($siteId !== null, fn ($q) => $q->where('o.site_id', $siteId))
                ->groupBy('o.behaviour_code', 'b.name', 'b.is_high_consequence', 'o.site_id',
                    's.name')
                ->havingRaw('count(*) > 1')
                ->orderByRaw('count(*) desc')
                ->selectRaw('o.behaviour_code, b.name, b.is_high_consequence, o.site_id,
                    s.name as site_name, count(*) as total, max(o.observed_at) as last_seen')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /**
     * Risk trends, cut the five ways RISK-2 asks for.
     *
     * Counted from the risk lines in force rather than from events, which is the distinction
     * between a risk trend and an incident trend: the question is where the ASSESSED risk is
     * concentrated, not where things have gone wrong. The two disagree often, and when they do
     * the disagreement is the finding.
     *
     * @return array<string,mixed>
     */
    public function trends(int $companyId, ?int $siteId = null): array
    {
        $lines = fn () => DB::table('sheq_risk_lines as l')
            ->join('sheq_risk_assessments as a', 'a.id', '=', 'l.risk_assessment_id')
            ->where('l.company_id', $companyId)->whereNull('l.deleted_at')
            ->whereNull('a.deleted_at')->where('a.status', 'APPROVED')
            ->when($siteId !== null, fn ($q) => $q->where('a.site_id', $siteId));

        return [
            'by_site' => $lines()
                ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
                ->groupBy('a.site_id', 's.name')
                ->orderByRaw('avg(l.residual_score) desc nulls last')
                ->selectRaw('a.site_id, s.name, count(*) as lines,
                    round(avg(l.residual_score), 1) as average_residual,
                    max(l.residual_score) as highest_residual,
                    sum(case when l.is_acceptable = false then 1 else 0 end) as unacceptable')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'by_activity' => $lines()
                ->whereNotNull('l.activity')
                ->groupBy('l.activity')
                ->orderByRaw('avg(l.residual_score) desc nulls last')
                ->selectRaw('l.activity, count(*) as lines,
                    round(avg(l.residual_score), 1) as average_residual,
                    max(l.residual_score) as highest_residual')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'by_hazard' => $lines()
                ->whereNotNull('l.hazard')
                ->groupBy('l.hazard')
                ->orderByRaw('count(*) desc')
                ->selectRaw('l.hazard, count(*) as lines,
                    round(avg(l.residual_score), 1) as average_residual')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            /*
             * By contractor and by department, from the EVENTS rather than the lines.
             *
             * A risk assessment is written about work, not about who is doing it — a HIRA for
             * working at height does not name a contractor — so an assessed-risk-by-contractor
             * figure would be empty. What a reader means by the question is "whose work is
             * producing the risk", and the answer to that is in the incident and observation
             * registers, which do record the contractor.
             */
            'by_contractor' => DB::table('sheq_incidents as i')
                ->join('sheq_contractors as c', 'c.id', '=', 'i.contractor_id')
                ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
                ->when($siteId !== null, fn ($q) => $q->where('i.site_id', $siteId))
                ->groupBy('i.contractor_id', 'c.name')
                ->orderByRaw('count(*) desc')
                ->selectRaw('i.contractor_id, c.name, count(*) as incidents,
                    sum(case when i.potential_severity >= 4 then 1 else 0 end) as high_potential')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),

            'by_department' => DB::table('sheq_observations as o')
                ->join('company_units as u', 'u.id', '=', 'o.department_unit_id')
                ->where('o.company_id', $companyId)->whereNull('o.deleted_at')
                ->whereIn('o.observation_type', ['UNSAFE', 'INTERVENTION'])
                ->when($siteId !== null, fn ($q) => $q->where('o.site_id', $siteId))
                ->groupBy('o.department_unit_id', 'u.name')
                ->orderByRaw('count(*) desc')
                ->selectRaw('o.department_unit_id, u.name, count(*) as observations')
                ->limit(30)->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    /**
     * Controls the evidence says are not working (RISK-3).
     *
     * Read from the four sources the requirement names. The key figure is not the failure count
     * but the CONTRADICTION: a control still recorded as implemented, on an assessment somebody
     * approved, which the evidence says keeps failing. That pair is what makes a risk assessment
     * worth less than the paper it is printed on, and nothing in the module could previously
     * produce it.
     *
     * @return array<string,mixed>
     */
    public function ineffectiveControls(int $companyId, ?Carbon $from = null): array
    {
        $from ??= Carbon::today()->subYear();

        $rows = DB::table('sheq_controls as c')
            ->join('sheq_risk_lines as l', 'l.id', '=', 'c.risk_line_id')
            ->join('sheq_risk_assessments as a', 'a.id', '=', 'l.risk_assessment_id')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'a.site_id')
            ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
            ->whereNull('a.deleted_at')
            ->orderByDesc('c.consecutive_failures')
            ->get(['c.id', 'c.uuid', 'c.description', 'c.hierarchy_level', 'c.is_implemented',
                'c.consecutive_failures', 'c.last_verified_on', 'c.last_verification_result',
                'c.verification_method', 'c.verification_frequency', 'c.linked_document_id',
                'c.linked_competency_id', 'c.linked_inspection_template_id',
                'c.linked_permit_type', 'c.linked_activity', 'l.activity', 'l.hazard',
                'l.residual_score', 'a.reference as assessment_reference',
                // The id as well as the name, because the site risk score filters this list by
                // site and a name is not a key. Without it that count is silently always zero.
                'a.site_id', 's.name as site_name']);

        if ($rows->isEmpty()) {
            return ['controls' => [], 'counts' => ['total' => 0, 'failing' => 0,
                'never_verified' => 0, 'contradictions' => 0]];
        }

        $verifications = DB::table('sheq_control_verifications')
            ->where('company_id', $companyId)
            ->whereIn('control_id', $rows->pluck('id'))
            ->where('verified_on', '>=', $from->toDateString())
            ->selectRaw('control_id, result, count(*) as n')
            ->groupBy('control_id', 'result')->get();

        /*
         * CAPA effectiveness, which the requirement names and which is the most telling of the
         * four: an action raised to fix a control, completed, and then reviewed as not effective
         * is the system itself reporting that the fix did not work.
         */
        $ineffectiveCapa = DB::table('sheq_actions')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('effectiveness_result', ['NOT_EFFECTIVE', 'PARTIALLY_EFFECTIVE'])
            ->where('source_type', 'RISK_ASSESSMENT')
            ->selectRaw('source_id, count(*) as n')->groupBy('source_id')
            ->pluck('n', 'source_id');

        $controls = $rows->map(function ($c) use ($verifications, $ineffectiveCapa) {
            $mine = $verifications->where('control_id', $c->id);

            $failed = (int) $mine->where('result', 'FAILED')->sum('n');
            $partial = (int) $mine->where('result', 'PARTIAL')->sum('n');
            $effective = (int) $mine->where('result', 'EFFECTIVE')->sum('n');
            $checks = $failed + $partial + $effective;

            return array_merge((array) $c, [
                'checks' => $checks,
                'failed' => $failed,
                'partial' => $partial,
                'effective' => $effective,
                'failure_rate' => $checks === 0 ? null
                    : (int) round(($failed + $partial) / $checks * 100),
                'ineffective_capa' => (int) ($ineffectiveCapa[$c->id] ?? 0),
                /*
                 * The contradiction. Both halves matter: evidence of failure AND an assessment
                 * that still counts the control. Either alone is ordinary — a control that
                 * failed and has been marked not implemented is being managed.
                 */
                'contradiction' => (bool) $c->is_implemented
                    && ($failed >= 2 || (int) $c->consecutive_failures >= 2),
                /*
                 * Never checked at all, which is its own problem and a quieter one. A control
                 * nobody has ever verified is an assumption, and the assessment presents it as a
                 * barrier.
                 */
                'never_verified' => $checks === 0 && $c->last_verified_on === null,
                // RISK-4: what actually enforces it.
                'enforced_by' => array_values(array_filter([
                    $c->linked_document_id === null ? null : 'a document',
                    $c->linked_competency_id === null ? null : 'a competency',
                    $c->linked_inspection_template_id === null ? null : 'an inspection',
                    $c->linked_permit_type === null ? null : 'a permit',
                    $c->linked_activity === null ? null : 'an activity',
                ])),
                'id' => null,
            ]);
        })
            ->sortByDesc(fn (array $c) => ($c['contradiction'] ? 1000 : 0)
                + $c['failed'] * 10 + $c['ineffective_capa'])
            ->values();

        return [
            'controls' => $controls->all(),
            'counts' => [
                'total' => $controls->count(),
                'failing' => $controls->where('failed', '>', 0)->count(),
                'never_verified' => $controls->where('never_verified', true)->count(),
                'contradictions' => $controls->where('contradiction', true)->count(),
                /*
                 * Controls with nothing enforcing them (RISK-4). A control that is only a
                 * sentence in a document nobody is trained on, that no inspection checks and no
                 * permit requires, is a control in name only — and that is most of them on most
                 * assessments.
                 */
                'unenforced' => $controls->filter(fn (array $c) => $c['enforced_by'] === [])
                    ->count(),
            ],
        ];
    }

    /**
     * The high-risk work board (RISK-5).
     *
     * Everything happening right now that somebody should be able to see on one screen, and the
     * reason it is one screen is that the information is otherwise spread across five registers.
     * A site manager asking "what dangerous work is going on at this moment, who is doing it, and
     * is it covered" has no way to answer that without opening permits, the roster, the
     * assessments and the gate log in turn.
     *
     * Driven from open permits rather than from activities, because a permit is the thing that
     * says high-risk work is ACTUALLY HAPPENING. An activity catalogue says what could happen.
     *
     * @return array<string,mixed>
     */
    public function highRiskBoard(int $companyId, ?int $siteId = null): array
    {
        $now = Carbon::now();

        $permits = DB::table('sheq_permits as p')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'p.site_id')
            ->leftJoin('sheq_contractors as c', 'c.id', '=', 'p.contractor_id')
            ->leftJoin('sheq_risk_assessments as a', 'a.id', '=', 'p.risk_assessment_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.issuer_id')
            ->leftJoin('hr_people as ip', 'ip.user_id', '=', 'u.id')
            ->where('p.company_id', $companyId)->whereNull('p.deleted_at')
            ->whereIn('p.status', ['ACTIVE', 'SUSPENDED'])
            ->when($siteId !== null, fn ($q) => $q->where('p.site_id', $siteId))
            ->orderBy('p.valid_to')
            ->get(['p.id', 'p.uuid', 'p.reference', 'p.permit_type', 'p.work_description',
                'p.location_text', 'p.valid_from', 'p.valid_to', 'p.status',
                'p.competency_overridden', 'p.suspension_reason', 'p.site_id',
                's.name as site_name', 'c.name as contractor_name',
                'a.reference as assessment_reference', 'a.status as assessment_status',
                'a.review_due as assessment_review_due',
                'ip.first_name', 'ip.surname', 'u.email as issuer_email']);

        $activities = DB::table('sheq_activities')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->get(['code', 'name', 'is_high_risk', 'required_credentials'])
            ->keyBy(fn ($a) => strtoupper((string) $a->code));

        return [
            'as_at' => $now->toDateTimeString(),
            'permits' => $permits->map(function ($p) use ($companyId, $activities, $now) {
                $activity = $activities[strtoupper((string) $p->permit_type)] ?? null;

                $workers = DB::table('sheq_permit_workers')
                    ->where('company_id', $companyId)->where('permit_id', $p->id)
                    ->get(['name', 'worker_role'])
                    ->map(fn ($w) => (array) $w)->all();

                $controls = DB::table('sheq_controls as c')
                    ->join('sheq_risk_lines as l', 'l.id', '=', 'c.risk_line_id')
                    ->join('sheq_risk_assessments as a', 'a.id', '=', 'l.risk_assessment_id')
                    ->where('c.company_id', $companyId)->whereNull('c.deleted_at')
                    ->where(fn ($q) => $q->where('c.linked_permit_type', $p->permit_type)
                        ->orWhere('c.linked_activity', strtoupper((string) $p->permit_type)))
                    ->limit(20)
                    ->get(['c.description', 'c.hierarchy_level', 'c.is_implemented',
                        'c.consecutive_failures'])
                    ->map(fn ($c) => (array) $c)->all();

                return array_merge((array) $p, [
                    'activity_name' => $activity->name ?? null,
                    'is_high_risk' => (bool) ($activity->is_high_risk ?? true),
                    'workers' => $workers,
                    'worker_count' => count($workers),
                    'controls' => $controls,
                    /*
                     * The three things that make a live permit worth looking at before the
                     * others, and each is a fact rather than a judgement.
                     */
                    'no_assessment' => $p->assessment_reference === null,
                    'assessment_overdue' => $p->assessment_review_due !== null
                        && Carbon::parse($p->assessment_review_due)->isPast(),
                    'failing_controls' => count(array_filter($controls,
                        fn (array $c) => (int) $c['consecutive_failures'] >= 2)),
                    'expiring_within_hours' => $p->valid_to === null ? null
                        : max(0, (int) $now->diffInHours(Carbon::parse($p->valid_to), false)),
                    'responsible' => trim(($p->first_name ?? '').' '.($p->surname ?? ''))
                        ?: $p->issuer_email,
                    'id' => null, 'first_name' => null, 'surname' => null,
                    'issuer_email' => null,
                ]);
            })->all(),
            'counts' => [
                'live' => $permits->count(),
                'suspended' => $permits->where('status', 'SUSPENDED')->count(),
                'competency_overridden' => $permits->where('competency_overridden', true)->count(),
                'without_assessment' => $permits->whereNull('assessment_reference')->count(),
            ],
            /*
             * Who is actually on site, from the attendance register. A board showing permits
             * without people is a board showing paperwork — and in an evacuation the question is
             * who is inside, not what was authorised.
             */
            'on_site' => (int) DB::table('sheq_site_attendance')
                ->where('company_id', $companyId)->whereNull('exited_at')
                ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
                ->count(),
        ];
    }
}
