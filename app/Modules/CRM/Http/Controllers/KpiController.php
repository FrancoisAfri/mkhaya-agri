<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Models\CrmKpi;
use App\Modules\CRM\Services\CrmKpiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * No-code KPI builder (spec section 6).
 */
class KpiController extends Controller
{
    public function __construct(private CrmKpiService $service)
    {
    }

    public function index(): Response
    {
        $kpis = CrmKpi::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($k) => [
                'id'      => $k->id,
                'uuid'    => $k->uuid,
                'name'    => $k->name,
                'entity'  => $k->entity,
                'metric'  => $k->metric,
                'field'   => $k->field,
                'filters' => $k->filters ?? [],
                'format'  => $k->format,
                'color'   => $k->color,
                'pin_to_dashboard' => $k->pin_to_dashboard,
                'value'   => $this->service->compute($k),
            ]);

        return Inertia::render('Modules/CRM/KpiBuilder', [
            'kpis'     => $kpis,
            'entities' => $this->service->entities(),
            'columns'  => CrmKpiService::COLUMNS,
            'numeric'  => CrmKpiService::NUMERIC,
            'metrics'  => CrmKpi::METRICS,
            'formats'  => CrmKpi::FORMATS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $data['uuid']       = Str::uuid();
        $data['sort_order'] = (int) CrmKpi::max('sort_order') + 1;
        $data['created_by'] = Auth::id();

        CrmKpi::create($data);

        return back()->with('success', 'KPI added.');
    }

    public function update(Request $request, CrmKpi $kpi): RedirectResponse
    {
        $kpi->update($this->validateData($request));

        return back()->with('success', 'KPI updated.');
    }

    public function destroy(CrmKpi $kpi): RedirectResponse
    {
        $kpi->delete();

        return back()->with('success', 'KPI removed.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'                => 'required|string|max:120',
            'entity'              => 'required|in:deal,lead,contact,account,activity',
            'metric'              => 'required|in:count,sum,avg,min,max',
            'field'               => 'nullable|string|max:60',
            'filters'             => 'nullable|array',
            'filters.*.field'     => 'required_with:filters|string',
            'filters.*.operator'  => 'required_with:filters|string',
            'format'              => 'required|in:number,currency,percent',
            'color'               => 'nullable|string|max:20',
            'pin_to_dashboard'    => 'boolean',
        ]);
    }
}
