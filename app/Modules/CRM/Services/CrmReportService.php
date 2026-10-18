<?php

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reporting & analytics aggregations (spec section 6).
 *
 * Pure query/aggregation logic — no side effects — so it is straightforward to
 * unit-test and to feed both the dashboard and the BI/CSV export.
 *
 * Date range ($from, $to) applies to the relevant date column per report
 * (closed_at for won/lost & attribution; created_at for the funnel & activity).
 */
class CrmReportService
{
    public function __construct(
        private ?string $from = null,
        private ?string $to = null,
        private ?array $owners = null,
    ) {
    }

    public function withRange(?string $from, ?string $to): self
    {
        return new self($from, $to, $this->owners);
    }

    /** Restrict every aggregation to these owner ids (null = all owners). */
    public function forOwners(?array $owners): self
    {
        return new self($this->from, $this->to, $owners);
    }

    /** Apply the owner filter to a query on the given owner column. */
    private function applyOwners($query, string $column = 'owner_id')
    {
        if ($this->owners !== null) {
            $query->whereIn($column, $this->owners);
        }
        return $query;
    }

    /**
     * Headline KPIs.
     */
    public function summary(): array
    {
        $open = $this->scopeCreated(CrmDeal::where('status', 'open'));
        $won  = $this->scopeClosed(CrmDeal::where('status', 'won'));
        $lost = $this->scopeClosed(CrmDeal::where('status', 'lost'));

        $wonCount  = (clone $won)->count();
        $lostCount = (clone $lost)->count();
        $closed    = $wonCount + $lostCount;

        return [
            'open_count'        => (clone $open)->count(),
            'open_value'        => (float) (clone $open)->sum('amount'),
            'weighted_pipeline' => round($this->weightedPipeline(), 2),
            'won_count'         => $wonCount,
            'won_value'         => (float) (clone $won)->sum('amount'),
            'win_rate'          => $closed > 0 ? round($wonCount / $closed * 100, 1) : 0.0,
            'avg_deal_size'     => $wonCount > 0 ? round((float) (clone $won)->sum('amount') / $wonCount, 2) : 0.0,
        ];
    }

    /**
     * Current open-deal distribution per stage of the default pipeline, with the
     * stage-to-stage conversion ratio.
     */
    public function funnel(): array
    {
        $pipeline = CrmPipeline::where('is_default', true)->with('stages')->first()
            ?? CrmPipeline::with('stages')->first();
        if (! $pipeline) return [];

        $counts = $this->scopeCreated(CrmDeal::where('pipeline_id', $pipeline->id))
            ->select('stage_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(amount),0) as v'))
            ->groupBy('stage_id')->get()->keyBy('stage_id');

        $rows  = [];
        $first = null;
        foreach ($pipeline->stages as $s) {
            $count = (int) ($counts[$s->id]->c ?? 0);
            $first ??= max($count, 1);
            $rows[] = [
                'stage'      => $s->name,
                'count'      => $count,
                'value'      => (float) ($counts[$s->id]->v ?? 0),
                'conversion' => round($count / $first * 100, 1),
            ];
        }

