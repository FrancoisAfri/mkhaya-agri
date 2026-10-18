<?php

namespace App\Modules\Operations\Sheq\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The analytic half of the AI Safety Officer: trends, hotspots, recurrence and forecasting.
 *
 * **Computed, not generated.** Every figure here comes from a SQL query over the tenant's own
 * records, and the language model is used — where it is used at all — only to phrase the
 * recommendation. That order matters: a model asked to "find trends in this data" will produce a
 * confident narrative whether or not one exists, and a safety manager acting on an invented trend
 * spends their budget on the wrong hazard. The counting is deterministic; only the wording is not.
 *
 * Every insight carries its evidence — the incident ids it counted. An analytic claim nobody can
 * drill into is an opinion with a chart, and the first thing a challenged safety manager needs is
 * to open the four incidents behind "grinding injuries are up".
 *
 * Insights are written to `sheq_ai_insights` and can be dismissed, so a finding somebody has
 * considered and rejected stops resurfacing every night.
 */
class SafetyAnalytics
{
    /** Below this, a "trend" is noise. Two incidents becoming three is not a 50% increase. */
    private const MIN_SAMPLE = 4;

    public function __construct(private readonly SheqAiClient $client) {}

    /** Run everything and return what was found. */
    public function runAll(int $companyId): array
    {
        return [
            'trends' => $this->accidentTrends($companyId),
            'hotspots' => $this->highRiskAreas($companyId),
            'recurrence' => $this->recurringIncidents($companyId),
            'forecast' => $this->complianceForecast($companyId),
            'environmental' => $this->environmentalRecommendations($companyId),
        ];
    }

    /**
     * Incident trends — this quarter against the one before.
     *
     * Reported as a direction with the counts attached rather than a percentage alone, because
     * "up 200%" reads as a crisis when it is two incidents becoming six on a site of four hundred.
     */
    public function accidentTrends(int $companyId): array
    {
        $now = Carbon::today();
        $thisFrom = $now->copy()->subDays(90);
        $lastFrom = $now->copy()->subDays(180);

        $current = $this->countByType($companyId, $thisFrom, $now);
        $previous = $this->countByType($companyId, $lastFrom, $thisFrom);

        $found = [];

        foreach ($current as $type => $rows) {
            $nowCount = count($rows);
            $thenCount = count($previous[$type] ?? []);

            if ($nowCount < self::MIN_SAMPLE) {
                continue;
            }

            $change = $thenCount === 0 ? 100 : (int) round((($nowCount - $thenCount) / $thenCount) * 100);

            if (abs($change) < 25) {
                continue;
            }

            $found[] = $this->record($companyId, [
                'insight_type' => 'TREND',
                'title' => sprintf('%s incidents %s %d%%',
                    ucfirst(strtolower(str_replace('_', ' ', $type))),
                    $change > 0 ? 'up' : 'down', abs($change)),
                'finding' => sprintf(
                    '%d %s incident(s) in the last 90 days, against %d in the 90 days before.',
                    $nowCount, strtolower(str_replace('_', ' ', $type)), $thenCount),
                'recommendation' => $change > 0
                    ? 'Review the controls for this incident type before the next quarter.'
                    : 'Whatever changed here is working — identify it so it can be applied elsewhere.',
                'evidence' => array_slice($rows, 0, 20),
                'sample_size' => $nowCount,
                'severity' => $change > 50 ? 'WARNING' : 'INFO',
                'period_from' => $thisFrom->toDateString(),
                'period_to' => $now->toDateString(),
            ]);
        }

        return $found;
    }

