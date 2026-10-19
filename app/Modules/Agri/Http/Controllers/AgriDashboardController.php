<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Services\FarmAlerts;
use App\Modules\Agri\Services\FarmProfitability;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Mkhaya Agri landing page (spec Module I): the farms in the user's scope and the headline
 * figures of every module the person may see. Every count goes through the same access scopes as
 * the registers, so the dashboard can never show more than the person could open — and a section
 * whose permission the person lacks is not computed at all.
 */
class AgriDashboardController extends AgriController
{
    public function index(Request $request, FarmAccess $access, FarmAlerts $alerts, FarmProfitability $profitability): Response
    {
        $this->allow($request, R::VIEW_FARMS);
        $user = $request->user();
        $alerts->scanIfStale((int) $user->company_id);

        $farms = Farm::query()->where('status', '!=', Farm::ARCHIVED)->orderBy('name')
            ->withCount(['fields' => fn ($q) => $q->where('status', 'active')])->get();
        $fieldHa = FarmField::query()->where('status', 'active')->get(['area', 'area_unit'])->sum(fn (FarmField $f) => $f->hectares() ?? 0);

        $kpis = [
            'active_farms' => $farms->where('status', Farm::ACTIVE)->count(),
            'inactive_farms' => $farms->where('status', Farm::INACTIVE)->count(),
            'fields' => (int) $farms->sum('fields_count'),
            'field_hectares' => round($fieldHa, 1),
        ];

        $crops = null;
        if ($user->can(R::VIEW_CROPS)) {
            $live = CropPlan::query()->whereIn('status', CropPlan::LIVE)->with('cropType')->get();
            $crops = [
                'plans' => $live->count(),
                'planted_ha' => round($live->whereIn('status', [CropPlan::PLANTED, CropPlan::HARVESTING])->sum(fn ($p) => $p->hectares()), 1),
                'awaiting_approval' => $live->where('status', CropPlan::DRAFT)->count(),
                'by_crop' => $live->groupBy(fn ($p) => $p->cropType?->name ?? 'Other')->map(fn ($g, $k) => ['crop' => $k, 'hectares' => round($g->sum(fn ($p) => $p->hectares()), 1)])->values(),
                'activities_due' => FieldActivity::query()->whereIn('status', ['planned', 'in_progress'])->whereDate('planned_on', '<=', today()->addDays(7))->count(),
            ];
        }

        $livestock = null;
        if ($user->can(R::VIEW_LIVESTOCK)) {
            $groups = LivestockGroup::query()->where('status', 'active')->with('species')->get();
            $bySpecies = $groups->groupBy(fn ($g) => $g->species?->name ?? 'Other')->map(fn ($g, $k) => ['species' => $k, 'head' => $g->sum(fn ($x) => $x->currentHeadCount())])->values();
            $livestock = ['head' => (int) $bySpecies->sum('head') + Animal::query()->where('status', 'active')->whereNull('group_id')->count(),
                'groups' => $groups->count(), 'by_species' => $bySpecies];
        }

        $tasks = $user->can(R::VIEW_TASKS) ? [
            'mine' => FarmTask::query()->where('assigned_to', $user->id)->whereIn('status', FarmTask::PENDING)->count(),
            'overdue' => FarmTask::query()->whereIn('status', FarmTask::PENDING)->whereDate('due_on', '<', today())->count(),
            'awaiting_review' => FarmTask::query()->where('status', FarmTask::SUBMITTED)->count(),
        ] : null;

        $machinery = $user->can(R::VIEW_MACHINERY) ? [
            'machines' => FarmMachine::query()->where('status', '!=', 'disposed')->count(),
            'service_due' => FarmMachine::query()->where('status', '!=', 'disposed')->get()->filter(fn ($m) => $m->serviceDue(14, 10))->count(),
        ] : null;

        $finance = null;
        if ($user->can(R::VIEW_FINANCE)) {
            $rows = $profitability->summary($farms->where('status', Farm::ACTIVE)->values(), today()->startOfYear()->toDateString(), today()->toDateString());
            $finance = ['income' => round(array_sum(array_column($rows, 'income')), 2), 'costs' => round(array_sum(array_column($rows, 'costs')), 2),
                'gross_margin' => round(array_sum(array_column($rows, 'gross_margin')), 2), 'by_farm' => $rows];
        }

        $open = FarmAlert::query()->whereIn('status', [FarmAlert::OPEN, FarmAlert::ACKNOWLEDGED])->with('farm')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->orderBy('due_on')->limit(8)->get();

        return Inertia::render('Modules/Agri/Dashboard', [
            'kpis' => $kpis,
            'farms' => $farms->map(fn (Farm $f) => [
                'id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'farm_type' => $f->farm_type,
                'location' => $f->location, 'status' => $f->status, 'fields_count' => (int) $f->fields_count,
                'hectares' => Farm::hectares($f->area !== null ? (float) $f->area : null, $f->area_unit),
            ])->values(),
            'crops' => $crops, 'livestock' => $livestock, 'tasks' => $tasks, 'machinery' => $machinery, 'finance' => $finance,
            'alerts' => [
                'total' => FarmAlert::query()->whereIn('status', [FarmAlert::OPEN, FarmAlert::ACKNOWLEDGED])->count(),
                'critical' => FarmAlert::query()->whereIn('status', [FarmAlert::OPEN, FarmAlert::ACKNOWLEDGED])->where('severity', 'critical')->count(),
                'top' => $open->map(fn ($a) => ['id' => $a->uuid, 'severity' => $a->severity, 'title' => $a->title, 'message' => $a->message,
                    'link' => $a->link, 'farm' => $a->farm?->name]),
            ],
            'scope' => ['all_farms' => $access->isUnrestricted($user)],
            'can' => ['manage' => $user->can(R::MANAGE_FARMS), 'assistant' => $user->can(R::USE_ASSISTANT)],
        ]);
    }
}
