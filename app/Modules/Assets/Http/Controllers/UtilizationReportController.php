<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Actions\GenerateUtilizationReportAction;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetLocation;
use App\Modules\Assets\Models\AssetUtilizationReport;
use App\Modules\Assets\Models\Assets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UtilizationReportController extends Controller
{
public function index(): Response
{
    $reports = AssetUtilizationReport::with('generatedBy')
        ->orderByDesc('generated_at')
        ->paginate(25)
        ->through(fn (AssetUtilizationReport $r) => [
            'uuid'                  => $r->uuid,
            'report_name'           => $r->report_name,
            'scope'                 => [
                'value' => $r->scope->value,
                'label' => $r->scope->label(), // adapte selon ton enum
            ],
            'period_start'          => $r->period_start?->toDateString(),
            'period_end'            => $r->period_end?->toDateString(),
            'total_assets_in_scope' => $r->total_assets_in_scope,
            'generated_at'          => $r->generated_at?->toISOString(),
            'generatedBy'           => $r->generatedBy
                                        ? ['name' => $r->generatedBy->name]
                                        : null,
        ]);

    return Inertia::render('Modules/Assets/Reports/Utilization/Index', [
        'reports' => $reports,
    ]);
}

    public function create(): Response
    {
        return Inertia::render('Modules/Assets/Reports/Utilization/Create', [
            'categories' => AssetCategory::where('status', 1)->get(['id', 'name']),
            'locations'  => AssetLocation::where('status', 1)->get(['id', 'name']),
            'assets'     => Assets::orderBy('name')->get(['id', 'name', 'asset_tag']),
        ]);
    }

    public function store(Request $request, GenerateUtilizationReportAction $action): RedirectResponse
    {
        $data = $request->validate([
            'report_name'  => 'required|string|max:255',
            'period_start' => 'required|date',
            'period_end'   => 'required|date|after:period_start|before_or_equal:' . now()->toDateString(),
            'scope'        => 'required|string|in:all,category,location,single',
            'scope_id'     => 'required_unless:scope,all|nullable|integer',
        ]);

        $start = now()->parse($data['period_start']);
        $end   = now()->parse($data['period_end']);
        abort_if($start->diffInDays($end) > 365, 422, 'Report period cannot exceed 365 days.');

        $report = $action->execute($data, $request->user()->id);

        return redirect()->route('assets.utilization-reports.show', $report->uuid)
            ->with('success', 'Report generated.');
    }

 public function show(AssetUtilizationReport $utilizationReport): Response
{
    $utilizationReport->load('generatedBy');

    $snapshots = $utilizationReport->snapshots()
        ->with('asset')
        ->orderByDesc('utilization_rate')
        ->paginate(50)
        ->through(fn ($s) => [
            'id'                     => $s->id,
            'utilization_rate'       => $s->utilization_rate,
            'total_operating_hours'  => $s->total_operating_hours,
            'total_downtime_minutes' => $s->total_downtime_minutes,
            'log_entry_count'        => $s->log_entry_count,
            'asset'                  => $s->asset ? [
                'uuid'      => $s->asset->uuid,
                'name'      => $s->asset->name,
                'asset_tag' => $s->asset->asset_tag,
            ] : null,
        ]);

    return Inertia::render('Modules/Assets/Reports/Utilization/Show', [
        'report'    => [
            'uuid'                  => $utilizationReport->uuid,
            'report_name'           => $utilizationReport->report_name,
            'scope'                 => [
                'value' => $utilizationReport->scope->value,
                'label' => $utilizationReport->scope->label(),
            ],
            'period_start'          => $utilizationReport->period_start?->toDateString(),
            'period_end'            => $utilizationReport->period_end?->toDateString(),
            'total_assets_in_scope' => $utilizationReport->total_assets_in_scope,
            'summary_stats'         => $utilizationReport->summary_stats,
            'generated_at'          => $utilizationReport->generated_at?->toISOString(),
            'generatedBy'           => $utilizationReport->generatedBy
                                        ? ['name' => $utilizationReport->generatedBy->name]
                                        : null,
        ],
        'snapshots' => $snapshots,
    ]);
}

    public function dashboard(): Response
    {
        $latest = AssetUtilizationReport::where('scope', 'all')
            ->orderByDesc('generated_at')
            ->first();

        return Inertia::render('Modules/Assets/Reports/Utilization/Dashboard', [
            'top_utilized'    => $latest?->top_utilized ?? [],
            'least_utilized'  => $latest?->least_utilized ?? [],
            'summary_stats'   => $latest?->summary_stats ?? [],
            'last_report_at'  => $latest?->generated_at?->toISOString(),
        ]);
    }
}
