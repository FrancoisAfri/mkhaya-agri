<?php

namespace App\Modules\Operations\Sheq\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Contractor performance, counted from what happened rather than rated by hand.
 *
 * A contractor score somebody types in is a score of the relationship — the contractor who is
 * pleasant to deal with rates well, and the one who argues about scope rates badly. These figures
 * come from the incident, inspection, action and access registers, so what a procurement manager
 * sees at renewal is what the site actually produced.
 *
 * Normalised by workers on site. A contractor with two hundred people and four incidents is not
 * performing worse than one with three people and two, and a raw count says otherwise.
 */
class ContractorPerformanceService
{
    /**
     * The ten factors CON-20 lists, each with what it counts and what it costs.
     *
     * Declared rather than implied by whichever counts happen to be written, so the screen can
     * name every factor, `ContractorScoreTest` can assert the requirement's list is covered, and
     * a factor that cannot yet be counted says so instead of silently contributing nothing.
     *
     * The weights are a view, not a standard. A high-potential incident weighs most because it is
     * the near miss that did not happen to hurt anybody — the strongest available signal about
     * what comes next — and a near miss itself weighs little because reporting them is behaviour
     * a site wants to encourage. Penalising a contractor for reporting near misses teaches them
     * to stop reporting, and the figure improves while the site gets more dangerous. That is
     * exactly the kind of judgement a host should be able to overrule, which is why they are
     * configurable.
     *
     * @var array<string, array{weight:int, label:string, source:string, available:bool,
     *                          note:string}>
     */
    public const FACTORS = [
        'incidents' => [
            'weight' => 3, 'label' => 'Incidents', 'source' => 'sheq_incidents',
            'available' => true,
            'note' => 'Reported incidents attributed to this contractor.',
        ],
        'high_potential_incidents' => [
            'weight' => 8, 'label' => 'High-potential incidents', 'source' => 'sheq_incidents',
            'available' => true,
            'note' => 'The near miss that did not happen to hurt anybody — the strongest signal '
                .'available about what comes next.',
        ],
        'near_misses' => [
            'weight' => 1, 'label' => 'Near misses', 'source' => 'sheq_incidents',
            'available' => true,
            'note' => 'Weighted lightly on purpose. Penalising a contractor for reporting near '
                .'misses teaches them to stop reporting, and then the score improves while the '
                .'site gets more dangerous.',
        ],
        'audit_findings' => [
            'weight' => 2, 'label' => 'Audit findings', 'source' => 'sheq_audit_findings',
            'available' => true,
            'note' => 'Findings raised against this contractor in an audit.',
        ],
        'inspection_failures' => [
            'weight' => 2, 'label' => 'Failed inspections', 'source' => 'sheq_inspections',
            'available' => true,
            'note' => 'Inspections of this contractor that did not pass.',
        ],
        'overdue_actions' => [
            'weight' => 2, 'label' => 'Overdue CAPA', 'source' => 'sheq_actions',
            'available' => true,
            'note' => 'Corrective actions past their due date and not yet verified.',
        ],
        'document_gaps' => [
            'weight' => 2, 'label' => 'Document compliance', 'source' => 'sheq_contractor_documents',
            'available' => true,
            'note' => 'Mandatory documents missing, expired or never accepted.',
        ],
        'safety_file_gaps' => [
            'weight' => 4, 'label' => 'Safety file', 'source' => 'sheq_safety_file_submissions',
            'available' => true,
            'note' => 'Counted when the safety file is not in an approved state.',
        ],
        'competency_gaps' => [
            'weight' => 1, 'label' => 'Competency compliance',
            'source' => 'sheq_contractor_worker_credentials', 'available' => true,
            'note' => 'Workers whose accepted competency or medical has expired.',
        ],
        'access_refusals' => [
            'weight' => 1, 'label' => 'Access violations', 'source' => 'sheq_access_events',
            'available' => true,
            'note' => 'People of theirs refused at a gate.',
        ],
        /*
         * Permit violations, now that a permit records whose work it is.
         *
         * Two things are counted and both are a documented departure from a controlled
         * procedure, which is what a "violation" means for a permit regime:
         *
         * A SUSPENSION is work stopped for cause while it was under way. Somebody with the
         * authority to stop it did, and wrote down why.
         *
         * A COMPETENCY OVERRIDE is a permit issued to a crew the competency ledger could not
         * vouch for, on a named person's authority. That is sometimes the right call — a
         * contractor's people have no personnel record here, so their tickets are the
         * contractor's to evidence — and it is still a permit issued without the check.
         *
         * What is deliberately NOT counted is an expired permit. A permit running past its
         * valid_to is overwhelmingly a crew finishing a job and not closing the paperwork, and
         * charging a safety score for an administrative lapse would swamp the two signals above
         * with noise.
         *
         * Weighted 4: between a failed inspection and a high-potential incident. A permit
         * suspended mid-task is a stronger signal than a failed checklist and a weaker one than
         * something that nearly hurt somebody.
         */
        'permit_violations' => [
            'weight' => 4, 'label' => 'Permit violations', 'source' => 'sheq_permits',
            'available' => true,
            'note' => 'Permits for their work that were suspended, or issued with the competency '
                .'check overridden. An expired permit is not counted — that is paperwork, not a '
                .'departure from the procedure.',
        ],
    ];

