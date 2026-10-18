<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmActivity;
use App\Modules\CRM\Models\CrmDeal;
use App\Modules\CRM\Models\CrmKpi;
use App\Modules\CRM\Models\CrmLead;
use App\Modules\CRM\Models\CrmPipeline;
use App\Modules\CRM\Services\CrmKpiService;
use App\Modules\CRM\Services\CrmVisibilityService;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM dashboard — pipeline funnel, KPIs and recent activity (spec section 6).
 * Scoped to what the user may see: consultants their own, managers their tree.
 */
class CrmDashboardController extends Controller
{
    public function index(CrmVisibilityService $visibility, CrmKpiService $kpiService): Response
    {
        $owners = $visibility->visibleOwnerIds();
        // Apply the owner scope to any query, when restricted.
        $scope  = fn ($q) => $owners !== null ? $q->whereIn('owner_id', $owners) : $q;

        $kpis = [
            'open_deals'     => $scope(CrmDeal::where('status', 'open'))->count(),
            'open_value'     => (float) $scope(CrmDeal::where('status', 'open'))->sum('amount'),
            'won_this_month' => $scope(CrmDeal::where('status', 'won')
                ->whereMonth('closed_at', now()->month)->whereYear('closed_at', now()->year))->count(),
            'won_value'      => (float) $scope(CrmDeal::where('status', 'won')
                ->whereMonth('closed_at', now()->month)->whereYear('closed_at', now()->year))->sum('amount'),
            'new_leads'      => $scope(CrmLead::where('status', 'new'))->count(),
            'overdue_tasks'  => $scope(CrmActivity::where('status', 'pending')->whereNotNull('due_at')
                ->where('due_at', '<', now()))->count(),
        ];

        // Funnel: deal count + value per stage for the default pipeline.
        $pipeline = CrmPipeline::where('is_default', true)->with('stages')->first()
            ?? CrmPipeline::with('stages')->first();

        $funnel = [];
        if ($pipeline) {
            $byStage = $scope(CrmDeal::where('pipeline_id', $pipeline->id))
                ->select('stage_id', DB::raw('count(*) as c'), DB::raw('coalesce(sum(amount),0) as v'))
                ->groupBy('stage_id')->get()->keyBy('stage_id');

            $funnel = $pipeline->stages->map(fn ($s) => [
                'stage' => $s->name,
                'count' => (int) ($byStage[$s->id]->c ?? 0),
                'value' => (float) ($byStage[$s->id]->v ?? 0),
            ])->values();
        }

        $recent = $scope(CrmActivity::with('owner.person'))->latest()->limit(10)->get()
            ->map(fn ($a) => [
                'id'      => $a->id,
                'type'    => $a->type,
                'subject' => $a->subject,
                'related' => $a->related_type . ' #' . $a->related_id,
                'when'    => $a->created_at?->diffForHumans(),
            ]);

        // Custom metrics the user pinned on the Metrics page — shown as live cards.
        $customKpis = CrmKpi::where('pin_to_dashboard', true)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($k) => [
                'id'     => $k->id,
                'name'   => $k->name,
                'format' => $k->format,
                'color'  => $k->color,
                'value'  => $kpiService->compute($k),
            ])->values();

        return Inertia::render('Modules/CRM/Dashboard', [
            'kpis'       => $kpis,
            'customKpis' => $customKpis,
            'funnel'     => $funnel,
            'pipeline'   => $pipeline?->only('id', 'name'),
            'recent'     => $recent,
        ]);
    }
}
