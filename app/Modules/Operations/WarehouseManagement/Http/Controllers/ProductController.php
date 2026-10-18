<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;
use App\Modules\Operations\Manufacturing\Models\Item as MfgItem;
use App\Modules\Operations\Manufacturing\Models\ItemCategory;
use App\Modules\Operations\Manufacturing\Models\ItemSubstitute;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\ItemBarcode;
use App\Modules\Operations\WarehouseManagement\Models\ItemBundle;
use App\Modules\Operations\WarehouseManagement\Models\ItemEvent;
use App\Modules\Operations\WarehouseManagement\Models\ItemIdentifier;
use App\Modules\Operations\WarehouseManagement\Models\ItemPackaging;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use App\Modules\Operations\WarehouseManagement\Services\BarcodeRegistry;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\ItemLifecycle;
use App\Modules\Operations\WarehouseManagement\Services\LabelSheet;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** WMS-010 to WMS-013: the product master, its units, packaging, barcodes and lifecycle. */
class ProductController extends Controller
{
    use RefusesPolitely, ScopesValidationToTenant;

    public function __construct(
        private readonly ItemCatalogue $catalogue,
        private readonly BarcodeRegistry $barcodes,
        private readonly ItemLifecycle $lifecycle,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $q = trim((string) $request->input('q', ''));
        $duplicates = $request->boolean('duplicates') ? $this->barcodes->duplicates() : null;

        $items = FinStockItem::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('code', 'ilike', "%{$q}%")->orWhere('name', 'ilike', "%{$q}%")
                ->orWhereIn('id', ItemBarcode::query()->where('barcode', $q)->select('stock_item_id'))
                ->orWhereIn('id', ItemIdentifier::query()->where('code', 'ilike', "%{$q}%")->select('stock_item_id'))))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->when($duplicates !== null, fn ($query) => $query->whereIn('id', ItemBarcode::query()->whereIn('barcode', $duplicates)->select('stock_item_id')))
            ->tap(fn ($query) => app(\App\Modules\Operations\WarehouseManagement\Services\ClientVisibility::class)->scope($query))
            ->orderBy('code')
            ->paginate(50)->withQueryString();

        $ids = collect($items->items())->pluck('id');
        $primary = ItemBarcode::query()->whereIn('stock_item_id', $ids)->where('is_active', true)->orderByDesc('is_primary')->get()->groupBy('stock_item_id');
        $profiles = ItemProfile::query()->whereIn('stock_item_id', $ids)->get()->keyBy('stock_item_id');
        $mfg = MfgItem::withoutGlobalScopes()->whereIn('stock_item_id', $ids)->get()->keyBy('stock_item_id');

        return Inertia::render('Modules/Warehouse/Products/Index', [
            'items' => $items->through(fn (FinStockItem $i) => [
                'id' => $i->uuid, 'code' => $i->code, 'name' => $i->name, 'uom' => $i->uom, 'status' => $i->status,
                'qty_on_hand' => (float) $i->qty_on_hand,
                'brand' => $profiles[$i->id]->brand ?? null,
                'barcode' => ($primary[$i->id] ?? collect())->first()?->barcode,
                'barcodes' => ($primary[$i->id] ?? collect())->count(),
                'lot' => (bool) ($mfg[$i->id]->is_lot_tracked ?? false),
                'serial' => (bool) ($mfg[$i->id]->is_serial_tracked ?? false),
            ]),
            'filters' => ['q' => $q, 'status' => $request->input('status'), 'duplicates' => $request->boolean('duplicates')],
            'statuses' => FinStockItem::STATUSES,
            'duplicate_count' => count($duplicates ?? $this->barcodes->duplicates()),
            'units' => $this->units(),
            'can' => ['manage' => $request->user()->can('create', FinStockItem::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', FinStockItem::class);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-\.\/]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'uom' => ['required', 'string', 'max:16'],
            'brand' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['company_id'] = $this->companyId($request);
        $item = null;

        $response = $this->attempt(function () use ($data, &$item) {
            $this->catalogue->ensureUnits($data['company_id']);
            $item = $this->catalogue->create($data);
        }, 'Item created.');

        return $item ? redirect()->route('warehouse.products.show', $item->uuid)->with('success', 'Item created.') : $response;
    }

    public function show(Request $request, string $uuid): Response
    {
        $item = $this->find($uuid);
        $this->authorize('view', $item);
        abort_unless(app(\App\Modules\Operations\WarehouseManagement\Services\ClientVisibility::class)->mayView($item), 403, 'This item belongs to a client you have not been granted.');
        $this->catalogue->ensureUnits((int) $item->company_id);
        $mfg = $this->catalogue->mfgProfile($item);
        $profile = ItemProfile::query()->firstOrNew(['stock_item_id' => $item->id]);
        $base = $this->catalogue->baseUom($item);

        return Inertia::render('Modules/Warehouse/Products/Show', [
            'item' => [
                'id' => $item->uuid, 'code' => $item->code, 'name' => $item->name, 'uom' => $item->uom,
                'base_uom_id' => $base->id, 'status' => $item->status, 'status_reason' => $item->status_reason,
                'status_changed_at' => $item->status_changed_at?->toIso8601String(),
                'cost_method' => $item->cost_method, 'standard_cost' => (float) $item->standard_cost,
                'reorder_level' => (float) $item->reorder_level, 'qty_on_hand' => (float) $item->qty_on_hand,
                'has_movements' => FinStockMovement::query()->where('item_id', $item->id)->exists(),
                'owner_party_id' => $item->owner_party_id, 'is_valued' => $item->isValued(),
            ],
            'profile' => $profile->only(['description', 'brand', 'purchase_uom_id', 'storage_uom_id', 'issue_uom_id',
                'is_variable_weight', 'safety_stock', 'max_stock', 'reorder_qty', 'lead_time_days',
                'min_remaining_shelf_life_days', 'expiry_warning_days', 'temperature_min_c', 'temperature_max_c',
                'hazard_class', 'requires_inspection', 'loss_risk', 'abc_class', 'rotation', 'expiry_alert_days']),
            'physical' => $mfg->only(['kind', 'category_id', 'weight_kg', 'dimensions', 'shelf_life_days', 'storage_conditions',
                'handling_instructions', 'is_lot_tracked', 'is_serial_tracked']),
            'packaging' => ItemPackaging::query()->where('stock_item_id', $item->id)->with('uom:id,code')->orderBy('qty_in_base')->get()
                ->map(fn (ItemPackaging $p) => $p->only(['uuid', 'level', 'name', 'uom_id', 'qty_in_base', 'gtin', 'length_cm', 'width_cm', 'height_cm', 'gross_weight_kg', 'is_active'])
                    + ['uom' => $p->uom?->code]),
            'barcodes' => ItemBarcode::query()->where('stock_item_id', $item->id)->with('packaging:id,level')->orderByDesc('is_active')->orderByDesc('is_primary')->get()
                ->map(fn (ItemBarcode $b) => $b->only(['uuid', 'barcode', 'symbology', 'source', 'is_primary', 'is_active', 'duplicate_reason'])
                    + ['packaging' => $b->packaging?->level, 'supplier_id' => $b->supplier_id,
                        'shared_with' => ItemBarcode::query()->where('wms_item_barcodes.barcode', $b->barcode)->where('wms_item_barcodes.stock_item_id', '!=', $item->id)->where('wms_item_barcodes.is_active', true)
                            ->join('fin_stock_items as i', 'i.id', '=', 'wms_item_barcodes.stock_item_id')->pluck('i.code')]),
            'identifiers' => ItemIdentifier::query()->where('stock_item_id', $item->id)->orderBy('kind')->get()
                ->map(fn (ItemIdentifier $i) => $i->only(['uuid', 'kind', 'code', 'supplier_id', 'party_name', 'description'])),
            'bundle' => ItemBundle::query()->where('bundle_item_id', $item->id)->with('component:id,uuid,code,name')->get()
                ->map(fn (ItemBundle $b) => ['component_id' => $b->component?->uuid, 'code' => $b->component?->code, 'name' => $b->component?->name, 'qty' => (float) $b->qty]),
            'substitutes' => ItemSubstitute::query()->where('item_id', $mfg->id)->where('is_active', true)->get()
                ->map(fn (ItemSubstitute $s) => ['id' => $s->uuid, 'ratio' => (float) $s->ratio, 'requires_approval' => (bool) $s->requires_approval,
                    'restriction' => $s->restriction] + (FinStockItem::query()->whereKey($s->substitute_stock_item_id)->first(['uuid', 'code', 'name'])?->toArray() ?? [])),
            'history' => ItemEvent::query()->where('stock_item_id', $item->id)->with('actor.person')->latest('created_at')->limit(50)->get()
                ->map(fn (ItemEvent $e) => ['action' => $e->action, 'changes' => $e->changes, 'reason' => $e->reason,
                    'actor' => $e->actor?->person ? trim($e->actor->person->first_name.' '.$e->actor->person->surname) : $e->actor?->email,
                    'at' => $e->created_at?->toIso8601String()]),
            'units' => $this->units(),
            'categories' => ItemCategory::query()->when($this->companyId($request), fn ($q, $c) => $q->where('company_id', $c))->orderBy('name')->get(['id', 'name']),
            'suppliers' => DB::table('suppliers')->whereNull('deleted_at')
                ->when($this->companyId($request), fn ($q, $c) => $q->where('company_id', $c))
                ->orderBy('company_name')->limit(500)->get(['id', 'company_name as name']),
            'parties' => DB::table('fin_parties')->where('is_active', true)
                ->when($this->companyId($request), fn ($q, $c) => $q->where('company_id', $c))
                ->orderBy('name')->limit(1000)->get(['id', 'name']),
            'statuses' => FinStockItem::STATUSES,
            'packaging_levels' => ItemPackaging::LEVELS,
            'symbologies' => ItemBarcode::SYMBOLOGIES,
            'identifier_kinds' => ItemIdentifier::KINDS,
            'can' => [
                'manage' => $request->user()->can('update', $item),
                'status' => $request->user()->can('changeStatus', $item),
                'approve_duplicate' => $request->user()->can('approveDuplicateBarcode', $item),
            ],
        ]);
    }

    public function update(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $uom = $this->tenantExists('mfg_uoms');
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'uom' => ['sometimes', 'required', 'string', 'max:16'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'brand' => ['nullable', 'string', 'max:120'],
            'purchase_uom_id' => ['nullable', 'integer', $uom],
            'storage_uom_id' => ['nullable', 'integer', $uom],
            'issue_uom_id' => ['nullable', 'integer', $uom],
            'is_variable_weight' => ['boolean'],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_qty' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'min_remaining_shelf_life_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'expiry_warning_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'temperature_min_c' => ['nullable', 'numeric', 'between:-80,80'],
            'temperature_max_c' => ['nullable', 'numeric', 'between:-80,80', 'gte:temperature_min_c'],
            'hazard_class' => ['nullable', 'string', 'max:16'],
            'loss_risk' => ['nullable', \Illuminate\Validation\Rule::in(['LOW', 'MEDIUM', 'HIGH'])],
            'rotation' => ['nullable', \Illuminate\Validation\Rule::in(['FEFO', 'FIFO', 'LIFO', 'NONE'])],
            'expiry_alert_days' => ['nullable', 'array', 'max:8'], 'expiry_alert_days.*' => ['integer', 'between:1,1095'],
            'requires_inspection' => ['boolean'],
            'category_id' => ['nullable', 'integer', $this->tenantExists('mfg_item_categories')],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'dimensions' => ['nullable', 'array'],
            'dimensions.length' => ['nullable', 'numeric', 'min:0'],
            'dimensions.width' => ['nullable', 'numeric', 'min:0'],
            'dimensions.height' => ['nullable', 'numeric', 'min:0'],
            'shelf_life_days' => ['nullable', 'integer', 'min:0', 'max:36500'],
            'storage_conditions' => ['nullable', 'string', 'max:1000'],
            'handling_instructions' => ['nullable', 'string', 'max:1000'],
            'is_lot_tracked' => ['boolean'],
            'is_serial_tracked' => ['boolean'],
            'owner_party_id' => ['nullable', 'integer', $this->tenantExists('fin_parties')],
        ]);

        return $this->attempt(fn () => $this->catalogue->update($item, $data), 'Item saved.');
    }

    public function status(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('changeStatus', $item);
        $data = $request->validate([
            'status' => ['required', Rule::in(FinStockItem::STATUSES)],
            'reason' => ['required', 'string', 'max:1000'],
            'quarantine_stock' => ['boolean'],
        ]);

        return $this->attempt(fn () => $this->lifecycle->change($item, $data['status'], $data['reason'], (bool) ($data['quarantine_stock'] ?? false)), 'Status changed.');
    }

    public function storePackaging(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $data = $this->packagingRules($request);

        return $this->attempt(fn () => $this->catalogue->savePackaging($item, $data), 'Packaging level saved.');
    }

    public function updatePackaging(Request $request, string $uuid, string $packaging): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $row = ItemPackaging::query()->where('stock_item_id', $item->id)->where('uuid', $packaging)->firstOrFail();

        return $this->attempt(fn () => $this->catalogue->savePackaging($item, $this->packagingRules($request), $row), 'Packaging level saved.');
    }

    public function destroyPackaging(string $uuid, string $packaging): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $row = ItemPackaging::query()->where('stock_item_id', $item->id)->where('uuid', $packaging)->firstOrFail();

        return $this->attempt(fn () => $this->catalogue->removePackaging($row), 'Packaging level removed.');
    }

    public function storeBarcode(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:64'],
            'symbology' => ['nullable', Rule::in(ItemBarcode::SYMBOLOGIES)],
            'source' => ['nullable', Rule::in(ItemBarcode::SOURCES)],
            'packaging_id' => ['nullable', 'string'],
            'supplier_id' => ['nullable', 'integer', $this->tenantExists('suppliers', 'id', true)],
            'is_primary' => ['boolean'],
            'duplicate_reason' => ['nullable', 'string', 'max:500'],
        ]);
        if (! empty($data['packaging_id'])) {
            $data['packaging_id'] = ItemPackaging::query()->where('stock_item_id', $item->id)->where('uuid', $data['packaging_id'])->value('id');
        }

        return $this->attempt(fn () => $this->barcodes->register($item, $data, $data['duplicate_reason'] ?? null,
            $request->user()->can('approveDuplicateBarcode', $item)), 'Barcode registered.');
    }

    public function generateBarcode(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $pack = $request->filled('packaging_id')
            ? ItemPackaging::query()->where('stock_item_id', $item->id)->where('uuid', $request->input('packaging_id'))->firstOrFail() : null;

        return $this->attempt(fn () => $this->barcodes->generateInternal($item, $pack), 'Internal barcode generated.');
    }

    public function destroyBarcode(string $uuid, string $barcode): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $row = ItemBarcode::query()->where('stock_item_id', $item->id)->where('uuid', $barcode)->firstOrFail();

        return $this->attempt(fn () => $this->barcodes->deactivate($row), 'Barcode deactivated.');
    }

    public function storeIdentifier(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $data = $request->validate([
            'kind' => ['required', Rule::in(ItemIdentifier::KINDS)],
            'code' => ['required', 'string', 'max:120'],
            'supplier_id' => ['nullable', 'integer', $this->tenantExists('suppliers', 'id', true)],
            'party_name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(fn () => $this->catalogue->addIdentifier($item, $data), 'Code added.');
    }

    public function destroyIdentifier(string $uuid, string $identifier): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $row = ItemIdentifier::query()->where('stock_item_id', $item->id)->where('uuid', $identifier)->firstOrFail();

        return $this->attempt(fn () => $this->catalogue->removeIdentifier($row), 'Code removed.');
    }

    public function bundle(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $data = $request->validate([
            'components' => ['present', 'array'],
            'components.*.id' => ['required', 'string'],
            'components.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);
        $map = [];
        foreach ($data['components'] as $c) {
            $map[$this->find($c['id'])->id] = (float) $c['qty'];
        }

        return $this->attempt(fn () => $this->catalogue->setBundle($item, $map), 'Bundle saved.');
    }

    public function storeSubstitute(Request $request, string $uuid): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $data = $request->validate([
            'substitute_id' => ['required', 'string'],
            'ratio' => ['required', 'numeric', 'gt:0'],
            'requires_approval' => ['boolean'],
            'restriction' => ['nullable', 'string', 'max:500'],
        ]);
        $sub = $this->find($data['substitute_id']);

        return $this->attempt(fn () => $this->catalogue->addSubstitute($item, $sub, (float) $data['ratio'], (bool) ($data['requires_approval'] ?? true), $data['restriction'] ?? null), 'Substitute added.');
    }

    public function destroySubstitute(string $uuid, string $substitute): RedirectResponse
    {
        $item = $this->find($uuid);
        $this->authorize('update', $item);
        $mfg = $this->catalogue->mfgProfile($item);

        return $this->attempt(function () use ($mfg, $substitute, $item) {
            $row = ItemSubstitute::query()->where('item_id', $mfg->id)->where('uuid', $substitute)->firstOrFail();
            $row->update(['is_active' => false]);
            $this->catalogue->event($item, 'SUBSTITUTE', ['substitute' => [$row->substitute_stock_item_id, null]]);
        }, 'Substitute removed.');
    }

    /** Item search for pickers (bundles, substitutes). */
    public function search(Request $request)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $q = trim((string) $request->input('q', ''));

        return response()->json(FinStockItem::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'ilike', "%{$q}%")->orWhere('name', 'ilike', "%{$q}%")))
            ->orderBy('code')->limit(20)->get(['uuid', 'code', 'name'])
            ->map(fn ($i) => ['id' => $i->uuid, 'title' => "{$i->code} — {$i->name}"]));
    }

    /** WMS-012 scan tester: what the warehouse understands a scanned code to be. */
    public function scan(Request $request): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $code = (string) $request->input('code', '');
        $result = null;
        $error = null;

        if ($code !== '') {
            // A scanner's FNC1 arrives as the GS control character; typed by hand it is usually written <GS>.
            $raw = str_replace(['[GS]', '<GS>', '{GS}'], "\x1D", $code);
            try {
                $r = $this->barcodes->resolve($raw, $request->integer('supplier_id') ?: null);
                $result = [
                    'item' => ['id' => $r['item']->uuid, 'code' => $r['item']->code, 'name' => $r['item']->name, 'status' => $r['item']->status, 'uom' => $r['item']->uom],
                    'packaging' => $r['packaging']?->level,
                    'qty_in_base' => $r['qty_in_base'],
                ] + array_intersect_key($r, array_flip(['matched_by', 'batch', 'serial', 'expiry', 'production_date', 'best_before', 'count', 'net_weight_kg', 'gs1']));
            } catch (InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return Inertia::render('Modules/Warehouse/Products/Scan', ['code' => $code, 'result' => $result, 'error' => $error]);
    }

    /** The same resolution as the scan tester, as JSON, for scanning into a form (receiving, picking). */
    public function resolve(Request $request)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $raw = str_replace(['[GS]', '<GS>', '{GS}'], "\x1D", (string) $request->input('code', ''));
        try {
            $r = $this->barcodes->resolve($raw, $request->integer('supplier_id') ?: null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'item' => ['id' => $r['item']->uuid, 'code' => $r['item']->code, 'name' => $r['item']->name, 'status' => $r['item']->status, 'uom' => $r['item']->uom],
            'packaging' => $r['packaging']?->level, 'qty_in_base' => $r['qty_in_base'],
        ] + array_intersect_key($r, array_flip(['matched_by', 'batch', 'serial', 'expiry', 'production_date', 'best_before', 'count', 'net_weight_kg'])));
    }

    /** Product labels carrying the primary barcode, for shelves, cartons and pallets. */
    public function labels(Request $request, LabelSheet $sheet): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $ids = array_filter((array) $request->input('items', []));
        $items = FinStockItem::query()->when($ids, fn ($q) => $q->whereIn('uuid', $ids), fn ($q) => $q->whereRaw('1 = 0'))->orderBy('code')->limit(200)->get();

        return Inertia::render('Modules/Warehouse/Products/Labels', [
            'labels' => $sheet->forItems($items, $request->input('level')),
            'level' => $request->input('level'),
            'copies' => max(1, min(50, $request->integer('copies', 1))),
        ]);
    }

    private function packagingRules(Request $request): array
    {
        return $request->validate([
            'level' => ['required', Rule::in(ItemPackaging::LEVELS)],
            'name' => ['nullable', 'string', 'max:80'],
            'uom_id' => ['required', 'integer', $this->tenantExists('mfg_uoms')],
            'qty_in_base' => ['required', 'numeric', 'gt:0'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }

    private function units(): array
    {
        // Named by company: a platform administrator has no tenant scope to narrow these for them.
        $cid = $this->companyId(request());

        return Uom::query()->when($cid, fn ($q) => $q->where('company_id', $cid))->where('is_active', true)->orderBy('dimension')->orderBy('code')->get(['id', 'code', 'name', 'dimension'])
            ->map(fn ($u) => ['id' => $u->id, 'code' => $u->code, 'title' => "{$u->code} — {$u->name}", 'dimension' => $u->dimension])->all();
    }

    private function find(string $uuid): FinStockItem
    {
        return FinStockItem::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function companyId(Request $request): ?int
    {
        return app(CurrentCompany::class)->id() ?? $request->user()?->company_id;
    }
}
