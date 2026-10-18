<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\LocationChange;
use App\Modules\Operations\WarehouseManagement\Services\LabelSheet;
use App\Modules\Operations\WarehouseManagement\Services\LocationExplorer;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-002/004/005: the location explorer, location controls, labels and history. */
class LocationController extends Controller
{
    use RefusesPolitely;

    public function __construct(private readonly LocationService $locations) {}

    public function index(Request $request, LocationExplorer $explorer): Response
    {
        $this->authorize('viewAny', FinWarehouse::class);

        $warehouses = $this->warehouseOptions();
        $warehouse = $this->chosen($request);

        return Inertia::render('Modules/Warehouse/Layout/Locations', [
            'warehouses' => $warehouses,
            'warehouse' => $warehouse ? ['id' => $warehouse->uuid, 'code' => $warehouse->code, 'name' => $warehouse->name] : null,
            'explorer' => $warehouse ? $explorer->tree($warehouse) : null,
            'levels' => FinStockBin::LEVELS,
            'kinds' => FinStockBin::KINDS,
            'can' => [
                'manage' => $request->user()->can('manageLocations', FinWarehouse::class),
                'block' => $request->user()->can('blockLocations', FinWarehouse::class),
            ],
        ]);
    }

    public function store(Request $request, string $warehouse): RedirectResponse
    {
        $this->authorize('manageLocations', FinWarehouse::class);
        $wh = $this->warehouse($warehouse);
        $data = $this->validated($request);
        $data['parent_id'] = $this->parentId($wh, $request->input('parent'));

        return $this->attempt(fn () => $this->locations->create($wh, $data), 'Location added.');
    }

