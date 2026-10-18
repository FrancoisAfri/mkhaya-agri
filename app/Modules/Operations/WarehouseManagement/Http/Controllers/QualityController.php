<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\DefectCode;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\InspectionCharacteristic;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Services\QualityControl;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseQuality;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** WMS-082: the warehouse's inspections on Manufacturing's quality records -- queue, results, decisions, plans, defect codes. */
class QualityController extends Controller
{
    use RefusesPolitely;

    private const STAGES = [InspectionPlan::INCOMING, InspectionPlan::STORAGE, InspectionPlan::RETURN, InspectionPlan::DISPATCH];

    public function index(Request $request, QualityControl $qc): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $status = $request->input('status', 'OPEN');
        $list = Inspection::query()->whereIn('stage', self::STAGES)
            ->when($status === 'OPEN', fn ($q) => $q->whereIn('status', [Inspection::OPEN, Inspection::AWAITING_DISPOSITION]),
                fn ($q) => $q->where('status', Inspection::CLOSED))
            ->with(['plan.characteristics'])->latest('id')->paginate(25)->withQueryString();
        $items = FinStockItem::query()->whereIn('id', $list->pluck('stock_item_id')->filter())->get(['id', 'code', 'name', 'uom'])->keyBy('id');
        $bins = FinStockBin::query()->whereIn('id', $list->pluck('bin_id')->filter())->pluck('code', 'id');
        $lots = FinStockLot::query()->whereIn('id', $list->pluck('lot_id')->filter())->pluck('lot_number', 'id');
        $warehouses = FinWarehouse::query()->whereIn('id', $list->pluck('warehouse_id')->filter())->pluck('code', 'id');

