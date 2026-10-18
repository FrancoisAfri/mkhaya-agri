<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\Forecast;
use App\Modules\Operations\WarehouseManagement\Models\ForecastRun;
use App\Modules\Operations\WarehouseManagement\Services\DemandForecaster;
use App\Modules\Operations\WarehouseManagement\Services\ExcessObsolete;
use App\Modules\Operations\WarehouseManagement\Services\PurchasingAdvisor;
use App\Modules\Operations\WarehouseManagement\Services\Replenishment;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-120..124: forecasts, buying recommendations, supplier comparison, excess and obsolete stock. */
class PlanningController extends Controller
{
    use RefusesPolitely;

    public function forecast(Request $request, DemandForecaster $forecaster): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $run = ForecastRun::query()->latest('id')->first();
        $rows = $run ? Forecast::query()->where('run_id', $run->id)->with('item:id,uuid,code,name,uom')->orderBy('item_id')->orderBy('period_start')->get()
            ->groupBy('item_id')->map(fn ($g) => [
                'item' => $g->first()->item?->code, 'name' => $g->first()->item?->name, 'uom' => $g->first()->item?->uom,
                'method' => $g->first()->method, 'basis' => $g->first()->basis,
                'periods' => $g->map(fn (Forecast $f) => ['id' => $f->id, 'start' => $f->period_start->toDateString(), 'qty' => $f->qty !== null ? (float) $f->qty : null,
                    'lower' => $f->lower !== null ? (float) $f->lower : null, 'upper' => $f->upper !== null ? (float) $f->upper : null,
                    'override' => $f->override_qty !== null ? (float) $f->override_qty : null, 'reason' => $f->override_reason])->values(),
            ])->sortBy(fn ($r) => $r['method'] === 'INSUFFICIENT' ? 1 : 0)->values() : collect();

        return Inertia::render('Modules/Warehouse/Planning/Forecast', [
            'run' => $run ? ['id' => $run->uuid, 'bucket' => $run->bucket, 'horizon' => $run->horizon, 'history' => $run->history_periods, 'items' => $run->items,
                'insufficient' => $run->insufficient, 'at' => $run->created_at?->toIso8601String()] : null,
            'rows' => $rows,
            'accuracy' => $forecaster->accuracy(),
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['id' => $w->id, 'title' => $w->code]),
            'can' => ['plan' => $request->user()->can(RoleCatalog::PLAN)],
        ]);
    }

    public function runForecast(Request $request, DemandForecaster $forecaster): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::PLAN), 403);
        $data = $request->validate(['bucket' => ['required', Rule::in(['WEEK', 'MONTH'])], 'horizon' => ['required', 'integer', 'between:1,52'],
            'history' => ['nullable', 'integer', 'between:6,156'], 'warehouse_id' => ['nullable', 'integer']]);
        if (! empty($data['warehouse_id'])) {
            FinWarehouse::query()->findOrFail($data['warehouse_id']);
        }

        return $this->attempt(fn () => $forecaster->run($data, $request->user()), 'Forecast run.');
    }

    public function override(Request $request, int|string $id, DemandForecaster $forecaster): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::PLAN), 403);
        $data = $request->validate(['qty' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:500']]);

        return $this->attempt(fn () => $forecaster->override(Forecast::query()->findOrFail((int) $id), (float) $data['qty'], $data['reason'], $request->user()), 'Forecast overridden.');
    }

    public function buying(Request $request, PurchasingAdvisor $advisor): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $level = (int) $request->input('service', 95);

        return Inertia::render('Modules/Warehouse/Planning/Buying', [
            'recommendations' => $advisor->recommendations($level),
            'service' => $level,
            'terms' => DB::table('wms_supplier_terms as t')->join('suppliers as s', 's.id', '=', 't.supplier_id')->join('fin_stock_items as i', 'i.id', '=', 't.stock_item_id')
                ->when(app(CurrentCompany::class)->id() ?? $request->user()->company_id, fn ($q, $c) => $q->where('t.company_id', $c))
                ->orderBy('i.code')->get(['t.id', 's.company_name as supplier', 'i.code as item', 't.min_order_qty', 't.order_multiple', 't.lead_time_days', 't.unit_price']),
            'suppliers' => DB::table('suppliers')->when(app(CurrentCompany::class)->id() ?? $request->user()->company_id, fn ($q, $c) => $q->where('company_id', $c))
                ->orderBy('company_name')->limit(500)->get(['id', 'company_name'])->map(fn ($s) => ['id' => $s->id, 'title' => $s->company_name]),
            'can' => ['plan' => $request->user()->can(RoleCatalog::PLAN)],
        ]);
    }

    public function compare(Request $request, string $uuid, PurchasingAdvisor $advisor)
    {
        $this->authorize('viewAny', FinStockItem::class);

        return response()->json($advisor->compareSuppliers(FinStockItem::query()->where('uuid', $uuid)->firstOrFail()));
    }

    /** Draft a purchase request from the chosen recommendations: Procurement approves it like any other. */
    public function request(Request $request, Replenishment $replenishment): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::PLAN), 403);
        $data = $request->validate(['lines' => ['required', 'array', 'min:1'], 'lines.*' => ['numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:500']]);
        $made = null;
        $response = $this->attempt(function () use ($replenishment, $data, $request, &$made) {
            $made = $replenishment->raiseRequest($data['lines'], $request->user(), $data['note'] ?? 'From buying recommendations');
        }, 'Draft purchase request raised.');

        return $made ? back()->with('success', "Draft purchase request {$made->request_number} raised; it now goes through Procurement's approvals.") : $response;
    }

    public function saveTerms(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::PLAN), 403);
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'item' => ['required', 'string'], 'min_order_qty' => ['nullable', 'numeric', 'min:0'],
            'order_multiple' => ['nullable', 'numeric', 'gt:0'], 'lead_time_days' => ['nullable', 'integer', 'between:0,730'], 'unit_price' => ['nullable', 'numeric', 'min:0']]);
        $cid = app(CurrentCompany::class)->id() ?? $request->user()->company_id;
        abort_unless(DB::table('suppliers')->where('id', $data['supplier_id'])->where('company_id', $cid)->exists(), 404);
        $item = FinStockItem::query()->where('uuid', $data['item'])->firstOrFail();
        DB::table('wms_supplier_terms')->updateOrInsert(['company_id' => $cid, 'supplier_id' => $data['supplier_id'], 'stock_item_id' => $item->id],
            ['min_order_qty' => $data['min_order_qty'] ?? null, 'order_multiple' => $data['order_multiple'] ?? null, 'lead_time_days' => $data['lead_time_days'] ?? null,
                'unit_price' => $data['unit_price'] ?? null, 'updated_at' => now(), 'created_at' => now()]);

        return back()->with('success', 'Supplier terms saved.');
    }

    public function excess(Request $request, ExcessObsolete $analysis): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $t = array_filter($request->only(['non_moving_days', 'obsolete_days', 'slow_cover_months']), fn ($v) => is_numeric($v) && $v > 0);

        return Inertia::render('Modules/Warehouse/Planning/Excess', ['analysis' => $analysis->analyse(array_map('intval', $t))]);
    }
}
