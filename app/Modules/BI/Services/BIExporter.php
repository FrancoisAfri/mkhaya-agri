<?php

namespace App\Modules\BI\Services;

use App\Modules\BI\Support\DatasetRegistry;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Turns the numbers behind a visual into rows (copied in idea from Mkhaya Sales' ReportExporter):
 * one visual → CSV or a one-sheet workbook; a whole dashboard → one sheet per visual. The numbers
 * come from BIEngine, so every subscription, permission and RLS check still applies.
 */
class BIExporter
{
    public function __construct(private BIEngine $engine) {}

    /** Run a widget and flatten it. Returns [headers, rows] or null when there is nothing to export. */
    public function widget(array $widget, array $filters, $user): ?array
    {
        $kind = BIEngine::TYPE_KIND[$widget['type'] ?? ''] ?? null;
        if (! $kind || $kind === 'none') {
            return null;
        }
        $res = $this->engine->run(['kind' => $kind] + (array) ($widget['config'] ?? []), $filters, $user);
        if (! empty($res['locked'])) {
            return [['Note'], [['Not available: ' . ($res['locked'] === 'subscription' ? 'outside your subscription' : 'you do not have access to this data')]]];
        }

        return $this->rows($res);
    }

    /** Flatten one engine result. */
    public function rows(array $r): array
    {
        $labels = $r['labels'] ?? [];
        $dimLabel = fn () => $this->dimLabel($r['dataset'] ?? '', $r['dimension'] ?? null);

        return match ($r['kind']) {
            'kpi' => [['Measure', 'Value'], collect($r['values'] ?? [])->filter(fn ($v, $k) => ! str_starts_with((string) $k, '_'))
                ->map(fn ($v, $k) => [$labels[$k] ?? $k, $v])->values()->all()],
            'time', 'calendar' => [array_merge(['Period'], array_column($r['series'], 'name')),
                collect($r['categories'])->map(fn ($c, $i) => array_merge([$c], array_map(fn ($s) => $s['data'][$i] ?? 0, $r['series'])))->all()],
            'category', 'pareto', 'table' => [array_merge([$dimLabel()], array_column($r['series'], 'name'), isset($r['cumulative']) ? ['Cumulative %'] : []),
                collect($r['categories'])->map(fn ($c, $i) => array_merge([$c], array_map(fn ($s) => $s['data'][$i] ?? 0, $r['series']), isset($r['cumulative']) ? [$r['cumulative'][$i]] : []))->all()],
            'matrix' => $this->matrixRows($r, $dimLabel()),
            'treemap' => [[$dimLabel(), 'Item', array_values($labels)[0] ?? 'Value'],
                collect($r['tree'])->flatMap(fn ($g) => collect($g['children'] ?: [['name' => '', 'value' => $g['value']]])->map(fn ($c) => [$g['name'], $c['name'], $c['value']]))->all()],
            'waterfall' => [['Step', 'Value'], array_merge([[$r['start']['name'], $r['start']['value']]], array_map(fn ($s) => [$s['name'], $s['value']], $r['steps']), [[$r['end']['name'], $r['end']['value']]])],
            'narrative' => [['Insight'], array_map(fn ($l) => [$l], $r['lines'] ?? [])],
            default => [['Note'], [['Nothing to export']]],
        };
    }

    private function matrixRows(array $r, string $dim): array
    {
        $grid = [];
        foreach ($r['cells'] as [$c, $row, $v]) {
            $grid[$row][$c] = $v;
        }

        return [array_merge([$dim], $r['columns']), collect($r['rows'])->map(fn ($name, $i) => array_merge([$name], array_map(fn ($c) => $grid[$i][$c] ?? 0, array_keys($r['columns']))))->all()];
    }

    private function dimLabel(string $dataset, ?string $dim): string
    {
        return $dim ? (DatasetRegistry::get($dataset)['dimensions'][$dim]['label'] ?? ucfirst(str_replace('_', ' ', $dim))) : 'Item';
    }

    /** One CSV (with a UTF-8 BOM so Excel opens rand signs and accents correctly). */
    public function csv(array $headers, array $rows): string
    {
        $h = fopen('php://temp', 'r+');
        fwrite($h, "\xEF\xBB\xBF");
        fputcsv($h, $headers);
        foreach ($rows as $row) {
            fputcsv($h, $row);
        }
        rewind($h);

        return stream_get_contents($h);
    }

    /**
     * A workbook: one sheet per [title => [headers, rows]]. Returns a temp file path; the caller
     * deletes it.
     */
    public function workbook(array $sheets, string $title): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle($title)->setCreator('Mkhaya');
        $book->removeSheetByIndex(0);
        $used = [];
        foreach ($sheets ?: ['Empty' => [['Note'], [['This dashboard has no data visuals.']]]] as $name => [$headers, $rows]) {
            $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', (string) $name)) ?: 'Sheet', 0, 28);
            $sheetName = $base;
            for ($n = 2; isset($used[mb_strtolower($sheetName)]); $n++) {
                $sheetName = mb_substr($base, 0, 26) . " {$n}";
            }
            $used[mb_strtolower($sheetName)] = true;
            $ws = $book->createSheet()->setTitle($sheetName);
            $ws->fromArray(array_merge([$headers], $rows), null, 'A1', true);
            $ws->getStyle('1:1')->getFont()->setBold(true);
            foreach (range(1, max(1, count($headers))) as $i) {
                $ws->getColumnDimensionByColumn($i)->setAutoSize(true);
            }
            $ws->freezePane('A2');
        }
        $book->setActiveSheetIndex(0);
        $path = tempnam(sys_get_temp_dir(), 'bi') . '.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    /** Every data visual on a dashboard, as workbook sheets. */
    public function dashboardSheets(array $widgets, array $filters, $user): array
    {
        $sheets = [];
        foreach (collect($widgets)->sortBy(fn ($w) => [$w['y'] ?? 0, $w['x'] ?? 0]) as $w) {
            if ($rows = $this->widget($w, $filters, $user)) {
                $title = $w['title'] ?: ($w['type'] ?? 'Visual');
                $sheets[isset($sheets[$title]) ? $title . ' ' . (count($sheets) + 1) : $title] = $rows;
            }
        }

        return $sheets;
    }
}
