<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseKpis;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** §33: KPIs this month against last month and the chosen baseline, with every definition in view. */
class KpiController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request, WarehouseKpis $kpis): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        abort_unless($request->user()->can(RoleCatalog::SUPERVISE) || $request->user()->can(RoleCatalog::ACCOUNTING), 403);
        $cid = (int) app(CurrentCompany::class)->id();
        $now = $kpis->compute(now()->startOfMonth(), now());
        $snaps = DB::table('wms_kpi_snapshots')->where('company_id', $cid)->where('definition_version', WarehouseKpis::VERSION)->orderBy('period')->get()->groupBy('kpi');
        $baseline = DB::table('wms_kpi_baselines')->where('company_id', $cid)->value('period');
        $last = now()->subMonthNoOverflow()->format('Y-m');

        return Inertia::render('Modules/Warehouse/Insights/Kpis', [
            'kpis' => collect(WarehouseKpis::DEFINITIONS)->map(function ($d, $key) use ($now, $snaps, $baseline, $last) {
                $hist = ($snaps[$key] ?? collect())->map(fn ($s) => ['period' => $s->period, 'value' => $s->value !== null ? (float) $s->value : null])->values();

                return ['key' => $key, 'name' => $d[0], 'unit' => $d[1], 'higher_is_better' => $d[2], 'definition' => $d[3], 'exclusions' => $d[4],
                    'now' => $now[$key], 'last' => $hist->firstWhere('period', $last)['value'] ?? null,
                    'baseline' => $baseline ? ($hist->firstWhere('period', $baseline)['value'] ?? null) : null, 'history' => $hist->take(-12)->values()];
            })->values(),
            'baseline' => $baseline, 'last_period' => $last, 'month' => now()->format('Y-m'), 'version' => WarehouseKpis::VERSION,
            'periods' => DB::table('wms_kpi_snapshots')->where('company_id', $cid)->distinct()->orderByDesc('period')->pluck('period'),
            'can' => ['manage' => $request->user()->can(RoleCatalog::MANAGE_WAREHOUSES)],
        ]);
    }

    public function act(Request $request, WarehouseKpis $kpis): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_WAREHOUSES), 403);
        $d = $request->validate(['action' => ['required', 'in:snapshot,baseline'], 'period' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')]]);
        $cid = (int) app(CurrentCompany::class)->id();
        if ($d['action'] === 'snapshot') {
            abort_if($d['period'] === now()->format('Y-m'), 422, 'A month is recorded once it has ended.');

            return $this->attempt(fn () => $kpis->snapshot($cid, $d['period']), "KPIs for {$d['period']} recorded.");
        }
        abort_unless(DB::table('wms_kpi_snapshots')->where('company_id', $cid)->where('period', $d['period'])->exists(), 422, 'Record that month first.');
        DB::table('wms_kpi_baselines')->updateOrInsert(['company_id' => $cid], ['period' => $d['period'], 'set_by' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
        app(WarehouseAudit::class)->record($cid, 'kpi.baseline_set', 'KPI', $d['period'], "KPI baseline set to {$d['period']}");

        return back()->with('success', "Baseline set to {$d['period']}.");
    }
}