        return Inertia::render('Modules/Warehouse/Quality/Inspections', [
            'inspections' => $list->through(fn (Inspection $i) => [
                'id' => $i->uuid, 'number' => $i->number, 'stage' => $i->stage, 'status' => $i->status, 'result' => $i->result,
                'disposition' => $i->disposition, 'disposition_reason' => $i->disposition_reason, 'qty' => (float) $i->qty,
                'item' => $items[$i->stock_item_id]?->code ?? null, 'item_name' => $items[$i->stock_item_id]?->name ?? null, 'uom' => $items[$i->stock_item_id]?->uom ?? null,
                'warehouse' => $warehouses[$i->warehouse_id] ?? null, 'bin' => $bins[$i->bin_id] ?? null, 'lot' => $lots[$i->lot_id] ?? null,
                'reference' => $i->receipt_reference, 'notes' => $i->notes, 'sample_size' => $i->sample_size, 'plan' => $i->plan?->name,
                'checks' => $i->plan?->characteristics->map(fn ($c) => ['id' => $c->uuid, 'name' => $c->name, 'kind' => $c->kind, 'uom' => $c->uom,
                    'lower' => $c->lower_limit !== null ? (float) $c->lower_limit : null, 'upper' => $c->upper_limit !== null ? (float) $c->upper_limit : null,
                    'critical' => (bool) $c->is_critical])->values() ?? [],
                'decisions' => $i->status === Inspection::AWAITING_DISPOSITION ? $qc->allowedDispositions($i) : [],
                'created_at' => $i->created_at?->toIso8601String(),
            ]),
            'filters' => ['status' => $status],
            'defect_codes' => DefectCode::query()->where('is_active', true)->orderBy('code')->get(['code', 'name'])->map(fn ($d) => ['id' => $d->code, 'title' => "{$d->code} — {$d->name}"]),
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])->map(fn ($w) => ['id' => $w->uuid, 'title' => "{$w->code} — {$w->name}"]),
            'can' => ['quality' => $request->user()->can(RoleCatalog::QUALITY), 'manage' => $request->user()->can(RoleCatalog::MANAGE_QUALITY)],
        ]);
    }

    public function record(Request $request, string $uuid, QualityControl $qc): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::QUALITY), 403);
        $inspection = $this->inspection($uuid);
        $data = $request->validate([
            'results' => ['required', 'array', 'min:1'], 'results.*.characteristic' => ['nullable', 'string'], 'results.*.name' => ['nullable', 'string', 'max:120'],
            'results.*.value' => ['nullable', 'numeric'], 'results.*.passed' => ['nullable', 'boolean'], 'results.*.sample_no' => ['nullable', 'integer', 'min:1'],
            'results.*.defect_code' => ['nullable', 'string', 'max:24'], 'results.*.notes' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->domain(fn () => $qc->record($inspection, $data['results'], $request->user(), $data['notes'] ?? null)), 'Results recorded.');
    }

    public function decide(Request $request, string $uuid, QualityControl $qc): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::QUALITY), 403);
        $inspection = $this->inspection($uuid);
        $data = $request->validate(['decision' => ['required', Rule::in(Inspection::DISPOSITIONS)], 'reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->domain(fn () => $qc->dispose($inspection, $data['decision'], $data['reason'] ?? null, $request->user())), 'Decision recorded.');
    }

    public function hold(Request $request, WarehouseQuality $quality): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::QUALITY), 403);
        $data = $request->validate(['warehouse' => ['required', 'string'], 'item' => ['required', 'string'], 'bin' => ['nullable', 'string'],
            'lot_id' => ['nullable', 'integer'], 'qty' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:500']]);
        $warehouse = FinWarehouse::query()->where('uuid', $data['warehouse'])->firstOrFail();
        $bin = ! empty($data['bin']) ? FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('uuid', $data['bin'])->firstOrFail() : null;

        return $this->attempt(fn () => $this->domain(fn () => $quality->hold($warehouse, FinStockItem::query()->where('uuid', $data['item'])->firstOrFail(),
            $bin, $data['lot_id'] ?? null, (float) $data['qty'], $data['reason'], $request->user())), 'Held for inspection.');
    }

    public function inspectOrder(Request $request, string $uuid, WarehouseQuality $quality): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::QUALITY), 403);
        $order = FulfilmentOrder::query()->where('uuid', $uuid)->firstOrFail();

        return $this->attempt(fn () => $this->domain(fn () => $quality->inspectOrder($order, $request->user())),
            'Dispatch inspections opened for the items whose plan asks for one.');
    }

    // ── Plans and defect codes ───────────────────────────────────────────────────────────────

    public function setup(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Quality/Setup', [
            'plans' => InspectionPlan::query()->whereIn('stage', [InspectionPlan::STORAGE, InspectionPlan::RETURN, InspectionPlan::DISPATCH, InspectionPlan::INCOMING])
                ->with(['characteristics', 'stockItem:id,uuid,code,name'])->orderBy('stage')->orderBy('code')->get()
                ->map(fn (InspectionPlan $p) => [
                    'id' => $p->uuid, 'code' => $p->code, 'name' => $p->name, 'stage' => $p->stage, 'item' => $p->stockItem?->uuid,
                    'item_title' => $p->stockItem ? "{$p->stockItem->code} — {$p->stockItem->name}" : null, 'sampling_rule' => $p->sampling_rule ?? 'FIXED',
                    'sample_size' => $p->sample_size, 'sample_pct' => $p->sample_pct !== null ? (float) $p->sample_pct : null,
                    'requires_certificate' => (bool) $p->requires_certificate, 'is_active' => (bool) $p->is_active,
                    'checks' => $p->characteristics->map(fn ($c) => ['name' => $c->name, 'kind' => $c->kind, 'uom' => $c->uom,
                        'lower' => $c->lower_limit !== null ? (float) $c->lower_limit : null, 'upper' => $c->upper_limit !== null ? (float) $c->upper_limit : null,
                        'critical' => (bool) $c->is_critical])->values(),
                ]),
            'defect_codes' => DefectCode::query()->orderBy('code')->get()->map(fn ($d) => ['id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'severity' => $d->severity, 'is_active' => $d->is_active]),
            'stages' => [InspectionPlan::STORAGE, InspectionPlan::RETURN, InspectionPlan::DISPATCH, InspectionPlan::INCOMING],
            'sampling_rules' => InspectionPlan::SAMPLING_RULES,
            'severities' => DefectCode::SEVERITIES,
            'can' => ['manage' => $request->user()->can(RoleCatalog::MANAGE_QUALITY)],
        ]);
    }

    public function savePlan(Request $request, ?string $uuid = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_QUALITY), 403);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:120'],
            'stage' => ['required', Rule::in([InspectionPlan::STORAGE, InspectionPlan::RETURN, InspectionPlan::DISPATCH, InspectionPlan::INCOMING])],
            'item' => ['nullable', 'string'], 'sampling_rule' => ['required', Rule::in(InspectionPlan::SAMPLING_RULES)],
            'sample_size' => ['nullable', 'integer', 'min:1', 'required_if:sampling_rule,FIXED'], 'sample_pct' => ['nullable', 'numeric', 'gt:0', 'max:100', 'required_if:sampling_rule,PERCENT'],
            'requires_certificate' => ['boolean'], 'is_active' => ['boolean'],
            'checks' => ['nullable', 'array'], 'checks.*.name' => ['required', 'string', 'max:120'], 'checks.*.kind' => ['required', Rule::in(['MEASURED', 'ATTRIBUTE'])],
            'checks.*.uom' => ['nullable', 'string', 'max:16'], 'checks.*.lower' => ['nullable', 'numeric'], 'checks.*.upper' => ['nullable', 'numeric'], 'checks.*.critical' => ['boolean'],
        ]);

        return $this->attempt(function () use ($data, $uuid, $request) {
            $item = ! empty($data['item']) ? FinStockItem::query()->where('uuid', $data['item'])->firstOrFail() : null;
            if ($data['stage'] === InspectionPlan::INCOMING && ! $item) {
                throw new RuntimeException('An incoming plan belongs to one product.');
            }
            DB::transaction(function () use ($data, $uuid, $request, $item) {
                $plan = $uuid ? InspectionPlan::query()->where('uuid', $uuid)->firstOrFail() : new InspectionPlan([
                    'uuid' => (string) Str::uuid(), 'company_id' => app(CurrentCompany::class)->id() ?? $request->user()->company_id, 'created_by' => $request->user()->id]);
                $plan->fill(['code' => $data['code'], 'name' => $data['name'], 'stage' => $data['stage'], 'stock_item_id' => $item?->id,
                    'sampling_rule' => $data['sampling_rule'], 'sample_size' => $data['sample_size'] ?? null, 'sample_pct' => $data['sample_pct'] ?? null,
                    'requires_certificate' => $data['requires_certificate'] ?? false, 'is_active' => $data['is_active'] ?? true])->save();
                if (array_key_exists('checks', $data)) {
                    // Checks already recorded against stay as they were; changing the list starts a new set.
                    $used = DB::table('mfg_inspection_results')->whereIn('characteristic_id', $plan->characteristics()->select('id'))->exists();
                    if ($used) {
                        throw new RuntimeException('Results have been recorded against this plan\'s checks. Copy it to a new plan to change them.');
                    }
                    $plan->characteristics()->delete();
                    foreach (array_values($data['checks'] ?? []) as $i => $c) {
                        InspectionCharacteristic::create(['uuid' => (string) Str::uuid(), 'company_id' => $plan->company_id, 'plan_id' => $plan->id,
                            'sequence' => $i + 1, 'name' => $c['name'], 'kind' => $c['kind'], 'uom' => $c['uom'] ?? null, 'lower_limit' => $c['lower'] ?? null,
                            'upper_limit' => $c['upper'] ?? null, 'is_critical' => $c['critical'] ?? false]);
                    }
                }
            });
        }, 'Inspection plan saved.');
    }

    public function saveDefect(Request $request, ?string $id = null): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_QUALITY), 403);
        $data = $request->validate(['code' => ['required', 'string', 'max:24', 'regex:/^[A-Z0-9_-]+$/'], 'name' => ['required', 'string', 'max:120'],
            'severity' => ['required', Rule::in(DefectCode::SEVERITIES)], 'is_active' => ['boolean']]);

        return $this->attempt(function () use ($data, $id, $request) {
            $row = $id ? DefectCode::query()->findOrFail((int) $id) : new DefectCode(['company_id' => app(CurrentCompany::class)->id() ?? $request->user()->company_id]);
            if (DefectCode::query()->where('code', $data['code'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw new RuntimeException("Defect code {$data['code']} already exists.");
            }
            $row->fill($data)->save();
        }, 'Defect code saved.');
    }

    private function inspection(string $uuid): Inspection
    {
        return Inspection::query()->where('uuid', $uuid)->whereIn('stage', self::STAGES)->firstOrFail();
    }

    private function domain(callable $work): mixed
    {
        try {
            return $work();
        } catch (DomainException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
    }
}
