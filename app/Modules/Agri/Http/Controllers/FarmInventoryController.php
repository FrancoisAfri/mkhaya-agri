<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Core\User\Trait\FileStorage;
use App\Modules\Agri\Models\AgriItemSetting;
use App\Modules\Agri\Models\ChemicalProduct;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmStore;
use App\Modules\Agri\Models\SupplierDispute;
use App\Modules\Agri\Services\FarmInventory;
use App\Modules\Agri\Services\FarmProcurement;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Operations\WarehouseManagement\Models\TransferOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 2 §5: stock control and purchasing from the farm's side — its stores, transfers between
 * them, holds, reorder suggestions and value; the chemical register; and orders, backorders,
 * price variance, disputes and supplier performance.
 */
class FarmInventoryController extends AgriController
{
    use FileStorage;

    public const TABS = ['stores', 'transfers', 'reorder', 'chemicals', 'purchasing', 'suppliers'];

    public function __construct(private readonly FarmInventory $inventory, private readonly FarmProcurement $procurement) {}

    public function index(Request $request, string $tab = 'stores'): Response
    {
        abort_unless(in_array($tab, self::TABS, true), 404);
        $this->allow($request, R::VIEW_STOCK);
        $farms = Farm::query()->operational()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->first();
        $stores = $farm ? $this->inventory->storesOf($farm) : collect();

        return Inertia::render('Modules/Agri/Stock/Control', [
            'tab' => $tab,
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'stores' => $stores->map(fn (FarmStore $s) => ['id' => $s->uuid, 'name' => $s->name, 'kind' => $s->kind, 'restricted' => $s->restricted,
                'allowed_storage_classes' => $s->allowed_storage_classes ?? [], 'capacity' => $s->capacity, 'capacity_unit' => $s->capacity_unit])->values(),
            'data' => $farm ? match ($tab) {
                'stores' => ['valuation' => $this->inventory->valuation($farm), 'held' => $this->held($stores)],
                'transfers' => ['orders' => $this->transfers($stores)],
                'reorder' => ['suggestions' => $this->inventory->reorderSuggestions($farm)],
                'chemicals' => ['products' => ChemicalProduct::query()->orderBy('id')->get()->map(fn ($c) => $this->chemicalRow($c)),
                    'settings' => AgriItemSetting::query()->get()->map(fn ($x) => $x->toArray() + ['item' => FinStockItem::withoutGlobalScopes()->whereKey($x->stock_item_id)->value('name')])],
                'purchasing' => ['orders' => $this->procurement->orders($farm), 'variance' => $this->procurement->priceVariance($farm, today()->subYear()->toDateString()),
                    'disputes' => SupplierDispute::query()->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $farm->id))->orderByDesc('id')->limit(100)->get()
                        ->map(fn ($d) => $d->toArray() + ['supplier' => DB::table('suppliers')->where('id', $d->supplier_id)->value('company_name')])],
                'suppliers' => ['suppliers' => $this->procurement->suppliers(today()->subDays(365), today())],
            } : [],
            'options' => [
                'items' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'code', 'name', 'uom'])
                    ->map(fn ($i) => ['value' => $i->id, 'title' => "{$i->name} ({$i->code})"]),
                'suppliers' => DB::table('suppliers')->where('company_id', $request->user()->company_id)->orderBy('company_name')->limit(500)->get(['id', 'company_name'])
                    ->map(fn ($s) => ['value' => $s->id, 'title' => $s->company_name]),
                'kinds' => FarmStore::KINDS, 'categories' => AgriItemSetting::CATEGORIES, 'storage_classes' => ChemicalProduct::STORAGE_CLASSES,
                'dispute_kinds' => SupplierDispute::KINDS, 'competencies' => app(\App\Modules\HR\Hr\Attendance\Services\HrSources::class)->competencies((int) $request->user()->company_id),
                'people' => $this->tenantPeopleOptions($request),
            ],
            'can' => ['manage' => $request->user()->can(R::RECEIVE_STOCK), 'issue' => $request->user()->can(R::ISSUE_STOCK), 'finance' => $request->user()->can(R::MANAGE_FINANCE)],
        ]);
    }

    public function addStore(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $data = $request->validate(['farm_id' => ['required', 'string'], 'kind' => ['required', Rule::in(FarmStore::KINDS)], 'name' => ['required', 'string', 'max:120'],
            'capacity' => ['nullable', 'numeric', 'gt:0'], 'capacity_unit' => ['nullable', 'string', 'max:12'], 'restricted' => ['boolean'],
            'allowed_storage_classes' => ['nullable', 'array'], 'allowed_storage_classes.*' => [Rule::in(ChemicalProduct::STORAGE_CLASSES)]]);
        $store = $this->inventory->addStore($this->workingFarm($request, $data['farm_id']), $data);

        return back()->with('success', "{$store->name} added.");
    }

    public function updateStore(Request $request, FarmStore $store): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $this->assertWritable($request, $store->farm_id);
        $store->update($request->validate(['name' => ['required', 'string', 'max:120'], 'capacity' => ['nullable', 'numeric', 'gt:0'], 'capacity_unit' => ['nullable', 'string', 'max:12'],
            'restricted' => ['boolean'], 'allowed_storage_classes' => ['nullable', 'array'], 'allowed_storage_classes.*' => [Rule::in(ChemicalProduct::STORAGE_CLASSES)]]));

        return back()->with('success', 'Store updated.');
    }

    public function itemSetting(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $data = $request->validate(['stock_item_id' => ['required', 'integer'], 'category' => ['required', Rule::in(AgriItemSetting::CATEGORIES)],
            'preferred_supplier_id' => ['nullable', 'integer'], 'lot_tracked' => ['boolean'], 'serial_tracked' => ['boolean']]);
        $this->inventory->itemSetting(FinStockItem::query()->findOrFail($data['stock_item_id']), $data);

        return back()->with('success', 'Item settings saved.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $this->allow($request, R::ISSUE_STOCK);
        $data = $request->validate(['from_store_id' => ['required', 'string'], 'to_store_id' => ['required', 'string', 'different:from_store_id'],
            'reason' => ['nullable', 'string', 'max:300'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.item_id' => ['required', 'integer'], 'lines.*.qty' => ['required', 'numeric', 'gt:0']]);
        $from = FarmStore::query()->where('uuid', $data['from_store_id'])->firstOrFail();
        $to = FarmStore::query()->where('uuid', $data['to_store_id'])->firstOrFail();
        $this->assertWritable($request, $from->farm_id);
        $order = $this->inventory->transfer($from, $to, $data['lines'], $request->user(), $data['reason'] ?? null);

        return back()->with('success', "Transfer {$order->number} approved. {$from->name} dispatches it; {$to->name} confirms what arrives.");
    }

    public function transferStep(Request $request, string $uuid): RedirectResponse
    {
        $this->allow($request, R::ISSUE_STOCK);
        $order = TransferOrder::query()->where('uuid', $uuid)->with('lines')->firstOrFail();
        $data = $request->validate(['step' => ['required', Rule::in(['dispatch', 'receive'])], 'received' => ['nullable', 'array']]);
        $data['step'] === 'dispatch' ? $this->inventory->dispatch($order, $request->user())
            : $this->inventory->receive($order, $data['received'] ?? $order->lines->mapWithKeys(fn ($l) => [$l->id => (float) $l->dispatched_qty - (float) $l->received_qty])->all(), $request->user());

        return back()->with('success', $data['step'] === 'dispatch' ? "Transfer {$order->number} dispatched." : "Transfer {$order->number} received.");
    }

    public function hold(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $data = $request->validate(['store_id' => ['required', 'string'], 'item_id' => ['required', 'integer'], 'qty' => ['required', 'numeric', 'gt:0'],
            'from' => ['required', Rule::in(['AVAILABLE', 'QUARANTINE', 'DAMAGED', 'REJECTED'])], 'to' => ['required', Rule::in(['AVAILABLE', 'QUARANTINE', 'DAMAGED', 'REJECTED']), 'different:from'],
            'reason' => ['required', 'string', 'max:300']]);
        $store = FarmStore::query()->where('uuid', $data['store_id'])->firstOrFail();
        $this->assertWritable($request, $store->farm_id);
        $this->inventory->changeStatus($store, FinStockItem::query()->findOrFail($data['item_id']), (float) $data['qty'], $data['from'], $data['to'], $data['reason'], $request->user());

        return back()->with('success', 'Stock moved to '.strtolower($data['to']).'.');
    }

    public function saveChemical(Request $request, ?ChemicalProduct $chemical = null): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $data = $request->validate(['stock_item_id' => ['required', 'integer'], 'registration_no' => ['nullable', 'string', 'max:40'], 'active_ingredient' => ['nullable', 'string', 'max:200'],
            'formulation' => ['nullable', 'string', 'max:40'], 'hazard_class' => ['nullable', 'string', 'max:30'], 'storage_class' => ['required', Rule::in(ChemicalProduct::STORAGE_CLASSES)],
            'incompatible_with' => ['nullable', 'array'], 'incompatible_with.*' => [Rule::in(ChemicalProduct::STORAGE_CLASSES)], 'ppe' => ['nullable', 'string', 'max:300'],
            'handling' => ['nullable', 'string', 'max:1000'], 'reentry_hours' => ['nullable', 'integer', 'min:0', 'max:2000'], 'withholding_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'restricted' => ['boolean'], 'competency_code' => ['nullable', 'string', 'max:40'], 'authorised_user_ids' => ['nullable', 'array'], 'authorised_user_ids.*' => ['integer'],
            'registration_expires_on' => ['nullable', 'date'], 'sds_revised_on' => ['nullable', 'date'],
            'label' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'], 'sds' => ['nullable', 'file', 'max:10240', 'mimes:pdf']]);
        foreach (['label', 'sds'] as $f) {
            if ($request->hasFile($f)) {
                $file = $this->saveFileToDatabase($request->file($f));
                $data += ["{$f}_name" => $file['original_name'], "{$f}_mime" => $file['mime'], "{$f}_data" => $file['data']];
            }
            unset($data[$f]);
        }
        $chemical?->exists ? $chemical->update($data) : ChemicalProduct::create($data + ['company_id' => $request->user()->company_id]);

        return back()->with('success', 'Chemical register updated.');
    }

    public function chemicalFile(Request $request, ChemicalProduct $chemical, string $which)
    {
        $this->allow($request, R::VIEW_STOCK);
        abort_unless(in_array($which, ['label', 'sds'], true) && $chemical->{"{$which}_data"}, 404);
        $mime = in_array($chemical->{"{$which}_mime"}, ['application/pdf', 'image/jpeg', 'image/png'], true) ? $chemical->{"{$which}_mime"} : 'application/octet-stream';

        return response(base64_decode($chemical->{"{$which}_data"}), 200, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.addslashes($chemical->{"{$which}_name"} ?? $which).'"']);
    }

    public function dispute(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK, R::MANAGE_FINANCE);
        $data = $request->validate(['farm_id' => ['nullable', 'string'], 'supplier_id' => ['required', 'integer'], 'purchase_order_id' => ['nullable', 'integer'],
            'kind' => ['required', Rule::in(SupplierDispute::KINDS)], 'description' => ['required', 'string', 'max:2000'], 'amount' => ['nullable', 'numeric', 'gte:0']]);
        $farm = ! empty($data['farm_id']) ? $this->workingFarm($request, $data['farm_id']) : null;
        $this->procurement->dispute($farm, $data, $request->user());

        return back()->with('success', 'Dispute recorded against the supplier.');
    }

    public function settleDispute(Request $request, SupplierDispute $dispute): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK, R::MANAGE_FINANCE);
        $data = $request->validate(['outcome' => ['required', Rule::in(['resolved', 'rejected'])], 'resolution' => ['required', 'string', 'max:1000']]);
        $this->procurement->settle($dispute, $data['outcome'], $data['resolution'], $request->user());

        return back()->with('success', 'Dispute '.$data['outcome'].'.');
    }

    public function supplierEvidence(Request $request, string $supplier): JsonResponse
    {
        $this->allow($request, R::VIEW_STOCK);
        // Suppliers are addressed by uuid in URLs, like everywhere else in the platform.
        $id = DB::table('suppliers')->where('uuid', $supplier)->where('company_id', $request->user()->company_id)->value('id');
        abort_unless($id, 404);

        return response()->json($this->procurement->evidence((int) $id, today()->subDays(365), today()));
    }

    private function held($stores): array
    {
        return DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')
            ->whereIn('p.warehouse_id', $stores->pluck('warehouse_id'))->where('p.qty', '>', 0)->where('p.status', '!=', FinStockPosition::AVAILABLE)
            ->get(['p.warehouse_id', 'p.status', 'p.qty', 'i.name', 'i.uom', 'i.id as item_id'])
            ->map(fn ($r) => ['store' => $stores->firstWhere('warehouse_id', $r->warehouse_id)?->name, 'store_id' => $stores->firstWhere('warehouse_id', $r->warehouse_id)?->uuid,
                'status' => $r->status, 'item' => $r->name, 'item_id' => $r->item_id, 'qty' => (float) $r->qty, 'uom' => $r->uom])->all();
    }

    private function transfers($stores): array
    {
        $whs = $stores->pluck('warehouse_id');

        return TransferOrder::query()->where(fn ($q) => $q->whereIn('from_warehouse_id', $whs)->orWhereIn('to_warehouse_id', $whs))
            ->with('lines')->orderByDesc('id')->limit(100)->get()
            ->map(fn ($o) => ['id' => $o->uuid, 'number' => $o->number, 'status' => $o->status, 'reason' => $o->reason,
                'from' => $stores->firstWhere('warehouse_id', $o->from_warehouse_id)?->name ?? 'elsewhere', 'to' => $stores->firstWhere('warehouse_id', $o->to_warehouse_id)?->name ?? 'elsewhere',
                'lines' => $o->lines->map(fn ($l) => ['item' => FinStockItem::withoutGlobalScopes()->whereKey($l->item_id)->value('name'), 'qty' => (float) $l->qty,
                    'dispatched' => (float) $l->dispatched_qty, 'received' => (float) $l->received_qty])->all(),
                'created' => $o->created_at?->toDateString()])->all();
    }

    private function chemicalRow(ChemicalProduct $c): array
    {
        return collect($c->toArray())->except(['label_data', 'sds_data'])->all() + ['item' => FinStockItem::withoutGlobalScopes()->whereKey($c->stock_item_id)->value('name'),
            'label' => (bool) $c->label_name, 'sds' => (bool) $c->sds_name, 'expired' => $c->registration_expires_on?->isPast() ?? false];
    }
}