    public function generate(Request $request, string $warehouse): RedirectResponse
    {
        $this->authorize('manageLocations', FinWarehouse::class);
        $wh = $this->warehouse($warehouse);
        $data = $request->validate([
            'parent' => ['nullable', 'string'],
            'count' => ['required', 'integer', 'min:1', 'max:500'],
            'level' => ['nullable', Rule::in(FinStockBin::LEVELS)],
            'prefix' => ['nullable', 'string', 'max:24', 'regex:/^[A-Za-z0-9_\-\.]*$/'],
            'max_units' => ['nullable', 'numeric', 'min:0'],
            'max_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ]);
        $parent = $data['parent'] ? $this->bin($wh, $data['parent']) : null;
        $limits = array_filter(['max_units' => $data['max_units'] ?? null, 'max_weight_kg' => $data['max_weight_kg'] ?? null], fn ($v) => $v !== null);

        return $this->attempt(
            fn () => $this->locations->generate($wh, $parent, (int) $data['count'], $data['level'] ?? null, $data['prefix'] ?? null, $limits),
            "{$data['count']} locations added.",
        );
    }

    public function update(Request $request, string $warehouse, string $uuid): RedirectResponse
    {
        $this->authorize('manageLocations', FinWarehouse::class);
        $wh = $this->warehouse($warehouse);
        $bin = $this->bin($wh, $uuid);
        $data = $this->validated($request);
        if ($request->has('parent')) {
            $data['parent_id'] = $this->parentId($wh, $request->input('parent'));
        }

        return $this->attempt(fn () => $this->locations->update($bin, $data), 'Location saved.');
    }

    public function status(Request $request, string $warehouse, string $uuid): RedirectResponse
    {
        $this->authorize('blockLocations', FinWarehouse::class);
        $bin = $this->bin($this->warehouse($warehouse), $uuid);
        $data = $request->validate([
            'status' => ['required', Rule::in(FinStockBin::STATUSES)],
            'reason' => ['required', 'string', 'max:500'],
            'until' => ['nullable', 'date'],
        ]);

        return $this->attempt(fn () => match ($data['status']) {
            FinStockBin::BLOCKED => $this->locations->block($bin, $data['reason']),
            FinStockBin::RESTRICTED => $this->locations->restrict($bin, $data['reason'], isset($data['until']) ? Carbon::parse($data['until']) : null),
            default => $this->locations->reopen($bin, $data['reason']),
        }, 'Location status changed.');
    }

    public function destroy(Request $request, string $warehouse, string $uuid): RedirectResponse
    {
        $this->authorize('manageLocations', FinWarehouse::class);
        $bin = $this->bin($this->warehouse($warehouse), $uuid);

        return $this->attempt(fn () => $this->locations->deactivate($bin, $request->input('reason')), 'Location retired.');
    }

    /** Printable labels: a whole warehouse, one branch of it, or a hand-picked set. */
    public function labels(Request $request, LabelSheet $sheet): Response
    {
        $this->authorize('viewAny', FinWarehouse::class);
        $warehouse = $this->chosen($request);

        $labels = [];
        if ($warehouse) {
            $query = FinStockBin::query()->with('warehouse:id,code')->where('warehouse_id', $warehouse->id)->where('is_active', true);
            if ($request->filled('under')) {
                $root = $this->bin($warehouse, (string) $request->input('under'));
                $query->whereIn('id', $this->locations->descendantIds($root));
            }
            if ($request->filled('level')) {
                $query->where('level', strtoupper((string) $request->input('level')));
            }
            $labels = $sheet->for($query->orderBy('code')->limit(500)->get());
        }

        return Inertia::render('Modules/Warehouse/Layout/Labels', [
            'warehouses' => $this->warehouseOptions(),
            'warehouse' => $warehouse ? ['id' => $warehouse->uuid, 'code' => $warehouse->code, 'name' => $warehouse->name] : null,
            'labels' => $labels,
            'levels' => FinStockBin::LEVELS,
            'filters' => ['level' => $request->input('level'), 'under' => $request->input('under')],
        ]);
    }

    /** WMS-004 "record all changes to location configuration", readable. */
    public function history(Request $request): Response
    {
        $this->authorize('viewAny', FinWarehouse::class);
        $warehouse = $this->chosen($request);

        $changes = LocationChange::query()->with(['warehouse:id,code', 'bin:id,uuid,code', 'actor.person'])
            ->when($warehouse, fn ($q) => $q->where('warehouse_id', $warehouse->id))
            ->when($request->filled('location'), fn ($q) => $q->whereHas('bin', fn ($b) => $b->where('uuid', $request->input('location'))))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->input('action')))
            ->latest('created_at')->paginate(50)->withQueryString();

        return Inertia::render('Modules/Warehouse/Layout/History', [
            'warehouses' => $this->warehouseOptions(),
            'warehouse' => $warehouse?->uuid,
            'filters' => $request->only(['location', 'action']),
            'actions' => [LocationChange::CREATED, LocationChange::UPDATED, LocationChange::BLOCKED, LocationChange::RESTRICTED,
                LocationChange::REOPENED, LocationChange::DEACTIVATED, LocationChange::SYNCED],
            'changes' => $changes->through(fn (LocationChange $c) => [
                'action' => $c->action,
                'warehouse' => $c->warehouse?->code,
                'location' => $c->bin?->code,
                'changes' => $c->changes,
                'reason' => $c->reason,
                'actor' => $c->actor?->person ? trim($c->actor->person->first_name.' '.$c->actor->person->surname) : $c->actor?->email,
                'at' => $c->created_at?->toIso8601String(),
            ]),
        ]);
    }

    private function warehouseOptions(): array
    {
        return FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['uuid', 'code', 'name'])
            ->map(fn ($w) => ['id' => $w->uuid, 'code' => $w->code, 'name' => $w->name])->all();
    }

    private function chosen(Request $request): ?FinWarehouse
    {
        $uuid = $request->input('warehouse');

        return $uuid
            ? FinWarehouse::query()->where('uuid', $uuid)->firstOrFail()
            : FinWarehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('code')->first();
    }

    private function warehouse(string $uuid): FinWarehouse
    {
        return FinWarehouse::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function bin(FinWarehouse $warehouse, string $uuid): FinStockBin
    {
        return FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function parentId(FinWarehouse $warehouse, ?string $uuid): ?int
    {
        return $uuid ? $this->bin($warehouse, $uuid)->id : null;
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['sometimes', 'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'name' => ['nullable', 'string', 'max:120'],
            'level' => ['sometimes', Rule::in(FinStockBin::LEVELS)],
            'kind' => ['sometimes', Rule::in(FinStockBin::KINDS)],
            'barcode' => ['nullable', 'string', 'max:64'],
            'max_units' => ['nullable', 'numeric', 'min:0'],
            'max_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'max_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'max_pallets' => ['nullable', 'integer', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'requires_temperature_monitoring' => ['boolean'],
            'temperature_min_c' => ['nullable', 'numeric', 'between:-80,80'],
            'temperature_max_c' => ['nullable', 'numeric', 'between:-80,80'],
            'hazmat_allowed' => ['boolean'],
            'handling_notes' => ['nullable', 'string', 'max:1000'],
            'pick_sequence' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