        return $rows;
    }

    /**
     * Weighted sales forecast: open deals' amount * probability, grouped by
     * expected-close month for the next 6 months.
     */
    public function forecast(): array
    {
        $rows = $this->scopeCreated(CrmDeal::where('status', 'open'))
            ->whereNotNull('expected_close_date')
            ->select(
                DB::raw("to_char(expected_close_date, 'YYYY-MM') as month"),
                DB::raw('coalesce(sum(amount),0) as gross'),
                DB::raw('coalesce(sum(amount * probability / 100.0),0) as weighted'),
                DB::raw('count(*) as c')
            )
            ->groupBy('month')->orderBy('month')->get();

        return $rows->map(fn ($r) => [
            'month'    => $r->month,
            'gross'    => round((float) $r->gross, 2),
            'weighted' => round((float) $r->weighted, 2),
            'count'    => (int) $r->c,
        ])->toArray();
    }

    /**
     * Revenue attribution: won value grouped by lead source.
     */
    public function attribution(): array
    {
        $rows = $this->scopeClosed(CrmDeal::where('status', 'won'))
            ->select(DB::raw("coalesce(nullif(source,''),'Unknown') as source"), DB::raw('coalesce(sum(amount),0) as v'), DB::raw('count(*) as c'))
            ->groupBy('source')->orderByDesc('v')->get();

        return $rows->map(fn ($r) => ['source' => $r->source, 'value' => round((float) $r->v, 2), 'count' => (int) $r->c])->toArray();
    }

    /**
     * Win/loss analysis with lost-reason breakdown.
     */
    public function winLoss(): array
    {
        $won  = (clone $this->scopeClosed(CrmDeal::where('status', 'won')))->count();
        $lost = (clone $this->scopeClosed(CrmDeal::where('status', 'lost')))->count();

        $reasons = $this->scopeClosed(CrmDeal::where('status', 'lost'))
            ->select(DB::raw("coalesce(nullif(lost_reason,''),'Unspecified') as reason"), DB::raw('count(*) as c'))
            ->groupBy('reason')->orderByDesc('c')->get()
            ->map(fn ($r) => ['reason' => $r->reason, 'count' => (int) $r->c])->toArray();

        return [
            'won'      => $won,
            'lost'     => $lost,
            'win_rate' => ($won + $lost) > 0 ? round($won / ($won + $lost) * 100, 1) : 0.0,
            'reasons'  => $reasons,
        ];
    }

    /**
     * Sales leaderboard: won count + value per owner.
     */
    public function leaderboard(): array
    {
        $rows = $this->scopeClosed(CrmDeal::where('status', 'won'))
            ->select('owner_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(amount),0) as v'))
            ->groupBy('owner_id')->orderByDesc('v')->limit(10)->get();

        return $rows->map(function ($r) {
            $name = optional(\App\Core\User\Models\User::with('person')->find($r->owner_id))->person?->first_name;
            return ['owner' => $name ?: ('User #' . $r->owner_id), 'won' => (int) $r->c, 'value' => round((float) $r->v, 2)];
        })->toArray();
    }

    /** Report keys available for the reports page, exports and scheduling. */
    public static function reportKeys(): array
    {
        return [
            'funnel'           => 'Pipeline Funnel',
            'forecast'         => 'Sales Forecast',
            'attribution'      => 'Revenue by Source',
            'leaderboard'      => 'Sales Leaderboard',
            'winloss'          => 'Win / Loss',
            'lost_deals'       => 'Lost Deals (with AI advice)',
            'open_deals'       => 'Open Deals',
            'won_deals'        => 'Won Deals',
            'leads'            => 'Leads',
            'calls'            => 'Calls by Rep',
            'campaigns'        => 'Campaign Performance',
            'user_performance' => 'User Performance (vs target)',
            'targets'          => 'Targets vs Actuals (weekly & monthly)',
            'exec_performance' => 'Executive Summary',
            'complaints'       => 'Complaints (with AI recommendations)',
            'complaints_weekly'=> 'Complaints — weekly summary',
            'surveys'          => 'Customer Surveys (NPS / CSAT / response rate)',
        ];
    }

    /**
     * Flat dataset for BI / CSV export / scheduling by report key.
     *
     * @return array{0:array<string>, 1:array<array>}  [headers, rows]
     */
    public function dataset(string $type): array
    {
        return match ($type) {
            'funnel'      => [['stage', 'count', 'value', 'conversion_pct'], array_map(fn ($r) => [$r['stage'], $r['count'], $r['value'], $r['conversion']], $this->funnel())],
            'forecast'    => [['month', 'gross', 'weighted', 'count'], array_map(fn ($r) => [$r['month'], $r['gross'], $r['weighted'], $r['count']], $this->forecast())],
            'attribution' => [['source', 'value', 'count'], array_map(fn ($r) => [$r['source'], $r['value'], $r['count']], $this->attribution())],
            'leaderboard' => [['owner', 'won', 'value'], array_map(fn ($r) => [$r['owner'], $r['won'], $r['value']], $this->leaderboard())],
            'winloss'     => $this->winLossDataset(),
            'lost_deals'  => $this->lostDealsDataset(),
            'open_deals'  => $this->dealsDataset('open'),
            'won_deals'   => $this->dealsDataset('won'),
            'leads'       => $this->leadsDataset(),
            'calls'       => $this->callsDataset(),
            'campaigns'   => $this->campaignsDataset(),
            'user_performance' => $this->userPerformanceDataset(),
            'targets'          => $this->targetsDataset(),
            'exec_performance' => $this->execPerformanceDataset(),
            'complaints'        => $this->complaintsDataset(),
            'complaints_weekly' => $this->complaintsWeeklyDataset(),
            'surveys'           => $this->surveysDataset(),
            default       => throw new \InvalidArgumentException("Unknown report: {$type}"),
        };
    }

    /** Per-rep weekly AND monthly revenue actual vs target (+ % of each). */
    private function targetsDataset(): array
    {
        $perf = app(\App\Modules\CRM\Services\CrmPerformanceService::class);

        $userIds = DB::table('crm_targets')->distinct()->pluck('user_id');
        if ($this->owners !== null) {
            $userIds = $userIds->intersect($this->owners);
        }

        $rows = [];
        foreach ($userIds as $id) {
            $name = optional(\App\Core\User\Models\User::with('person')->find($id))->person?->first_name ?: ('User #'.$id);
            $w = collect($perf->scorecard($id, 'weekly')['metrics'])->firstWhere('metric', 'revenue');
            $m = collect($perf->scorecard($id, 'monthly')['metrics'])->firstWhere('metric', 'revenue');
            $rows[] = [
                $name,
                round($w['actual'] ?? 0, 2), round($w['target'] ?? 0, 2), ($w['percent'] ?? null) === null ? '—' : $w['percent'].'%',
                round($m['actual'] ?? 0, 2), round($m['target'] ?? 0, 2), ($m['percent'] ?? null) === null ? '—' : $m['percent'].'%',
            ];
        }

        return [
            ['rep', 'weekly_actual', 'weekly_target', 'weekly_%', 'monthly_actual', 'monthly_target', 'monthly_%'],
            $rows,
        ];
    }

    /** Customer surveys with response rate, NPS and CSAT. */
    private function surveysDataset(): array
    {
        $svc  = app(\App\Modules\CRM\Services\CrmSurveyService::class);
        $rows = \App\Modules\CRM\Models\CrmSurvey::withCount(['recipients', 'responses'])
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest()->limit(2000)->get()
            ->map(function ($s) use ($svc) {
                $r = $svc->results($s);
                return [
                    $s->name, $s->category, $s->status,
                    $r['recipients'], $r['completed'], $r['response_rate'].'%',
                    $r['nps'] ?? '', $r['csat_avg'] ?? '',
                    $s->deadline?->toDateString() ?? '',
                ];
            })->all();

        return [['survey', 'category', 'status', 'recipients', 'responses', 'response_rate', 'nps', 'csat_avg', 'deadline'], $rows];
    }

    /** Complaints list with status, severity, who it's about and AI recommendations. */
    private function complaintsDataset(): array
    {
        $rows = \App\Modules\CRM\Models\CrmComplaint::with(['assignee.person', 'againstEmployee.person'])
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->latest()->limit(5000)->get()
            ->map(function ($c) {
                $recs = collect($c->ai_recommendations ?: [])->flatMap(fn ($v) => (array) $v)->take(5)->implode('; ');
                return [
                    $c->reference, $c->type, $c->severity ?? '', $c->category ?? '', $c->status,
                    $c->subject, $c->customer_name ?? '',
                    $c->againstEmployee?->person?->first_name ?? $c->against_name ?? '',
                    $c->assignee?->person?->first_name ?? '',
                    $c->created_at?->toDateString() ?? '',
                    $c->resolved_at?->toDateString() ?? '',
                    $c->escalated ? 'yes' : 'no',
                    $recs,
                ];
            })->all();

        return [
            ['reference', 'type', 'severity', 'category', 'status', 'subject', 'customer', 'against', 'handler', 'logged', 'resolved', 'escalated', 'ai_recommendations'],
            $rows,
        ];
    }

    /** Weekly complaint counts with status & severity breakdown (last 12 weeks). */
    private function complaintsWeeklyDataset(): array
    {
        $rows = \App\Modules\CRM\Models\CrmComplaint::where('type', 'complaint')
            ->where('created_at', '>=', now()->subWeeks(12)->startOfWeek())
            ->selectRaw("to_char(created_at, 'IYYY-IW') as wk, count(*) as total")
            ->selectRaw("count(*) filter (where status in ('resolved','closed')) as resolved")
            ->selectRaw("count(*) filter (where status not in ('resolved','closed')) as open")
            ->selectRaw("count(*) filter (where severity in ('high','critical')) as high")
            ->selectRaw("count(*) filter (where escalated) as escalated")
            ->groupBy('wk')->orderBy('wk')->get();

        $out = $rows->map(fn ($r) => [
            $r->wk, (int) $r->total, (int) $r->open, (int) $r->resolved, (int) $r->high, (int) $r->escalated,
        ])->all();

        return [['week', 'total', 'open', 'resolved', 'high_critical', 'escalated'], $out];
    }

    /** Lost deals with reason, AI category and AI advice. */
    private function lostDealsDataset(): array
    {
        $rows = $this->scopeClosed(CrmDeal::where('status', 'lost'))
            ->with(['account:id,name', 'owner.person'])
            ->latest('closed_at')->limit(5000)->get()
            ->map(fn ($d) => [
                $d->title,
                $d->account?->name ?? '',
                (float) $d->amount,
                $d->owner?->person?->first_name ?? '',
                $d->closed_at?->toDateString() ?? '',
                $d->lost_reason ?? '',
                $d->lost_category ?? '',
                $d->lost_ai_advice ?? '',
            ])->all();

        return [['title', 'account', 'amount', 'owner', 'lost_on', 'reason', 'ai_category', 'ai_advice'], $rows];
    }

    /** Call (and activity) volume per rep within the range. */
    private function callsDataset(): array
    {
        $rows = $this->activityByOwner();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [$r['owner'], $r['call'], $r['email'], $r['meeting'], $r['whatsapp'], $r['sms'], $r['total']];
        }
        return [['rep', 'calls', 'emails', 'meetings', 'whatsapp', 'sms', 'total'], $out];
    }

    /** Campaign performance (sent / opened / clicked rates). */
    private function campaignsDataset(): array
    {
        $rows = DB::table('crm_campaigns')->whereNull('deleted_at')
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->orderByDesc('created_at')->limit(5000)->get()
            ->map(function ($c) {
                $sent = (int) $c->sent_count;
                $openRate  = $sent > 0 ? round($c->opened_count / $sent * 100, 1) : 0;
                $clickRate = $sent > 0 ? round($c->clicked_count / $sent * 100, 1) : 0;
                return [$c->name, $c->channel, $c->status, (int) $c->total_recipients, $sent,
                    (int) $c->opened_count, $openRate.'%', (int) $c->clicked_count, $clickRate.'%', (int) $c->failed_count];
            })->all();

        return [['campaign', 'channel', 'status', 'recipients', 'sent', 'opened', 'open_rate', 'clicked', 'click_rate', 'failed'], $rows];
    }

    /** Per-rep performance vs monthly revenue target. */
    private function userPerformanceDataset(): array
    {
        $activity = collect($this->activityByOwner())->keyBy('owner_id');

        $won = $this->scopeClosed(CrmDeal::where('status', 'won'))
            ->select('owner_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(amount),0) as v'))
            ->groupBy('owner_id')->get()->keyBy('owner_id');

        $leads = $this->scopeCreated(CrmLead::query())
            ->select('owner_id', DB::raw('count(*) as c'))->groupBy('owner_id')->get()->keyBy('owner_id');

        $targets = DB::table('crm_targets')->where('period', 'monthly')->where('metric', 'revenue')
            ->pluck('target_value', 'user_id');

        $ownerIds = collect($activity->keys())
            ->merge($won->keys())->merge($leads->keys())->unique()->filter()->values();

        $rows = [];
        foreach ($ownerIds as $id) {
            $name    = optional(\App\Core\User\Models\User::with('person')->find($id))->person?->first_name ?: ('User #'.$id);
            $revenue = (float) ($won[$id]->v ?? 0);
            $target  = (float) ($targets[$id] ?? 0);
            $rows[] = [
                $name,
                (int) ($activity[$id]['call'] ?? 0),
                (int) ($activity[$id]['email'] ?? 0),
                (int) ($activity[$id]['meeting'] ?? 0),
                (int) ($leads[$id]->c ?? 0),
                (int) ($won[$id]->c ?? 0),
                round($revenue, 2),
                round($target, 2),
                $target > 0 ? round($revenue / $target * 100).'%' : '—',
            ];
        }

        return [['rep', 'calls', 'emails', 'meetings', 'leads', 'deals_won', 'revenue', 'revenue_target', 'attainment'], $rows];
    }

    /** One-row executive summary for the period. */
    private function execPerformanceDataset(): array
    {
        $s  = $this->summary();
        $wl = $this->winLoss();

        $newLeads  = $this->scopeCreated(CrmLead::query())->count();
        $converted = CrmLead::where('status', 'converted')
            ->when($this->from, fn ($q) => $q->whereDate('updated_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('updated_at', '<=', $this->to))
            ->count();

        $rows = [
            ['Total leads', $newLeads],
            ['Converted leads', $converted],
            ['Lead conversion rate %', $newLeads > 0 ? round($converted / $newLeads * 100, 1) : 0],
            ['Open deals', $s['open_count']],
            ['Open pipeline value', $s['open_value']],
            ['Weighted forecast', $s['weighted_pipeline']],
            ['Deals won', $s['won_count']],
            ['Won value', $s['won_value']],
            ['Deals lost', $wl['lost']],
            ['Win rate %', $s['win_rate']],
            ['Average deal size', $s['avg_deal_size']],
        ];

        return [['metric', 'value'], $rows];
    }

    /**
     * Activity counts per owner (by type) within the range.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activityByOwner(): array
    {
        $rows = \App\Modules\CRM\Models\CrmActivity::query()
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->select('owner_id', 'type', DB::raw('count(*) as c'))
            ->groupBy('owner_id', 'type')->get();

        $byOwner = [];
        foreach ($rows as $r) {
            $byOwner[$r->owner_id] ??= ['owner_id' => $r->owner_id, 'call' => 0, 'email' => 0, 'meeting' => 0, 'whatsapp' => 0, 'sms' => 0, 'total' => 0];
            $byOwner[$r->owner_id][$r->type] = (int) $r->c;
            $byOwner[$r->owner_id]['total'] += (int) $r->c;
        }

        foreach ($byOwner as $id => &$row) {
            $row['owner'] = optional(\App\Core\User\Models\User::with('person')->find($id))->person?->first_name ?: ('User #'.$id);
        }

        return array_values($byOwner);
    }

    private function winLossDataset(): array
    {
        $wl = $this->winLoss();
        $rows = [['Won', $wl['won']], ['Lost', $wl['lost']], ['Win rate %', $wl['win_rate']]];
        foreach ($wl['reasons'] as $r) {
            $rows[] = ['Lost: ' . $r['reason'], $r['count']];
        }
        return [['metric', 'value'], $rows];
    }

    private function dealsDataset(string $status): array
    {
        $rows = $this->scopeCreated(CrmDeal::where('status', $status))
            ->with(['account:id,name', 'stage:id,name'])
            ->latest()->limit(5000)->get()
            ->map(fn ($d) => [
                $d->title, $d->account?->name ?? '', $d->stage?->name ?? '', (float) $d->amount,
                $d->currency, $d->probability, $d->expected_close_date?->toDateString() ?? '',
            ])->all();

        return [['title', 'account', 'stage', 'amount', 'currency', 'probability', 'expected_close'], $rows];
    }

    private function leadsDataset(): array
    {
        $rows = $this->scopeCreated(CrmLead::query())
            ->latest()->limit(5000)->get()
            ->map(fn ($l) => [
                trim($l->first_name . ' ' . $l->last_name), $l->company ?? '', $l->email ?? '',
                $l->source ?? '', $l->status, $l->score,
            ])->all();

        return [['name', 'company', 'email', 'source', 'status', 'score'], $rows];
    }

    // ── BI analytics (time series + breakdowns) ────────────────────────────────

    /**
     * Deals created per month (count + value) over the last 12 months.
     */
    public function dealsCreatedTrend(int $months = 12): array
    {
        return $this->monthlySeries(
            $this->applyOwners(CrmDeal::query()),
            'created_at',
            $months,
            fn ($q) => $q->select(
                DB::raw("to_char(created_at, 'YYYY-MM') as m"),
                DB::raw('count(*) as c'),
                DB::raw('coalesce(sum(amount),0) as v')
            )
        );
    }

    /**
     * Won value per month (close date) over the last 12 months.
     */
    public function wonTrend(int $months = 12): array
    {
        return $this->monthlySeries(
            $this->applyOwners(CrmDeal::where('status', 'won')->whereNotNull('closed_at')),
            'closed_at',
            $months,
            fn ($q) => $q->select(
                DB::raw("to_char(closed_at, 'YYYY-MM') as m"),
                DB::raw('count(*) as c'),
                DB::raw('coalesce(sum(amount),0) as v')
            )
        );
    }

    /** New leads per month over the last 12 months. */
    public function leadsTrend(int $months = 12): array
    {
        return $this->monthlySeries(
            $this->applyOwners(CrmLead::query()),
            'created_at',
            $months,
            fn ($q) => $q->select(DB::raw("to_char(created_at, 'YYYY-MM') as m"), DB::raw('count(*) as c'), DB::raw('0 as v'))
        );
    }

    /** Lead count by source. */
    public function leadSourceMix(): array
    {
        return $this->applyOwners(CrmLead::query())
            ->select(DB::raw("coalesce(nullif(source,''),'Unknown') as source"), DB::raw('count(*) as c'))
            ->groupBy('source')->orderByDesc('c')->limit(10)->get()
            ->map(fn ($r) => ['source' => $r->source, 'count' => (int) $r->c])->toArray();
    }

    /** Activity volume by type. */
    public function activityVolume(): array
    {
        return $this->applyOwners(\App\Modules\CRM\Models\CrmActivity::query())
            ->select('type', DB::raw('count(*) as c'))
            ->groupBy('type')->orderByDesc('c')->get()
            ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->c])->toArray();
    }

    /** Monthly calls & emails counts over the last N months. */
    public function callsEmailsTrend(int $months = 12): array
    {
        $rows = $this->applyOwners(\App\Modules\CRM\Models\CrmActivity::query())
            ->whereIn('type', ['call', 'email'])
            ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
            ->select(DB::raw("to_char(created_at, 'YYYY-MM') as m"), 'type', DB::raw('count(*) as c'))
            ->groupBy('m', 'type')->get();

        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[$r->m][$r->type] = (int) $r->c;
        }

        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $out[] = [
                'month'  => $key,
                'calls'  => $byMonth[$key]['call'] ?? 0,
                'emails' => $byMonth[$key]['email'] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Per-rep won-revenue for each month of a year — for the multi-series rep
     * performance chart. Respects the owner scope (rep=self, manager=team, etc.).
     *
     * @return array{categories: array<string>, reps: array<array{name:string, data:array<float>}>}
     */
    public function repMonthlyPerformance(?int $year = null): array
    {
        $year ??= (int) now()->year;
        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end   = (clone $start)->endOfYear();

        $rows = $this->applyOwners(CrmDeal::where('status', 'won')->whereNotNull('closed_at'))
            ->whereBetween('closed_at', [$start, $end])
            ->select('owner_id', DB::raw("to_char(closed_at, 'MM') as mm"), DB::raw('coalesce(sum(amount),0) as v'))
            ->groupBy('owner_id', 'mm')->get();

        return $this->pivotReps($rows, fn ($mm) => (int) $mm - 1, 12, [
            'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
        ]);
    }

    /**
     * Per-rep won-revenue for the last N ISO weeks.
     *
     * @return array{categories: array<string>, reps: array<array{name:string, data:array<float>}>}
     */
    public function repWeeklyPerformance(int $weeks = 12): array
    {
        $start = now()->startOfWeek()->subWeeks($weeks - 1);

        $rows = $this->applyOwners(CrmDeal::where('status', 'won')->whereNotNull('closed_at'))
            ->where('closed_at', '>=', $start)
            ->select('owner_id', DB::raw("to_char(closed_at, 'IYYY-IW') as wk"), DB::raw('coalesce(sum(amount),0) as v'))
            ->groupBy('owner_id', 'wk')->get();

        // Build the ordered list of week keys + labels.
        $keys = [];
        $labels = [];
        for ($i = 0; $i < $weeks; $i++) {
            $d = (clone $start)->addWeeks($i);
            $keys[$d->isoFormat('GGGG-WW')] = $i;
            $labels[] = 'W'.$d->isoWeek;
        }

        $index = fn ($wk) => $keys[$wk] ?? null;
        return $this->pivotRepsByKey($rows, 'wk', $index, $weeks, $labels);
    }

    /** Pivot owner/period rows into per-rep series (period via a 0-based index fn). */
    private function pivotReps($rows, callable $indexOf, int $size, array $categories): array
    {
        return $this->buildRepSeries($rows, fn ($r) => $indexOf($r->mm), $size, $categories);
    }

    private function pivotRepsByKey($rows, string $keyField, callable $indexOf, int $size, array $categories): array
    {
        return $this->buildRepSeries($rows, fn ($r) => $indexOf($r->{$keyField}), $size, $categories);
    }

    private function buildRepSeries($rows, callable $indexOf, int $size, array $categories): array
    {
        $byOwner = [];
        foreach ($rows as $r) {
            $idx = $indexOf($r);
            if ($idx === null) continue;
            $byOwner[$r->owner_id] ??= array_fill(0, $size, 0.0);
            $byOwner[$r->owner_id][$idx] += (float) $r->v;
        }

        $reps = [];
        foreach ($byOwner as $ownerId => $data) {
            $name = optional(\App\Core\User\Models\User::with('person')->find($ownerId))->person?->first_name ?: ('User #'.$ownerId);
            $reps[] = ['name' => $name, 'data' => array_map(fn ($v) => round($v, 2), $data)];
        }

        // Heaviest performers first.
        usort($reps, fn ($a, $b) => array_sum($b['data']) <=> array_sum($a['data']));

        return ['categories' => $categories, 'reps' => $reps];
    }

    private function monthlySeries($base, string $column, int $months, callable $select): array
    {
        $rows = $select($base->where($column, '>=', now()->subMonths($months)->startOfMonth()))
            ->groupBy('m')->orderBy('m')->get()->keyBy('m');

        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = now()->subMonths($i)->format('Y-m');
            $row = $rows[$key] ?? null;
            $out[] = [
                'month' => $key,
                'count' => (int) ($row->c ?? 0),
                'value' => round((float) ($row->v ?? 0), 2),
            ];
        }
        return $out;
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function weightedPipeline(): float
    {
        return (float) $this->scopeCreated(CrmDeal::where('status', 'open'))
            ->select(DB::raw('coalesce(sum(amount * probability / 100.0),0) as w'))->value('w');
    }

    private function scopeCreated($query)
    {
        if ($this->from) $query->whereDate('created_at', '>=', $this->from);
        if ($this->to)   $query->whereDate('created_at', '<=', $this->to);
        $this->applyOwners($query);
        return $query;
    }

    private function scopeClosed($query)
    {
        if ($this->from) $query->whereDate('closed_at', '>=', $this->from);
        if ($this->to)   $query->whereDate('closed_at', '<=', $this->to);
        $this->applyOwners($query);
        return $query;
    }
}
