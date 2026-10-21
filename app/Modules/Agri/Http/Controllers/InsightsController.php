<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Services\FarmDashboards;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 2 §13: the dashboard for each role, over the farms the person can open (or one farm), for
 * a period, with every figure labelled real-time, actual, estimate or forecast and linked to its
 * records — and each table exportable as CSV or Excel.
 */
class InsightsController extends AgriController
{
    public function __construct(private readonly FarmDashboards $dashboards) {}

    public function index(Request $request, ?string $role = null): Response
    {
        $this->allow($request, R::VIEW_FARMS);
        $roles = $this->dashboards->rolesFor($request->user());
        $role ??= $roles[0]['key'] ?? 'worker';
        abort_unless(collect($roles)->pluck('key')->contains($role), 403);
        [$farms, $farm, $from, $to] = $this->scope($request);

        return Inertia::render('Modules/Agri/Insights', [
            'roles' => $roles, 'dashboard' => $this->dashboards->build($role, $request->user(), $farms, $from, $to),
            'filters' => ['farm' => $farm?->uuid, 'from' => $from, 'to' => $to],
            'farms' => Farm::query()->operational()->orderBy('name')->get()->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
        ]);
    }

    /** One section's table (or the alerts) as CSV or Excel. */
    public function export(Request $request, string $role, string $section): StreamedResponse
    {
        $this->allow($request, R::VIEW_FARMS);
        abort_unless(collect($this->dashboards->rolesFor($request->user()))->pluck('key')->contains($role), 403);
        [$farms, , $from, $to] = $this->scope($request);
        $d = $this->dashboards->build($role, $request->user(), $farms, $from, $to);
        $rows = $section === 'alerts' ? $d['alerts'] : (collect($d['sections'])->firstWhere('key', $section)['rows'] ?? collect($d['sections'])->firstWhere('key', $section)['metrics'] ?? null);
        abort_if($rows === null, 404);
        $rows = array_map(fn ($r) => collect($r)->map(fn ($v) => is_array($v) ? json_encode($v) : $v)->all(), array_values($rows));
        $cols = $rows ? array_keys($rows[0]) : [];
        $name = "agri-{$role}-{$section}-{$from}-{$to}";

        if ($request->query('format') === 'xlsx') {
            return response()->streamDownload(function () use ($rows, $cols, $section, $from, $to) {
                $book = new Spreadsheet;
                $sheet = $book->getActiveSheet()->setTitle(mb_substr($section, 0, 30));
                $sheet->fromArray([array_map(fn ($c) => ucfirst(str_replace('_', ' ', $c)), $cols)], null, 'A1');
                $sheet->fromArray(array_map(fn ($r) => array_map(fn ($c) => $r[$c] ?? null, $cols), $rows), null, 'A2');
                $sheet->setCellValue('A'.(count($rows) + 3), "Period {$from} to {$to}. Generated ".now()->toDateTimeString().' UTC.');
                (new Xlsx($book))->save('php://output');
            }, "{$name}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($rows, $cols) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $cols);
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($c) => $r[$c] ?? '', $cols));
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv']);
    }

    /** §19: outcomes for a period, and against the farm's baseline. */
    public function outcomes(Request $request): Response
    {
        $this->allow($request, R::VIEW_REPORTS);
        $farm = $this->farmFilter($request) ?? Farm::query()->operational()->orderBy('name')->first();
        $from = $request->date('from')?->toDateString() ?? today()->subMonths(3)->toDateString();
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();
        $o = app(\App\Modules\Agri\Services\FarmOutcomes::class);

        return Inertia::render('Modules/Agri/Outcomes', [
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null, 'from' => $from, 'to' => $to,
            'farms' => Farm::query()->operational()->orderBy('name')->get()->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'measures' => $farm ? $o->measure($farm, $from, $to) : [],
            'comparison' => $farm ? $o->compare($farm) : null,
            'snapshots' => $farm ? \App\Modules\Agri\Models\OutcomeSnapshot::query()->where('farm_id', $farm->id)->orderByDesc('period_to')->limit(24)->get(['uuid', 'period_from', 'period_to', 'is_baseline', 'note']) : [],
            'can' => ['snapshot' => $request->user()->can(R::MANAGE_FARMS)],
        ]);
    }

    public function snapshot(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->allow($request, R::MANAGE_FARMS);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'baseline' => ['boolean'],
            'note' => ['nullable', 'string', 'max:300']]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        app(\App\Modules\Agri\Services\FarmOutcomes::class)->snapshot($farm, $data['from'], $data['to'], (bool) ($data['baseline'] ?? false), $data['note'] ?? null, $request->user());

        return back()->with('success', ($data['baseline'] ?? false) ? 'Baseline recorded.' : 'Period recorded against the baseline.');
    }

    private function scope(Request $request): array
    {
        $farm = $this->farmFilter($request);
        $farms = $farm ? collect([$farm]) : Farm::query()->operational()->get();
        $from = $request->date('from')?->toDateString() ?? today()->startOfYear()->toDateString();
        $to = $request->date('to')?->toDateString() ?? today()->toDateString();

        return [$farms, $farm, $from, $to];
    }
}