    /**
     * The factor keys that have a stored count, which is also the list of columns.
     *
     * @return list<string>
     */
    public static function countableFactors(): array
    {
        return array_keys(array_filter(self::FACTORS, fn (array $f) => $f['available']));
    }

    /** Rating bands. Separate from the weights because a host may reweight without rebanding. */
    private const BANDS = ['GOOD' => 90, 'ACCEPTABLE' => 75, 'WATCH' => 50];

    /** The weights this tenant uses, with the shipped ones underneath. */
    public function weightsFor(int $companyId): array
    {
        $stored = json_decode((string) DB::table('sheq_settings')
            ->where('company_id', $companyId)->value('contractor_score_weights'), true);

        $weights = [];

        foreach (self::FACTORS as $key => $factor) {
            /*
             * Merged rather than replaced, so a stored set written before a factor existed does
             * not leave the new one undefined. A factor that cannot be counted is pinned to zero
             * whatever is stored: a weight on an uncountable factor is a setting that appears to
             * do something and does not.
             */
            $weights[$key] = $factor['available']
                ? max(0, (int) ($stored[$key] ?? $factor['weight']))
                : 0;
        }

        return $weights;
    }

    /** Save a tenant's weights, on the record. */
    public function saveWeights(int $companyId, array $weights, ?int $actorId = null): array
    {
        $before = $this->weightsFor($companyId);
        $clean = [];

        foreach (self::FACTORS as $key => $factor) {
            if (! $factor['available']) {
                continue;
            }

            // Capped. A weight of 9,000 does not express strong feeling, it just makes every
            // contractor with one incident score zero and the figure stops distinguishing them.
            $clean[$key] = max(0, min(50, (int) ($weights[$key] ?? $factor['weight'])));
        }

        DB::table('sheq_settings')->updateOrInsert(
            ['company_id' => $companyId],
            ['contractor_score_weights' => json_encode($clean), 'updated_at' => now()],
        );

        DB::table('sheq_audit_log')->insert([
            'company_id' => $companyId, 'actor_id' => $actorId, 'actor_type' => 'USER',
            'action' => 'contractor.score_weights_changed',
            'subject_type' => 'SheqSettings', 'subject_id' => 0,
            'before' => json_encode($before), 'after' => json_encode($clean),
            'reason' => 'How much each factor costs a contractor score.',
        ]);

        return $clean;
    }

