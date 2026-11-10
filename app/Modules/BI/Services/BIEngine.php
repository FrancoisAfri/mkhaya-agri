<?php

namespace App\Modules\BI\Services;

use App\Modules\BI\Support\BIAccess;
use App\Modules\BI\Support\DatasetRegistry;
use App\Modules\Tenancy\Services\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The measure engine behind every BI visual (copied in shape from Mkhaya Sales, made generic).
 *
 * A widget names a dataset, measures, a dimension and a period; this builds the SQL from the
 * dataset definition alone, binds every value, and returns the numbers in the shape the visual
 * library (resources/js/Components/BI/visuals.ts) draws. Nothing is computed in the browser.
 */
class BIEngine
{
    /** Visual type → engine kind. Mirrors visuals.ts; a template may only use these types. */
    public const TYPE_KIND = [
        'kpi_card' => 'kpi', 'kpi_sparkline' => 'kpi', 'multi_kpi' => 'kpi', 'gauge' => 'kpi', 'linear_gauge' => 'kpi', 'progress' => 'kpi',
        'traffic_light' => 'kpi', 'variance_card' => 'kpi',
        'column_clustered' => 'category', 'bar_clustered' => 'category', 'bar_ranked' => 'category', 'diverging_bar' => 'category', 'lollipop' => 'category',
        'pie' => 'category', 'donut' => 'category', 'rose' => 'category', 'funnel' => 'category', 'radar' => 'category',
        'column_simple' => 'time', 'column_stacked' => 'time', 'column_100' => 'time', 'line' => 'time', 'line_multi' => 'time', 'line_smooth' => 'time',
        'line_step' => 'time', 'area' => 'time', 'area_stacked' => 'time', 'area_100' => 'time', 'running_total' => 'time', 'moving_average' => 'time',
        'combo_column_line' => 'time', 'dual_axis' => 'time', 'stacked_column_line' => 'time', 'area_line' => 'time', 'variance_table' => 'time',
        'bar_stacked' => 'matrix', 'bar_100' => 'matrix', 'small_multiples_bar' => 'matrix', 'heatmap_matrix' => 'matrix', 'matrix_table' => 'matrix',
        'treemap' => 'treemap', 'sunburst' => 'treemap', 'sankey' => 'treemap',
        'pareto' => 'pareto', 'waterfall' => 'waterfall', 'calendar_heatmap' => 'calendar',
        'table' => 'table', 'ranking_table' => 'table',
        'smart_narrative' => 'narrative', 'ai_insight' => 'narrative', 'text_box' => 'none',
    ];

    public const PERIODS = ['dashboard', 'mtd', 'last_month', 'qtd', 'ytd', 'last_year', 'last_12_months', 'last_24_months', 'all'];
    public const GRAINS = ['day', 'week', 'month', 'quarter', 'year'];

    public function __construct(private CurrentCompany $company) {}

