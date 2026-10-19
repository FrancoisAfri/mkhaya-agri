<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Services\FarmStores;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Finance\Accounting\Exceptions\PostingException;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Procurement\Models\PurchaseRequestItem;
use App\Modules\Procurement\Services\PurchaseRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module D: each farm's store in the shared inventory — what is on hand, receipts and opening
 * balances, and purchase requisitions raised through Procurement.
 */
class FarmStockController extends AgriController
{
    public function __construct(private readonly FarmStores $stores, private readonly StockLedger $ledger) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_STOCK);
        $farms = Farm::query()->orderBy('name')->get();
        $farm = $this->farmFilter($request) ?? $farms->firstWhere('warehouse_id', '!=', null) ?? $farms->first();

        $items = collect();
        $movements = collect();
        if ($farm?->warehouse_id) {
            $positions = DB::table('fin_stock_movements')->where('warehouse_id', $farm->warehouse_id)
                ->groupBy('item_id')->selectRaw('item_id, SUM(qty) as on_hand, MAX(movement_date) as last_moved')->get()->keyBy('item_id');
            $items = FinStockItem::query()->whereIn('id', $positions->keys())->orderBy('name')->get()
                ->map(fn (FinStockItem $i) => [
                    'id' => $i->id, 'code' => $i->code, 'name' => $i->name, 'uom' => $i->uom,
                    'on_hand' => round((float) $positions[$i->id]->on_hand, 4),
                    'available' => round($this->ledger->available($i, (int) $farm->warehouse_id), 4),
                    'reorder_level' => (float) $i->reorder_level, 'avg_cost' => round((float) $i->avg_cost, 4),
                    'value' => round((float) $positions[$i->id]->on_hand * (float) $i->avg_cost, 2),
                    'last_moved' => $positions[$i->id]->last_moved,
                    'low' => (float) $i->reorder_level > 0 && $this->ledger->available($i, (int) $farm->warehouse_id) < (float) $i->reorder_level,
                ]);
            $movements = FinStockMovement::query()->where('warehouse_id', $farm->warehouse_id)->with('item:id,name,uom')
                ->orderByDesc('id')->limit(50)->get()
                ->map(fn ($m) => ['date' => $m->movement_date?->toDateString() ?? (string) $m->movement_date, 'type' => $m->type,
                    'item' => $m->item?->name, 'qty' => (float) $m->qty, 'uom' => $m->item?->uom, 'value' => (float) $m->value,
                    'reference' => $m->reference, 'source' => $m->source_type]);
        }

        $requisitions = $farm ? DB::table('purchase_requests')->where('project_code', $farm->code)
            ->where('delivery_location', 'like', '%'.$farm->name.'%')
            ->orderByDesc('id')->limit(20)->get(['uuid', 'request_number', 'status', 'estimated_total', 'needed_by', 'created_at']) : collect();

        return Inertia::render('Modules/Agri/Stock/Index', [
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name, 'code' => $farm->code, 'has_store' => (bool) $farm->warehouse_id] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name])->values(),
            'items' => $items->values(),
            'movements' => $movements,
            'requisitions' => $requisitions,
            'catalogue' => FinStockItem::query()->where('is_active', true)->orderBy('name')->limit(1000)->get(['id', 'code', 'name', 'uom', 'avg_cost', 'reorder_level'])
                ->map(fn ($i) => ['id' => $i->id, 'name' => "{$i->name} ({$i->code})", 'uom' => $i->uom, 'avg_cost' => (float) $i->avg_cost]),
            'enterprises' => $farm ? \App\Modules\Agri\Models\CropPlan::query()->where('farm_id', $farm->id)->where('status', '!=', 'cancelled')->with('cropType')->get()
                ->map(fn ($p) => ['value' => 'crop_plan:'.$p->uuid, 'title' => "Crop: {$p->code} · {$p->cropType?->name}"])
                ->merge(\App\Modules\Agri\Models\LivestockGroup::query()->where('farm_id', $farm->id)->get()
                    ->map(fn ($g) => ['value' => 'livestock_group:'.$g->uuid, 'title' => "Livestock: {$g->code} · {$g->name}"]))->values() : [],
            'can' => ['receive' => $request->user()->can(R::RECEIVE_STOCK), 'sell' => $request->user()->can(R::MANAGE_FINANCE)],
        ]);
    }

    public function openStore(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $farm = $this->workingFarm($request, $request->input('farm_id'));
        $store = $this->stores->storeFor($farm);

        return back()->with('success', "Store {$store->code} opened for {$farm->name}.");
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:150'],
            'uom' => ['required', 'string', 'max:20'],
            'reorder_level' => ['nullable', 'numeric', 'gte:0'],
            'standard_cost' => ['nullable', 'numeric', 'gte:0'],
        ]);
        if (FinStockItem::query()->whereRaw('lower(code) = ?', [mb_strtolower($data['code'])])->exists()) {
            throw ValidationException::withMessages(['code' => 'That item code is already in the stock catalogue.']);
        }
        try {
            app(InventoryService::class)->createItem($data + ['cost_method' => 'weighted_avg', 'is_active' => true, 'reorder_level' => $data['reorder_level'] ?? 0]);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return back()->with('success', "{$data['name']} added to the stock catalogue.");
    }

    public function receive(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'stock_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'gte:0'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'basis' => ['required', Rule::in(['purchase', 'opening'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
        try {
            $this->stores->receive($farm, $item, (float) $data['quantity'], (float) $data['unit_cost'], $data['received_on'], $data['basis'],
                $data['reference'] ?? null, $request->user(), $data['idempotency_key'] ?? null);
        } catch (PostingException $e) {
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('success', "{$data['quantity']} {$item->uom} of {$item->name} received into the {$farm->name} store.");
    }

    public function sell(Request $request): RedirectResponse
    {
        $this->allow($request, \App\Modules\Agri\Support\RoleCatalog::MANAGE_FINANCE);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'stock_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'sold_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:300'],
            'buyer' => ['nullable', 'string', 'max:150'],
            'settled_by' => ['required', Rule::in(['bank', 'receivable'])],
            'enterprise' => ['nullable', 'string', 'max:80'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);
        $item = FinStockItem::query()->findOrFail($data['stock_item_id']);
        $enterprise = null;
        if (! empty($data['enterprise'])) {
            [$type, $uuid] = array_pad(explode(':', $data['enterprise'], 2), 2, null);
            $enterprise = match ($type) {
                'crop_plan' => \App\Modules\Agri\Models\CropPlan::query()->where('uuid', $uuid)->where('farm_id', $farm->id)->first(),
                'livestock_group' => \App\Modules\Agri\Models\LivestockGroup::query()->where('uuid', $uuid)->where('farm_id', $farm->id)->first(),
                default => null,
            };
        }
        $this->stores->sell($farm, $item, (float) $data['quantity'], (float) $data['amount'], $data['sold_on'], $data['description'],
            $data['settled_by'], $enterprise, $data['buyer'] ?? null, $request->user());

        return back()->with('success', "Sold {$data['quantity']} {$item->uom} of {$item->name}; stock and income posted.");
    }

    public function requisition(Request $request, PurchaseRequestService $service): RedirectResponse
    {
        $this->allow($request, R::RECEIVE_STOCK);
        $data = $request->validate([
            'farm_id' => ['required', 'string'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'justification' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.stock_item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'gte:0'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id']);

        $pr = DB::transaction(function () use ($data, $farm, $service, $request) {
            $pr = $service->create([
                'department' => 'Farm: '.$farm->name, 'project_code' => $farm->code, 'urgency' => 'normal',
                'justification' => $data['justification'], 'delivery_location' => $farm->name.($farm->location ? ', '.$farm->location : ''),
                'needed_by' => $data['needed_by'] ?? null, 'currency' => $farm->currency ?: 'ZAR',
            ], $request->user()->id);
            foreach ($data['lines'] as $line) {
                $item = FinStockItem::query()->findOrFail($line['stock_item_id']);
                $price = (float) ($line['unit_price'] ?? $item->avg_cost ?? 0);
                PurchaseRequestItem::create([
                    'purchase_request_id' => $pr->id, 'item_type' => 'goods', 'name' => $item->name,
                    'description' => "{$item->code} for {$farm->name}", 'quantity' => $line['quantity'],
                    'unit_price' => $price, 'line_total' => round($price * (float) $line['quantity'], 2),
                    'source' => 'agri', 'external_ref' => "fin_stock_item:{$item->id}",
                ]);
            }
            $pr->recalcTotal();

            return $pr;
        });

        return back()->with('success', "Requisition {$pr->request_number} drafted in Procurement. Submit it there for approval.");
    }
}