    /**
     * Score one contractor for one month.
     *
     * @return array<string,mixed>
     */
    public function computeMonth(int $companyId, int $contractorId, ?Carbon $month = null): array
    {
        $month ??= Carbon::today()->startOfMonth();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $period = $from->format('Y-m');

        $counts = $this->counts($companyId, $contractorId, $from, $to);

        $workers = (int) DB::table('sheq_contractor_workers')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->where('is_active', true)->count();

        $weights = $this->weightsFor($companyId);
        $scored = $this->score($counts, $workers, $weights);

        /*
         * The score before this run, so a change can be recorded (CON-22).
         *
         * Nothing audited scores at all. A contractor could move from GOOD to POOR between two
         * monthly runs and the only trace was the new number overwriting the old one in place —
         * so a procurement manager comparing what they remembered with what the screen said had
         * no way to tell whether the score had changed or their memory had.
         */
        $before = DB::table('sheq_contractor_scores')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->where('period', $period)->value('score');

        DB::table('sheq_contractor_scores')->updateOrInsert(
            ['company_id' => $companyId, 'contractor_id' => $contractorId, 'period' => $period],
            $counts + [
                'uuid' => (string) Str::uuid(),
                'workers_on_site' => $workers,
                'score' => $scored['score'],
                'rating' => $this->rating($scored['score']),
                // Stored, not recomputed: the registers behind a score move, and a figure that
                // explains itself with today's data is a figure that rewrites its own history.
                'breakdown' => json_encode($scored['breakdown']),
                'weights' => json_encode($weights),
                'computed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        /*
         * Only an actual change. A monthly job that re-runs for the same period writes the same
         * figure, and an audit row per run would bury the one month the score moved.
         */
        if ($before === null ? $scored['score'] !== null
            : (float) $before !== (float) $scored['score']) {
            DB::table('sheq_audit_log')->insert([
                'company_id' => $companyId,
                'actor_id' => null,
                'actor_type' => 'SYSTEM',
                'action' => 'contractor.score_changed',
                'subject_type' => 'Contractor',
                'subject_id' => $contractorId,
                'before' => json_encode(['period' => $period, 'score' => $before]),
                'after' => json_encode(['period' => $period, 'score' => $scored['score'],
                    'rating' => $this->rating($scored['score']),
                    'contractor_id' => $contractorId]),
                'reason' => 'Counted from the incident, inspection, action and access registers.',
            ]);
        }

        return $counts + [
            'period' => $period, 'workers_on_site' => $workers,
            'score' => $scored['score'], 'rating' => $this->rating($scored['score']),
            'breakdown' => $scored['breakdown'], 'weights' => $weights,
        ];
    }

    /**
     * What happened to this contractor in the period.
     *
     * Every count is filtered by contractor, which sounds too obvious to be worth saying and is
     * the bug that was here: `inspection_failures` and `access_refusals` filtered on the company
     * and not on the contractor, so every contractor on the estate was charged with every failed
     * inspection and every refusal at every gate. A contractor with a spotless record carried
     * their competitors' penalties, and the figure a procurement manager renewed contracts on was
     * the same for all of them except for the two counts that did filter.
     *
     * @return array<string,int>
     */
    private function counts(int $companyId, int $contractorId, Carbon $from, Carbon $to): array
    {
        $incidents = fn () => DB::table('sheq_incidents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->whereBetween('occurred_at', [$from, $to]);

        return [
            'incidents' => (int) $incidents()->where('incident_type', '!=', 'NEAR_MISS')->count(),
            'high_potential_incidents' => (int) $incidents()
                ->where('potential_severity', '>=', 4)->count(),
            'near_misses' => (int) $incidents()->where('incident_type', 'NEAR_MISS')->count(),

            'audit_findings' => (int) DB::table('sheq_audit_findings as f')
                ->join('sheq_audits as a', 'a.id', '=', 'f.audit_id')
                ->where('f.company_id', $companyId)->whereNull('f.deleted_at')
                ->where('a.auditee_type', 'CONTRACTOR')->where('a.auditee_id', $contractorId)
                ->whereBetween('f.created_at', [$from, $to])->count(),

            'inspection_failures' => (int) DB::table('sheq_inspections')
                ->where('company_id', $companyId)->where('passed', false)
                ->whereNull('deleted_at')
                // The clause that was missing.
                ->where('subject_type', 'CONTRACTOR')->where('subject_id', $contractorId)
                ->whereBetween('completed_at', [$from, $to])->count(),

            'access_refusals' => (int) DB::table('sheq_access_events')
                ->where('company_id', $companyId)->where('allowed', false)
                ->where('subject_type', 'CONTRACTOR_WORKER')
                // And here.
                ->where('contractor_id', $contractorId)
                ->whereBetween('occurred_at', [$from, $to])->count(),

            'overdue_actions' => (int) DB::table('sheq_actions')
                ->where('company_id', $companyId)->whereNull('deleted_at')
                ->whereNotIn('status', ['VERIFIED', 'VERIFIED_CLOSED', 'CLOSED'])
                ->whereNotNull('due_date')->where('due_date', '<', $to)
                ->where(function ($q) use ($contractorId) {
                    /*
                     * Either linkage. `contractor_id` is the column added for the portal; the
                     * older `source_type`/`source_id` pair is how actions raised from a
                     * contractor review were recorded, and both are live in existing data.
                     */
                    $q->where('contractor_id', $contractorId)
                        ->orWhere(fn ($w) => $w->where('source_type', 'CONTRACTOR')
                            ->where('source_id', $contractorId));
                })->count(),

            'permit_violations' => (int) DB::table('sheq_permits')
                ->where('company_id', $companyId)->where('contractor_id', $contractorId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($from, $to) {
                    /*
                     * Dated on when the departure happened, not on when the permit was raised. A
                     * permit opened in February and suspended in March is a March event — it is
                     * the suspension being counted, not the permit.
                     */
                    $q->whereBetween('suspended_at', [$from, $to])
                        ->orWhereBetween('competency_overridden_at', [$from, $to]);
                })->count(),

            // The state of the file as at the end of the period rather than events during it:
            // "was this contractor's paperwork in order that month" is the question.
            'document_gaps' => $this->documentGaps($companyId, $contractorId, $to),
            'safety_file_gaps' => $this->safetyFileGaps($companyId, $contractorId),
            'competency_gaps' => $this->competencyGaps($companyId, $contractorId, $to),
        ];
    }

    /** Mandatory documents missing, expired or never accepted as at a date. */
    private function documentGaps(int $companyId, int $contractorId, Carbon $asAt): int
    {
        $required = json_decode((string) DB::table('sheq_settings')
            ->where('company_id', $companyId)->value('contractor_required_documents'), true);

        if (! is_array($required) || $required === []) {
            return 0;
        }

        $live = DB::table('sheq_contractor_documents')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')->whereNull('superseded_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', 'ACCEPTED'))
            ->where(fn ($q) => $q->whereNull('expires_on')
                ->orWhere('expires_on', '>=', $asAt->toDateString()))
            ->pluck('document_type')->map(fn ($t) => (string) $t)->unique()->all();

        return count(array_diff(array_map('strval', $required), $live));
    }

    /** One if the safety file is not approved, nought if it is. */
    private function safetyFileGaps(int $companyId, int $contractorId): int
    {
        $state = DB::table('sheq_safety_file_submissions')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->whereNull('deleted_at')
            ->orderByDesc('version')->value('state');

        return $state === 'APPROVED' ? 0 : 1;
    }

    /** Workers whose accepted competency or medical has lapsed. */
    private function competencyGaps(int $companyId, int $contractorId, Carbon $asAt): int
    {
        return (int) DB::table('sheq_contractor_worker_credentials as c')
            ->join('sheq_contractor_workers as w', 'w.id', '=', 'c.worker_id')
            ->where('c.company_id', $companyId)->where('w.contractor_id', $contractorId)
            ->whereNull('c.deleted_at')->whereNull('w.deleted_at')->where('w.is_active', true)
            ->where('c.status', 'ACCEPTED')
            ->whereNotNull('c.expires_on')->where('c.expires_on', '<', $asAt->toDateString())
            ->distinct()->count('c.id');
    }

    /**
     * 100 down to 0, and a line of arithmetic for every factor.
     *
     * Starts at 100 and subtracts weighted events per hundred workers, so a large contractor is
     * not penalised for being large. A contractor with no people on site scores nothing rather
     * than a perfect 100 — an absent contractor is not a safe one, they are simply absent.
     *
     * @return array{score:?float, breakdown:array<string,mixed>}
     */
    private function score(array $counts, int $workers, array $weights): array
    {
        if ($workers === 0) {
            return ['score' => null, 'breakdown' => [
                'workers_on_site' => 0,
                'note' => 'Not scored: this contractor has nobody on site, and an absent '
                    .'contractor is not a safe one — they are simply absent.',
                'factors' => [],
            ]];
        }

        $factors = [];
        $penalty = 0;

        foreach (self::FACTORS as $key => $factor) {
            $count = (int) ($counts[$key] ?? 0);
            $weight = (int) ($weights[$key] ?? 0);
            $cost = round(($count * $weight / $workers) * 100, 2);

            $penalty += $count * $weight;

            $factors[] = [
                'key' => $key,
                'label' => $factor['label'],
                'count' => $count,
                'weight' => $weight,
                // What this factor actually took off, so the arithmetic on the screen adds up to
                // the number at the top of it.
                'cost' => $cost,
                'available' => $factor['available'],
                'note' => $factor['note'],
            ];
        }

        $per100 = ($penalty / max($workers, 1)) * 100;
        $score = round(max(0, 100 - $per100), 2);

        return ['score' => $score, 'breakdown' => [
            'workers_on_site' => $workers,
            'started_at' => 100,
            'deducted' => round(min(100, $per100), 2),
            'score' => $score,
            // Said plainly, because a score of 0 for a contractor with nine incidents and three
            // people reads as a bug otherwise.
            'floored' => $per100 > 100,
            'factors' => $factors,
        ]];
    }

    private function rating(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }

        foreach (self::BANDS as $rating => $floor) {
            if ($score >= $floor) {
                return $rating;
            }
        }

        return 'POOR';
    }

    /** Every contractor, for the month. */
    public function computeAll(int $companyId, ?Carbon $month = null): int
    {
        $contractors = DB::table('sheq_contractors')
            ->where('company_id', $companyId)->whereNull('deleted_at')->pluck('id');

        foreach ($contractors as $contractorId) {
            $this->computeMonth($companyId, (int) $contractorId, $month);
        }

        return $contractors->count();
    }

    /**
     * The trend a procurement manager reads at renewal (CON-21).
     *
     * Oldest first, which is the opposite of how the register lists things and right here: a
     * trend is read left to right, and a chart handed its points backwards slopes the wrong way.
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $companyId, int $contractorId, int $months = 12): array
    {
        $rows = DB::table('sheq_contractor_scores')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->orderByDesc('period')->limit($months)
            /*
             * The countable factors only. A declared-but-uncountable factor has no column to
             * select — `permit_violations` is declared so the screen can say why it is not
             * counted, and selecting it here would make every read of the trend fail.
             */
            ->get(array_merge(['period', 'score', 'rating', 'workers_on_site', 'breakdown'],
                self::countableFactors()))
            ->map(fn ($r) => array_merge((array) $r, [
                'breakdown' => json_decode((string) $r->breakdown, true) ?: null,
            ]))
            ->reverse()->values()->all();

        return $rows;
    }

    /**
     * This period against the last, factor by factor (CON-21's trend comparison).
     *
     * The comparison rather than two lists side by side, because the useful fact is the
     * direction: three incidents last month and three this month is a different conversation from
     * nought and three, and a reader asked to subtract two columns in their head will get it
     * wrong in the direction that suits whoever is presenting.
     *
     * @return array<string,mixed>
     */
    public function comparison(int $companyId, int $contractorId, ?string $period = null): array
    {
        $period ??= Carbon::today()->format('Y-m');
        $previous = Carbon::createFromFormat('Y-m', $period)->startOfMonth()
            ->subMonth()->format('Y-m');

        $read = fn (string $p) => DB::table('sheq_contractor_scores')
            ->where('company_id', $companyId)->where('contractor_id', $contractorId)
            ->where('period', $p)->first();

        $now = $read($period);
        $then = $read($previous);

        $factors = [];

        foreach (self::FACTORS as $key => $factor) {
            $current = (int) ($now->{$key} ?? 0);
            $prior = (int) ($then->{$key} ?? 0);

            $factors[] = [
                'key' => $key,
                'label' => $factor['label'],
                'available' => $factor['available'],
                'current' => $current,
                'previous' => $prior,
                'change' => $current - $prior,
                /*
                 * Which direction is good. Every factor here is a count of something unwanted,
                 * so fewer is better — but saying so explicitly means a screen never has to
                 * assume it, and the next factor added might not be.
                 */
                'improved' => $current < $prior,
            ];
        }

        return [
            'period' => $period,
            'previous_period' => $previous,
            'score' => $now->score === null ? null : (float) $now->score,
            'previous_score' => $then?->score === null ? null : (float) $then->score,
            'change' => $now?->score === null || $then?->score === null
                // Null rather than nought: "no change" and "there is nothing to compare with"
                // are different, and a chart that draws the second as the first invents a flat
                // line out of an absence.
                ? null
                : round((float) $now->score - (float) $then->score, 2),
            'rating' => $now->rating ?? null,
            'previous_rating' => $then->rating ?? null,
            'factors' => $factors,
            'breakdown' => $now === null ? null
                : (json_decode((string) $now->breakdown, true) ?: null),
        ];
    }

    /** The league table, worst first — which is the useful direction. */
    public function leaderboard(int $companyId, ?string $period = null): array
    {
        $period ??= Carbon::today()->format('Y-m');

        return DB::table('sheq_contractor_scores as s')
            ->join('sheq_contractors as c', 'c.id', '=', 's.contractor_id')
            ->where('s.company_id', $companyId)->where('s.period', $period)
            ->whereNotNull('s.score')
            ->orderBy('s.score')
            ->get(['c.name', 'c.uuid', 's.score', 's.rating', 's.incidents',
                's.high_potential_incidents', 's.near_misses', 's.audit_findings',
                's.document_gaps', 's.safety_file_gaps', 's.competency_gaps',
                's.inspection_failures', 's.access_refusals', 's.overdue_actions',
                's.workers_on_site'])
            ->map(fn ($r) => (array) $r)->values()->all();
    }
}