    /**
     * Which sites are producing the incidents, normalised by exposure.
     *
     * Raw counts always name the biggest site, which tells nobody anything. Rate per thousand
     * hours is what makes a small site with a bad month visible.
     */
    public function highRiskAreas(int $companyId): array
    {
        $since = Carbon::today()->subDays(90);

        $rows = DB::table('sheq_incidents as i')
            ->leftJoin('sheq_sites as s', 's.id', '=', 'i.site_id')
            ->where('i.company_id', $companyId)->whereNull('i.deleted_at')
            ->where('i.occurred_at', '>=', $since)
            ->groupBy('i.site_id', 's.name')
            ->selectRaw('i.site_id, s.name, count(*) as total,
                count(*) filter (where i.potential_severity >= 4) as high_potential')
            ->having(DB::raw('count(*)'), '>=', self::MIN_SAMPLE)
            ->get();

        $found = [];

        foreach ($rows as $row) {
            // Exposure is captured per calendar month, so the window is expressed that way rather
            // than as a date range.
            $hours = (float) DB::table('sheq_exposure_hours')
                ->where('company_id', $companyId)
                ->when($row->site_id !== null, fn ($q) => $q->where('site_id', $row->site_id))
                ->whereRaw('make_date(period_year, period_month, 1) >= ?', [$since->startOfMonth()->toDateString()])
                ->selectRaw('coalesce(sum(employee_hours), 0) + coalesce(sum(contractor_hours), 0) as total')
                ->value('total');

            // Without exposure hours the rate is meaningless, so the count is reported plainly
            // rather than dressed as a rate that cannot be compared to anything.
            $rate = $hours > 0 ? round(($row->total / $hours) * 1000, 2) : null;

            $found[] = $this->record($companyId, [
                'insight_type' => 'HOTSPOT',
                'title' => ($row->name ?? 'Unassigned site').' — '.$row->total.' incidents in 90 days',
                'finding' => $rate === null
                    ? sprintf('%d incident(s), %d of them high-potential. No exposure hours '
                        .'recorded for this site, so this cannot be compared to others.',
                        $row->total, $row->high_potential)
                    : sprintf('%d incident(s) over %s hours worked — %s per 1 000 hours. %d were '
                        .'high-potential.', $row->total, number_format($hours), $rate,
                        $row->high_potential),
                'recommendation' => $row->high_potential > 0
                    ? 'High-potential events here have not yet caused serious harm. That is the '
                      .'window in which they are cheapest to fix.'
                    : 'Review whether the controls at this site match the work being done.',
                'sample_size' => (int) $row->total,
                'severity' => $row->high_potential > 0 ? 'WARNING' : 'INFO',
                'site_id' => $row->site_id,
                'period_from' => $since->toDateString(),
                'period_to' => Carbon::today()->toDateString(),
            ]);
        }

        return $found;
    }

    /**
     * The same thing happening again.
     *
     * The most valuable output in this class. A recurring incident means the corrective action
     * from last time did not work — and nothing in a normal register makes that visible, because
     * each one was closed on its own terms.
     */
    public function recurringIncidents(int $companyId): array
    {
        $since = Carbon::today()->subMonths(12);

        $rows = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('occurred_at', '>=', $since)
            ->get(['id', 'reference', 'incident_type', 'description', 'site_id', 'occurred_at']);

        // Grouped on the significant words of the description. Crude, and deliberately so: a
        // clustering model would find patterns nobody can explain, and the point is to hand a
        // safety manager something they can immediately recognise as the same job going wrong.
        $clusters = [];

        foreach ($rows as $row) {
            $key = $row->incident_type.'|'.implode(' ', array_slice($this->keywords($row->description), 0, 3));

            if (trim(Str::after($key, '|')) === '') {
                continue;
            }

            $clusters[$key][] = ['id' => (int) $row->id, 'reference' => $row->reference,
                'occurred_at' => $row->occurred_at];
        }

        $found = [];

        foreach ($clusters as $key => $members) {
            if (count($members) < 3) {
                continue;
            }

            $found[] = $this->record($companyId, [
                'insight_type' => 'RECURRENCE',
                'title' => sprintf('%d similar incidents: %s', count($members),
                    str_replace('|', ' — ', $key)),
                'finding' => sprintf(
                    'The same kind of event has been reported %d times in 12 months (%s). Where '
                    .'corrective actions were closed on the earlier ones, they have not prevented '
                    .'recurrence.',
                    count($members), implode(', ', array_column(array_slice($members, 0, 5), 'reference'))),
                'recommendation' => 'Reopen the root cause. A control that has failed three times '
                    .'is usually addressing a symptom rather than the cause.',
                'evidence' => array_slice($members, 0, 20),
                'sample_size' => count($members),
                'severity' => count($members) >= 5 ? 'CRITICAL' : 'WARNING',
                'period_from' => $since->toDateString(),
                'period_to' => Carbon::today()->toDateString(),
            ]);
        }

        return $found;
    }

    /**
     * What is about to fall out of compliance.
     *
     * A forecast in the only sense that is honest here: counting dated obligations that will lapse
     * if nothing is done. No model is asked to predict anything — the dates are already known, and
     * the failure is simply that nobody looked at them together.
     */
    public function complianceForecast(int $companyId): array
    {
        $horizon = Carbon::today()->addDays(60);
        $today = Carbon::today();

        $counts = [
            'competencies' => DB::table('sheq_competency_records')->where('company_id', $companyId)
                ->whereNull('deleted_at')->whereNull('revoked_at')
                ->whereBetween('expires_at', [$today, $horizon])->count(),
            'medicals' => DB::table('sheq_medical_records')->where('company_id', $companyId)
                ->whereNull('deleted_at')->whereBetween('expires_on', [$today, $horizon])->count(),
            'inductions' => DB::table('sheq_induction_completions')->where('company_id', $companyId)
                ->whereBetween('expires_on', [$today, $horizon])->count(),
            'supplier approvals' => DB::table('sheq_supplier_assessments')->where('company_id', $companyId)
                ->whereNull('deleted_at')->whereBetween('valid_until', [$today, $horizon])->count(),
            'equipment certificates' => DB::table('sheq_equipment')->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereBetween('certificate_expires_on', [$today, $horizon])->count(),
        ];

        $total = array_sum($counts);

        if ($total === 0) {
            return [];
        }

        $parts = [];

        foreach (array_filter($counts) as $what => $count) {
            $parts[] = "{$count} {$what}";
        }

        return [$this->record($companyId, [
            'insight_type' => 'FORECAST',
            'title' => "{$total} compliance item(s) lapse within 60 days",
            'finding' => 'Expiring: '.implode(', ', $parts).'.',
            'recommendation' => 'Book these now. Each one becomes a person refused at the gate or '
                .'a job that cannot proceed on the day it lapses.',
            'sample_size' => $total,
            'severity' => $total > 20 ? 'WARNING' : 'INFO',
            'period_from' => $today->toDateString(),
            'period_to' => $horizon->toDateString(),
        ])];
    }

    /**
     * Environmental recommendations, from the readings.
     *
     * Compares each aspect against its own prior period. Consumption moves for legitimate reasons —
     * production volume, season — so this reports the movement and leaves the explaining to the
     * people who know what changed, rather than asserting a cause it cannot know.
     */
    public function environmentalRecommendations(int $companyId): array
    {
        $now = Carbon::today();
        $thisFrom = $now->copy()->subDays(90);
        $lastFrom = $now->copy()->subDays(180);

        $current = $this->aspectTotals($companyId, $thisFrom, $now);
        $previous = $this->aspectTotals($companyId, $lastFrom, $thisFrom);

        $found = [];

        foreach ($current as $aspect => $total) {
            $before = $previous[$aspect] ?? 0.0;

            if ($before <= 0.0) {
                continue;
            }

            $change = (int) round((($total - $before) / $before) * 100);

            if (abs($change) < 15) {
                continue;
            }

            $found[] = $this->record($companyId, [
                'insight_type' => 'ENVIRONMENTAL',
                'title' => sprintf('%s consumption %s %d%%', ucfirst(strtolower($aspect)),
                    $change > 0 ? 'up' : 'down', abs($change)),
                'finding' => sprintf('%s over the last 90 days against %s in the 90 before.',
                    number_format($total, 2), number_format($before, 2)),
                'recommendation' => $change > 0
                    ? 'Check whether output rose in proportion. If it did not, the difference is '
                      .'waste — and it is cheapest to find while the readings are recent.'
                    : 'Record what changed. An unexplained reduction is as often a meter that '
                      .'stopped being read as a genuine saving.',
                'sample_size' => 1,
                'severity' => 'INFO',
                'period_from' => $thisFrom->toDateString(),
                'period_to' => $now->toDateString(),
            ]);
        }

        return $found;
    }

    /** Insights a person has not yet dismissed. */
    public function open(int $companyId, int $limit = 50): array
    {
        return DB::table('sheq_ai_insights')
            ->where('company_id', $companyId)->whereNull('dismissed_at')
            ->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 else 2 end")
            ->orderByDesc('created_at')->limit($limit)
            ->get(['uuid', 'insight_type', 'title', 'finding', 'recommendation', 'severity',
                'sample_size', 'evidence', 'period_from', 'period_to', 'created_at'])
            ->map(fn ($i) => array_merge((array) $i, [
                'evidence' => json_decode((string) $i->evidence, true) ?: [],
            ]))->values()->all();
    }

    public function dismiss(int $companyId, string $uuid, int $userId, ?string $note = null): void
    {
        DB::table('sheq_ai_insights')->where('company_id', $companyId)->where('uuid', $uuid)
            ->update(['dismissed_at' => now(), 'dismissed_by' => $userId,
                'dismiss_note' => $note, 'updated_at' => now()]);
    }

    /**
     * Write an insight, replacing an identical open one rather than stacking duplicates.
     *
     * @return array<string,mixed>
     */
    private function record(int $companyId, array $data): array
    {
        $existing = DB::table('sheq_ai_insights')
            ->where('company_id', $companyId)
            ->where('insight_type', $data['insight_type'])
            ->where('title', $data['title'])
            ->whereNull('dismissed_at')
            ->value('uuid');

        $uuid = $existing ?? (string) Str::uuid();

        $payload = [
            'company_id' => $companyId,
            'insight_type' => $data['insight_type'],
            'title' => $data['title'],
            'finding' => $data['finding'],
            'recommendation' => $data['recommendation'] ?? null,
            'evidence' => json_encode($data['evidence'] ?? []),
            'sample_size' => $data['sample_size'] ?? 0,
            'severity' => $data['severity'] ?? 'INFO',
            'site_id' => $data['site_id'] ?? null,
            'period_from' => $data['period_from'] ?? null,
            'period_to' => $data['period_to'] ?? null,
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            DB::table('sheq_ai_insights')->where('uuid', $existing)->update($payload);
        } else {
            DB::table('sheq_ai_insights')->insert($payload + ['uuid' => $uuid, 'created_at' => now()]);
        }

        return ['uuid' => $uuid] + $data;
    }

    /** @return array<string, list<array<string,mixed>>> */
    private function countByType(int $companyId, Carbon $from, Carbon $to): array
    {
        $out = [];

        $rows = DB::table('sheq_incidents')
            ->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereBetween('occurred_at', [$from, $to])
            ->get(['id', 'reference', 'incident_type', 'occurred_at']);

        foreach ($rows as $row) {
            $out[$row->incident_type][] = ['id' => (int) $row->id,
                'reference' => $row->reference, 'occurred_at' => $row->occurred_at];
        }

        return $out;
    }

    /** @return array<string, float> */
    private function aspectTotals(int $companyId, Carbon $from, Carbon $to): array
    {
        return DB::table('sheq_environmental_readings as r')
            ->join('sheq_environmental_meters as m', 'm.id', '=', 'r.meter_id')
            ->where('r.company_id', $companyId)
            ->whereBetween('r.reading_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('m.aspect')
            ->selectRaw('m.aspect, sum(r.value) as total')
            ->pluck('total', 'aspect')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * The words in a description that carry meaning.
     *
     * Stop words removed so "the operator was struck by the grinder" clusters on *struck* and
     * *grinder*, which is what makes two reports recognisably the same event.
     *
     * @return list<string>
     */
    private function keywords(?string $text): array
    {
        $stop = ['the', 'was', 'and', 'his', 'her', 'had', 'for', 'with', 'from', 'that', 'this',
            'when', 'while', 'were', 'been', 'have', 'has', 'not', 'but', 'out', 'into', 'onto',
            'off', 'his', 'she', 'him', 'they', 'their', 'then', 'due', 'did', 'got', 'get'];

        $words = preg_split('/[^\p{L}]+/u', mb_strtolower((string) $text)) ?: [];

        $keep = array_values(array_filter($words,
            fn ($w) => mb_strlen($w) > 3 && ! in_array($w, $stop, true)));

        sort($keep);

        return array_values(array_unique($keep));
    }
}
