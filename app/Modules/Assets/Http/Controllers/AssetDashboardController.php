<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetCustodyLog;
use App\Modules\Assets\Models\AssetIncident;
use App\Modules\Assets\Models\AssetMaintenanceSchedule;
use App\Modules\Assets\Models\AssetMovement;
use App\Modules\Assets\Models\AssetPolicyViolation;
use App\Modules\Assets\Models\AssetReplacementPrediction;
use App\Modules\Assets\Models\AssetRiskProfile;
use App\Modules\Assets\Models\AssetRiskScore;
use App\Modules\Assets\Models\AssetUtilizationReport;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Services\AssetAIModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AssetDashboardController extends Controller
{
    public function __construct(private AssetAIModule $ai) {}

    public function index(): Response
    {
        return Inertia::render('Modules/Assets/Dashboard/Index', [
            'overview'           => $this->overviewStats(),
            'maintenance'        => $this->maintenanceStats(),
            'incidents'          => $this->incidentStats(),
            'top_risk_assets'    => $this->topRiskAssets(),
            'utilization'        => $this->utilizationSummary(),
            'recent_activity'    => $this->recentActivity(),
            'assets_by_status'   => $this->groupByStatus(),
            'assets_by_category' => $this->groupByCategory(),
            'depreciation'       => $this->depreciationSummary(),
            'policy_violations'  => $this->policyViolationsSummary(),
            'near_replacement'   => $this->nearReplacement(),
            'idle_assets'        => $this->idleAssets(),
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $page    = (int) $request->input('page', 1);
        $perPage = min(50, (int) $request->input('per_page', 20));

        $days = (int) $request->input('days', 7);
        $logs = AssetCustodyLog::with('asset:id,uuid,name')
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'activity'     => $logs->getCollection()->map(fn ($l) => [
                'type'        => 'custody',
                'asset_uuid'  => $l->asset?->uuid,
                'asset_name'  => $l->asset?->name,
                'description' => ucwords(str_replace('_', ' ', $l->action)),
                'notes'       => $l->notes,
                'occurred_at' => $l->occurred_at?->toISOString(),
            ]),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }

    public function widget(string $widget): JsonResponse
    {
        $data = match ($widget) {
            'total-assets'         => ['count' => Assets::count()],
            'by-category'          => $this->groupByCategory(),
            'high-risk'            => $this->topRiskAssets(),
            'upcoming-maintenance' => $this->upcomingMaintenance(),
            'policy-violations'    => $this->policyViolationsSummary(),
            'utilization'          => $this->utilizationSummary(),
            'roi'                  => $this->roiSummary(),
            'depreciation-summary' => $this->depreciationSummary(),
            'idle-assets'          => $this->idleAssets(),
            'near-replacement'     => $this->nearReplacement(),
            default                => abort(404, 'Unknown widget'),
        };

        return response()->json(['widget' => $widget, 'data' => $data]);
    }

    public function aiRiskSummary(Assets $asset): JsonResponse
    {
        return response()->json($this->ai->summarizeAssetRisk($asset));
    }

    public function aiLifecycle(Assets $asset): JsonResponse
    {
        return response()->json($this->ai->summarizeLifecycle($asset));
    }

    public function aiReplacement(Assets $asset): JsonResponse
    {
        return response()->json($this->ai->predictReplacement($asset));
    }

    public function aiMaintenance(Assets $asset): JsonResponse
    {
        return response()->json($this->ai->recommendMaintenanceSchedule($asset));
    }

    public function aiDashboardSummary(): JsonResponse
    {
        return response()->json($this->ai->summarizeDashboard([
            'overview'           => $this->overviewStats(),
            'maintenance'        => $this->maintenanceStats(),
            'top_risk_assets'    => $this->topRiskAssets(),
            'policy_violations'  => $this->policyViolationsSummary(),
        ]));
    }

    private function overviewStats(): array
    {
        $excluded = ['disposed', 'sold'];

        return [
            'total'               => Assets::count(),
            'active'              => Assets::where('asset_status', 'active')->count(),
            'unallocated'         => Assets::where('asset_status', 'unallocated')->count(),
            'fleet_value'         => Assets::whereNotIn('asset_status', $excluded)->sum('purchase_cost'),
            'under_maintenance'   => Assets::where('asset_status', 'under_maintenance')->count(),
            'overdue_maintenance' => AssetMaintenanceSchedule::where('status', 'scheduled')
                ->whereDate('due_date', '<', now())->count(),
            'expired_warranties'  => Assets::whereNotNull('warranty_expiry')
                ->whereDate('warranty_expiry', '<', now())->count(),
        ];
    }

    private function maintenanceStats(): array
    {
        return [
            'scheduled'              => AssetMaintenanceSchedule::where('status', 'scheduled')->count(),
            'in_progress'            => AssetMaintenanceSchedule::where('status', 'in_progress')->count(),
            'overdue'                => AssetMaintenanceSchedule::where('status', 'scheduled')
                ->whereDate('due_date', '<', now())->count(),
            'completed_last_90_days' => AssetMaintenanceSchedule::where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays(90))->count(),
            'avg_actual_cost'        => AssetMaintenanceSchedule::where('status', 'completed')
                ->where('completed_at', '>=', now()->subDays(90))->avg('actual_cost'),
        ];
    }

    private function incidentStats(): array
    {
        $monthly = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = now()->subMonths($i);
            $monthly[] = [
                'month' => $m->format('M Y'),
                'count' => AssetIncident::whereYear('occurred_at', $m->year)
                    ->whereMonth('occurred_at', $m->month)->count(),
            ];
        }

        return [
            'total_unresolved' => AssetIncident::where('is_resolved', false)->count(),
            'total_last_30'    => AssetIncident::where('occurred_at', '>=', now()->subDays(30))->count(),
            'avg_repair_cost'  => AssetIncident::whereNotNull('repair_cost')->avg('repair_cost'),
            'monthly'          => $monthly,
        ];
    }

    private function topRiskAssets(): array
    {
        $latest = AssetRiskScore::query()
            ->select('asset_id', DB::raw('MAX(score_date) as latest'))
            ->groupBy('asset_id');

        return AssetRiskScore::query()
            ->joinSub($latest, 'l', fn ($j) => $j->on('asset_risk_scores.asset_id', '=', 'l.asset_id')
                ->on('asset_risk_scores.score_date', '=', 'l.latest'))
            ->with('asset:id,uuid,name,asset_tag,asset_status')
            ->orderByDesc('risk_score')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'asset_uuid' => $r->asset?->uuid,
                'asset_name' => $r->asset?->name,
                'asset_tag'  => $r->asset?->asset_tag,
                'risk_score' => $r->risk_score,
                'risk_level' => $r->risk_level,
            ])->toArray();
    }

    private function utilizationSummary(): array
    {
        $latest = AssetUtilizationReport::where('scope', 'all')
            ->orderByDesc('generated_at')->first();

        return [
            'top_utilized'   => $latest?->top_utilized   ?? [],
            'least_utilized' => $latest?->least_utilized ?? [],
            'summary_stats'  => $latest?->summary_stats  ?? [],
            'last_report_at' => $latest?->generated_at?->toISOString(),
        ];
    }

    private function recentActivity(): array
    {
        return AssetCustodyLog::with('asset:id,uuid,name')
            ->where('occurred_at', '>=', now()->subDays(7))
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get()
            ->map(fn ($l) => [
                'type'        => 'custody',
                'asset_uuid'  => $l->asset?->uuid,
                'asset_name'  => $l->asset?->name,
                'description' => ucwords(str_replace('_', ' ', $l->action)),
                'notes'       => $l->notes,
                'occurred_at' => $l->occurred_at?->toISOString(),
            ])->toArray();
    }

    private function groupByStatus(): array
    {
        return Assets::select('asset_status', DB::raw('count(*) as count'))
            ->groupBy('asset_status')->get()->toArray();
    }

    private function groupByCategory(): array
    {
        return Assets::select('asset_category_id', DB::raw('count(*) as count'))
            ->with('category:id,name')
            ->groupBy('asset_category_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get()->toArray();
    }

    private function depreciationSummary(): array
    {
        $monthStart = now()->startOfMonth();
        $yearStart  = now()->startOfYear();

        return [
            'mtd' => (float) DB::table('asset_depreciation_journals')
                ->where('posted_at', '>=', $monthStart)->sum('depreciation_expense'),
            'ytd' => (float) DB::table('asset_depreciation_journals')
                ->where('posted_at', '>=', $yearStart)->sum('depreciation_expense'),
        ];
    }

    private function policyViolationsSummary(): array
    {
        return [
            'open'   => AssetPolicyViolation::where('status', 'open')->count(),
            'recent' => AssetPolicyViolation::with('asset:id,uuid,name')
                ->orderByDesc('created_at')->limit(10)->get()->toArray(),
        ];
    }

    private function nearReplacement(): array
    {
        return AssetReplacementPrediction::with('asset:id,uuid,name,asset_tag')
            ->where('recommended_replacement_date', '<=', now()->addDays(90))
            ->orderBy('recommended_replacement_date')
            ->limit(10)->get()->toArray();
    }

    private function idleAssets(): array
    {
        $cutoff = now()->subDays(30);

        $idleIds = AssetMovement::select('asset_id', DB::raw('MAX(moved_at) as last_move'))
            ->groupBy('asset_id')
            ->havingRaw('MAX(moved_at) < ?', [$cutoff])
            ->pluck('asset_id');

        return Assets::whereIn('id', $idleIds)
            ->whereNotIn('asset_status', ['in_store', 'disposed', 'sold'])
            ->limit(20)
            ->get(['id', 'uuid', 'name', 'asset_tag', 'asset_status'])
            ->toArray();
    }

    private function upcomingMaintenance(): array
    {
        return AssetMaintenanceSchedule::with('asset:id,uuid,name,asset_tag')
            ->where('status', 'scheduled')
            ->whereBetween('due_date', [now(), now()->addDays(30)])
            ->orderBy('due_date')
            ->limit(20)
            ->get()->toArray();
    }

    private function roiSummary(): array
    {
        return [
            'average_roi' => (float) DB::table('asset_roi_reports')
                ->where('report_date', '>=', now()->subMonths(3))
                ->avg('roi_percentage'),
            'top'         => DB::table('asset_roi_reports')
                ->join('assets', 'assets.id', '=', 'asset_roi_reports.asset_id')
                ->select('assets.uuid', 'assets.name', 'asset_roi_reports.roi_percentage')
                ->orderByDesc('roi_percentage')
                ->limit(5)->get()->toArray(),
        ];
    }
}