    /** Run one widget. $filters: from, to, dims => [dimension_key => value]. */
    public function run(array $cfg, array $filters, $user, bool $gate = true): array
    {
        $cfg = $this->validate($cfg);
        if ($cfg['kind'] === 'none') {
            return ['kind' => 'none'];
        }
        if ($gate && ($why = BIAccess::blocked($cfg['dataset'], $user))) {
            return ['kind' => $cfg['kind'], 'locked' => $why];
        }
        $ds = DatasetRegistry::get($cfg['dataset']);
        // A date range picked on the dashboard wins over each widget's own period (as a page-level
        // date slicer does) — except balances, which are always "everything to date".
        if (! empty($filters['override']) && $cfg['period'] !== 'all') {
            $cfg['period'] = 'dashboard';
        }
        [$from, $to] = $this->period($cfg['period'], $filters);
        $f = ['from' => $from, 'to' => $to, 'dims' => array_intersect_key((array) ($filters['dims'] ?? []), $ds['dimensions'])];
        $key = 'bi:' . $this->company->id() . ':' . md5(json_encode([$cfg, $f]));

        return Cache::remember($key, 120, fn () => ['kind' => $cfg['kind'], 'dataset' => $cfg['dataset'],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()]] + match ($cfg['kind']) {
                'kpi' => $this->kpi($ds, $cfg, $f),
                'time' => $this->time($ds, $cfg, $f),
                'category', 'pareto' => $this->category($ds, $cfg, $f),
                'table' => $this->category($ds, $cfg, $f, true),
                'matrix' => $this->matrix($ds, $cfg, $f),
                'treemap' => $this->tree($ds, $cfg, $f),
                'calendar' => $this->time($ds, ['grain' => 'day'] + $cfg, $f),
                'waterfall' => $this->waterfall($ds, $cfg, $f),
                'narrative' => $this->narrative($ds, $cfg, $f),
            });
    }

    // ── kinds ───────────────────────────────────────────────────────────────────────────────────

    private function kpi(array $ds, array $cfg, array $f): array
    {
        $values = $this->values($ds, $cfg['measures'], $f, []);
        $m0 = $this->baseMeasures($ds, $cfg['measures'])[0];
        $long = $f['from']->diffInDays($f['to']) > 62;
        $spark = $this->aggregate($ds, [$m0], $f, [$this->bucketSql($ds, $long ? 'month' : 'day')]);

        return ['values' => $values[0] ?? [], 'spark' => collect($spark)->sortBy('_d0')->pluck($m0)->map(fn ($v) => round((float) $v, 2))->values()->all()]
            + $this->meta($ds, $cfg['measures']);
    }

    private function time(array $ds, array $cfg, array $f): array
    {
        $grain = $cfg['grain'];
        $bucket = $this->bucketSql($ds, $grain);
        $cats = $this->buckets($f['from'], $f['to'], $grain);
        if ($cfg['series'] && $cfg['series'] !== 'month') {
            $m = $this->baseMeasures($ds, $cfg['measures'])[0];
            $rows = collect($this->aggregate($ds, [$m], $f, [$bucket, $this->dimSql($ds, $cfg['series'])]));
            $cell = $rows->mapWithKeys(fn ($r) => [$this->bucketKey($r['_d0'], $grain) . '|' . (string) $r['_d1'] => (float) ($r[$m] ?? 0)]);
            $names = $rows->groupBy(fn ($r) => (string) $r['_d1'])->map(fn ($g) => $g->sum($m))->sortDesc()->keys()->take($cfg['top'] ?: 6);
            $series = $names->map(fn ($n) => ['key' => (string) $n, 'name' => (string) ($n === '' ? '—' : $n), 'measure' => $m,
                'data' => array_map(fn ($c) => round($cell[$c . '|' . $n] ?? 0, 2), array_keys($cats))])->values()->all();

            return $this->trimEmptyStart(['categories' => array_values($cats), 'series' => $series] + $this->meta($ds, [$m]), $cfg);
        }
        $rows = collect($this->values($ds, $cfg['measures'], $f, [$bucket]))->keyBy(fn ($r) => $this->bucketKey($r['_d0'], $grain));

        return $this->trimEmptyStart(['categories' => array_values($cats), 'series' => collect($cfg['measures'])->map(fn ($m) => ['key' => $m, 'name' => $this->label($ds, $m), 'measure' => $m,
            'data' => array_map(fn ($c) => isset($rows[$c]) ? round((float) ($rows[$c][$m] ?? 0), 2) : 0, array_keys($cats))])->all()] + $this->meta($ds, $cfg['measures']), $cfg);
    }

    /** An all-time series starts at 2000; drop the empty buckets before the first real figure. */
    private function trimEmptyStart(array $out, array $cfg): array
    {
        if ($cfg['period'] !== 'all' || ! $out['series']) {
            return $out;
        }
        $first = count($out['categories']);
        foreach ($out['series'] as $s) {
            foreach ($s['data'] as $i => $v) {
                if ((float) $v != 0.0) { $first = min($first, $i); break; }
            }
        }
        $first = min($first, max(0, count($out['categories']) - 1));
        if ($first > 0) {
            $out['categories'] = array_slice($out['categories'], $first);
            foreach ($out['series'] as &$s) { $s['data'] = array_slice($s['data'], $first); }
        }

        return $out;
    }

    private function category(array $ds, array $cfg, array $f, bool $table = false): array
    {
        $dim = $cfg['dimension'] ?? array_key_first($ds['dimensions']);
        $rows = collect($this->values($ds, $cfg['measures'], $f, [$this->dimSql($ds, $dim)]));
        $m0 = $cfg['measures'][0];
        $rows = $cfg['sort'] === 'chrono' ? $rows->sortBy('_d0') : ($cfg['sort'] === 'asc' ? $rows->sortBy($m0) : $rows->sortByDesc($m0));
        $top = $cfg['top'] ?: ($table ? 50 : 12);
        $shown = $rows->take($top)->values();
        if ($cfg['other'] && $rows->count() > $top) {
            $rest = $rows->slice($top);
            $shown->push(['_d0' => 'Other'] + collect($cfg['measures'])->mapWithKeys(fn ($m) => [$m => $rest->sum($m)])->all());
        }
        $out = ['dimension' => $dim, 'categories' => $shown->pluck('_d0')->map(fn ($v) => (string) ($v ?? '—'))->all(),
            'keys' => $shown->pluck('_d0')->map(fn ($v) => $v === 'Other' ? 'other' : $v)->all(),
            'series' => collect($cfg['measures'])->map(fn ($m) => ['key' => $m, 'name' => $this->label($ds, $m), 'measure' => $m,
                'data' => $shown->map(fn ($r) => round((float) ($r[$m] ?? 0), 2))->all()])->all()] + $this->meta($ds, $cfg['measures']);
        if ($cfg['kind'] === 'pareto') {
            $sum = max(0.0001, $rows->sum($m0));
            $run = 0;
            $out['cumulative'] = $shown->map(function ($r) use (&$run, $sum, $m0) { $run += (float) $r[$m0]; return round($run / $sum * 100, 1); })->all();
        }
        if ($table) {
            $out['total'] = round((float) $rows->sum($m0), 2);
            $out['count'] = $rows->count();
        }

        return $out;
    }

    private function matrix(array $ds, array $cfg, array $f): array
    {
        $dim = $cfg['dimension'] ?? array_key_first($ds['dimensions']);
        $m = $this->baseMeasures($ds, $cfg['measures'])[0];
        $colSql = in_array($cfg['series'], [null, 'month'], true) ? $this->bucketSql($ds, 'month') : $this->dimSql($ds, $cfg['series']);
        $rows = collect($this->aggregate($ds, [$m], $f, [$this->dimSql($ds, $dim), $colSql]));
        $isMonth = in_array($cfg['series'], [null, 'month'], true);
        $rowKeys = $rows->groupBy('_d0')->map(fn ($g) => $g->sum($m))->sortDesc()->keys()->take($cfg['top'] ?: 12)->values();
        $colKeys = $isMonth ? collect(array_keys($this->buckets($f['from'], $f['to'], 'month')))
            : $rows->groupBy('_d1')->map(fn ($g) => $g->sum($m))->sortDesc()->keys()->take(12)->values();
        $cells = [];
        foreach ($rows as $r) {
            $ri = $rowKeys->search($r['_d0']);
            $ci = $colKeys->search($isMonth ? $this->bucketKey($r['_d1'], 'month') : $r['_d1']);
            if ($ri !== false && $ci !== false) {
                $cells[] = [$ci, $ri, round((float) $r[$m], 2)];
            }
        }
        $cols = $isMonth ? array_values($this->buckets($f['from'], $f['to'], 'month')) : $colKeys->map(fn ($c) => (string) $c)->all();

        return ['dimension' => $dim, 'rows' => $rowKeys->map(fn ($r) => (string) ($r ?? '—'))->all(), 'columns' => $cols, 'cells' => $cells,
            'max' => collect($cells)->max(2) ?? 0] + $this->meta($ds, [$m]);
    }

    private function tree(array $ds, array $cfg, array $f): array
    {
        $dim = $cfg['dimension'] ?? array_key_first($ds['dimensions']);
        $m = $this->baseMeasures($ds, $cfg['measures'])[0];
        $child = $cfg['series'] ?: $dim;
        $rows = collect($this->aggregate($ds, [$m], $f, [$this->dimSql($ds, $dim), $this->dimSql($ds, $child)]));
        $tree = $rows->groupBy('_d0')->map(fn ($g, $k) => ['name' => (string) ($k ?: '—'), 'value' => round((float) $g->sum($m), 2),
            'children' => $g->sortByDesc($m)->take($cfg['top'] ?: 10)->map(fn ($r) => ['name' => (string) ($r['_d1'] ?? '—'), 'value' => round((float) $r[$m], 2)])->values()->all()])
            ->sortByDesc('value')->take(12)->values()->all();

        return ['dimension' => $dim, 'tree' => $tree] + $this->meta($ds, [$m]);
    }

    private function waterfall(array $ds, array $cfg, array $f): array
    {
        $dim = $cfg['dimension'] ?? array_key_first($ds['dimensions']);
        $m = $this->baseMeasures($ds, $cfg['measures'])[0];
        $now = collect($this->aggregate($ds, [$m], $f, [$this->dimSql($ds, $dim)]))->pluck($m, '_d0');
        $before = collect($this->aggregate($ds, [$m], $f, [$this->dimSql($ds, $dim)], 1))->pluck($m, '_d0');
        $keys = $now->keys()->merge($before->keys())->unique();
        $changes = $keys->map(fn ($k) => ['name' => (string) ($k ?? '—'), 'value' => round((float) ($now[$k] ?? 0) - (float) ($before[$k] ?? 0), 2)])->sortByDesc(fn ($c) => abs($c['value']));
        $top = $changes->take($cfg['top'] ?: 8)->values();
        $rest = round($changes->slice($cfg['top'] ?: 8)->sum('value'), 2);
        if ($rest != 0) {
            $top->push(['name' => 'Everything else', 'value' => $rest]);
        }

        return ['start' => ['name' => 'Same period last year', 'value' => round((float) $before->sum(), 2)], 'steps' => $top->all(),
            'end' => ['name' => 'This period', 'value' => round((float) $now->sum(), 2)]] + $this->meta($ds, [$m]);
    }

    private function narrative(array $ds, array $cfg, array $f): array
    {
        $measures = $this->baseMeasures($ds, $cfg['measures'] ?: array_slice(array_keys($ds['measures']), 0, 3));
        $lines = [];
        foreach (array_slice($measures, 0, 3) as $m) {
            $v = $this->values($ds, [$m, 'growth_pct'], $f, [])[0] ?? [];
            $g = $v['growth_pct'] ?? null;
            $lines[] = sprintf('%s: %s%s.', $this->label($ds, $m), $this->say($v[$m] ?? 0, $ds['measures'][$m]['format'] ?? 'number'),
                $g === null ? '' : sprintf(' (%s%s%% on the same period last year)', $g >= 0 ? '+' : '', number_format($g, 1)));
        }
        if ($dim = $cfg['dimension'] ?? array_key_first($ds['dimensions'])) {
            $m = $measures[0];
            $topRow = collect($this->aggregate($ds, [$m], $f, [$this->dimSql($ds, $dim)]))->sortByDesc($m)->first();
            if ($topRow && (float) $topRow[$m] > 0) {
                $lines[] = sprintf('Largest %s: %s, at %s.', strtolower($ds['dimensions'][$dim]['label']), $topRow['_d0'] ?? '—', $this->say($topRow[$m], $ds['measures'][$m]['format'] ?? 'number'));
            }
        }

        return ['lines' => $lines] + $this->meta($ds, $measures);
    }

    // ── SQL ─────────────────────────────────────────────────────────────────────────────────────

    /** Rows for the given measures (comparison and ratio measures resolved), grouped by $dims. */
    private function values(array $ds, array $measures, array $f, array $dims): array
    {
        $base = $this->baseMeasures($ds, $measures);
        $rows = $this->aggregate($ds, $base, $f, $dims);
        $needLy = (bool) array_intersect($measures, ['last_year', 'growth_pct']);
        $dimKey = fn ($r) => json_encode(array_map('strval', array_values(array_filter($r, fn ($k) => str_starts_with($k, '_d'), ARRAY_FILTER_USE_KEY))));
        $ly = $needLy ? collect($this->aggregate($ds, [$base[0]], $f, $dims, 1))->keyBy($dimKey) : collect();
        if (! $dims && ! $rows) {
            $rows = [array_fill_keys($base, 0)];
        }

        return array_map(function ($r) use ($base, $needLy, $ly, $dimKey) {
            if ($needLy) {
                $prev = (float) ($ly[$dimKey($r)][$base[0]] ?? 0);
                $r['last_year'] = round($prev, 2);
                $r['growth_pct'] = $prev == 0.0 ? null : round(((float) $r[$base[0]] - $prev) / abs($prev) * 100, 1);
            }

            return $r;
        }, $rows);
    }

    /**
     * One grouped query. $dims are SQL expressions from the dataset (never user text); every
     * filter value is bound. $shiftYears moves the period back for comparisons.
     */
    private function aggregate(array $ds, array $measures, array $f, array $dims, int $shiftYears = 0): array
    {
        $company = $this->company->id();
        if (! $company) {
            throw new InvalidArgumentException('BI needs an active company.');
        }
        $sel = [];
        foreach (array_values($dims) as $i => $d) {
            $sel[] = "{$d} as _d{$i}";
        }
        // A ratio measure (e.g. average = revenue / invoices) is computed from its parts after the query.
        $columns = collect($measures)->flatMap(fn ($m) => $ds['measures'][$m]['ratio'] ?? [$m])->unique()->values();
        foreach ($columns as $m) {
            $sql = $ds['measures'][$m]['sql'] ?? null;
            if (! $sql) {
                continue;
            }
            $sel[] = "{$sql} as \"{$m}\"";
        }
        $where = ["{$ds['company']} = ?", "{$ds['date']} >= ?", "{$ds['date']} < ?"];
        $bind = [$company, $f['from']->subYears($shiftYears)->toDateString(), $f['to']->subYears($shiftYears)->addDay()->toDateString()];
        if ($ds['where']) {
            $where[] = "({$ds['where']})";
        }
        foreach ($f['dims'] as $k => $v) {
            if (isset($ds['dimensions'][$k]) && $v !== null && $v !== '') {
                $where[] = "({$ds['dimensions'][$k]['sql']})::text = ?";
                $bind[] = (string) $v;
            }
        }
        $sql = 'select ' . implode(', ', $sel) . " from {$ds['from']} where " . implode(' and ', $where)
            . ($dims ? ' group by ' . implode(', ', range(1, count($dims))) : '');

        $ratios = array_filter($measures, fn ($m) => isset($ds['measures'][$m]['ratio']));

        return array_map(function ($r) use ($ds, $ratios) {
            $r = (array) $r;
            foreach ($ratios as $m) {
                [$a, $b] = $ds['measures'][$m]['ratio'];
                $r[$m] = (float) ($r[$b] ?? 0) == 0.0 ? 0 : round((float) ($r[$a] ?? 0) / (float) $r[$b] * ($ds['measures'][$m]['scale'] ?? 1), 4);
            }

            return $r;
        }, DB::select($sql, $bind));
    }

    private function dimSql(array $ds, ?string $dim): string
    {
        if (! $dim || ! isset($ds['dimensions'][$dim])) {
            throw new InvalidArgumentException("Unknown dimension '{$dim}' on {$ds['key']}.");
        }

        return $ds['dimensions'][$dim]['sql'];
    }

    private function bucketSql(array $ds, string $grain): string
    {
        return "date_trunc('" . (in_array($grain, self::GRAINS, true) ? $grain : 'month') . "', {$ds['date']})::date";
    }

    private function bucketKey($date, string $grain): string
    {
        $d = CarbonImmutable::parse($date);

        return match ($grain) { 'day' => $d->toDateString(), 'week' => $d->startOfWeek()->toDateString(), 'quarter' => $d->firstOfQuarter()->toDateString(), 'year' => $d->startOfYear()->toDateString(), default => $d->startOfMonth()->toDateString() };
    }

    /** Every bucket between the dates, so empty months still show as zero. key => label */
    private function buckets(CarbonImmutable $from, CarbonImmutable $to, string $grain): array
    {
        $out = [];
        $d = CarbonImmutable::parse($this->bucketKey($from, $grain));
        for ($i = 0; $d <= $to && $i < 800; $i++) {
            $out[$d->toDateString()] = match ($grain) {
                'day' => $d->toDateString(), 'week' => $d->format('d M'), 'quarter' => 'Q' . $d->quarter . ' ' . $d->year,
                'year' => (string) $d->year, default => $d->format('M Y'),
            };
            $d = match ($grain) { 'day' => $d->addDay(), 'week' => $d->addWeek(), 'quarter' => $d->addQuarter(), 'year' => $d->addYear(), default => $d->addMonthNoOverflow() };
        }

        return $out;
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    /** The dataset's own measures named in $measures (comparisons dropped); at least one. */
    private function baseMeasures(array $ds, array $measures): array
    {
        $base = array_values(array_filter($measures, fn ($m) => isset($ds['measures'][$m])));

        return $base ?: [array_key_first($ds['measures'])];
    }

    private function meta(array $ds, array $measures): array
    {
        $fmt = fn ($m) => $m === 'growth_pct' ? 'percent' : ($m === 'last_year' ? ($ds['measures'][$this->baseMeasures($ds, $measures)[0]]['format'] ?? 'number') : ($ds['measures'][$m]['format'] ?? 'number'));

        return ['formats' => collect($measures)->mapWithKeys(fn ($m) => [$m => $fmt($m)])->all(),
            'labels' => collect($measures)->mapWithKeys(fn ($m) => [$m => $this->label($ds, $m)])->all()];
    }

    private function label(array $ds, string $m): string
    {
        return $ds['measures'][$m]['label'] ?? DatasetRegistry::COMPARE[$m] ?? $m;
    }

    private function say($v, string $format): string
    {
        $v = (float) $v;

        return match ($format) { 'currency' => 'R ' . number_format($v, 0, '.', ' '), 'percent' => number_format($v, 1) . '%', default => number_format($v, $v == floor($v) ? 0 : 1, '.', ' ') };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function period(string $period, array $filters): array
    {
        $now = CarbonImmutable::today();

        return match ($period) {
            'mtd' => [$now->startOfMonth(), $now],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'qtd' => [$now->firstOfQuarter(), $now],
            'ytd' => [$now->startOfYear(), $now],
            'last_year' => [$now->subYear()->startOfYear(), $now->subYear()->endOfYear()->startOfDay()],
            'last_12_months' => [$now->subMonths(12)->addDay(), $now],
            'last_24_months' => [$now->subMonths(24)->addDay(), $now],
            'all' => [CarbonImmutable::parse('2000-01-01'), $now],
            default => [CarbonImmutable::parse($filters['from'] ?? $now->startOfYear()), CarbonImmutable::parse($filters['to'] ?? $now)],
        };
    }

    /** Only known keys survive; everything else about a widget is ignored. */
    public function validate(array $cfg): array
    {
        $cfg = array_merge(['kind' => 'category', 'dataset' => '', 'measures' => [], 'dimension' => null, 'series' => null, 'grain' => 'month',
            'top' => 0, 'sort' => 'desc', 'other' => false, 'period' => 'dashboard'], array_intersect_key($cfg,
            array_flip(['kind', 'dataset', 'measures', 'dimension', 'series', 'grain', 'top', 'sort', 'other', 'period'])));
        if ($cfg['kind'] === 'none') {
            return $cfg;
        }
        $ds = DatasetRegistry::get((string) $cfg['dataset']);
        if (! $ds) {
            throw new InvalidArgumentException("Unknown dataset '{$cfg['dataset']}'.");
        }
        $cfg['measures'] = array_values(array_filter((array) $cfg['measures'], fn ($m) => is_string($m) && (isset($ds['measures'][$m]) || isset(DatasetRegistry::COMPARE[$m]))));
        if (! $cfg['measures'] || ! array_filter($cfg['measures'], fn ($m) => isset($ds['measures'][$m]))) {
            array_unshift($cfg['measures'], array_key_first($ds['measures']));
        }
        foreach (['dimension', 'series'] as $d) {
            if ($cfg[$d] !== null && $cfg[$d] !== 'month' && ! isset($ds['dimensions'][$cfg[$d]])) {
                $cfg[$d] = null;
            }
        }
        if ($cfg['dimension'] === 'month') {
            $cfg['dimension'] = null;
        }
        $cfg['grain'] = in_array($cfg['grain'], self::GRAINS, true) ? $cfg['grain'] : 'month';
        $cfg['period'] = in_array($cfg['period'], self::PERIODS, true) ? $cfg['period'] : 'dashboard';
        $cfg['top'] = max(0, min(100, (int) $cfg['top']));
        $cfg['sort'] = in_array($cfg['sort'], ['asc', 'desc', 'chrono'], true) ? $cfg['sort'] : 'desc';
        $cfg['other'] = (bool) $cfg['other'];
        if (! in_array($cfg['kind'], array_unique(self::TYPE_KIND), true)) {
            throw new InvalidArgumentException("Unknown visual kind '{$cfg['kind']}'.");
        }

        return $cfg;
    }

    public function catalogue(array $datasets): array
    {
        return collect($datasets)->map(fn ($ds) => ['label' => $ds['label'], 'module' => $ds['module'],
            'measures' => collect($ds['measures'])->map(fn ($m) => ['label' => $m['label'], 'format' => $m['format'] ?? 'number'])->all() + collect(DatasetRegistry::COMPARE)->map(fn ($l) => ['label' => $l, 'format' => 'compare'])->all(),
            'dimensions' => collect($ds['dimensions'])->map(fn ($d) => $d['label'])->all()])->all();
    }
}
