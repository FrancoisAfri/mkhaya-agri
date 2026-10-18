<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Services\Allocator;
use App\Modules\Operations\WarehouseManagement\Services\BarcodeRegistry;
use App\Modules\Operations\WarehouseManagement\Services\FulfilmentOrders;
use App\Modules\Operations\WarehouseManagement\Services\StockAvailability;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orders from web shops and other systems (WMS-060), and what they need back: where an order is
 * and what can be promised.
 *
 * Sending the same order twice -- the normal outcome of a timeout and a retry -- returns the order
 * already made with 200, not a second order with 201. Lines name an item by SKU or any barcode the
 * warehouse knows it by.
 */
class OrderApiController extends Controller
{
    public function store(Request $request, FulfilmentOrders $orders, BarcodeRegistry $barcodes, Allocator $allocator): JsonResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_ORDERS, 'wms:orders');
        $data = $request->validate([
            'warehouse' => ['required', 'string'], 'source' => ['nullable', Rule::in(['ECOMMERCE', 'API'])],
            'external_ref' => ['required', 'string', 'max:120'], 'customer_name' => ['nullable', 'string', 'max:160'],
            'ship_to' => ['nullable', 'array'], 'required_by' => ['nullable', 'date'], 'priority' => ['nullable', 'integer', 'between:1,100'],
            'allow_partial' => ['boolean'], 'release' => ['boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.sku' => ['required_without:lines.*.barcode', 'nullable', 'string'],
            'lines.*.barcode' => ['nullable', 'string'], 'lines.*.qty' => ['required', 'numeric', 'gt:0'], 'lines.*.uom' => ['nullable', 'string'],
        ]);
        $warehouse = FinWarehouse::query()->where('code', $data['warehouse'])->where('is_active', true)->first();
        if (! $warehouse) {
            return response()->json(['message' => "No active warehouse with code {$data['warehouse']}."], 422);
        }

        try {
            $lines = array_map(function ($l) use ($barcodes) {
                $item = ! empty($l['sku']) ? FinStockItem::query()->where('code', $l['sku'])->first() : $barcodes->resolve((string) $l['barcode'])['item'];
                if (! $item) {
                    throw new RuntimeException("Unknown SKU {$l['sku']}.");
                }

                return ['item_id' => $item->id, 'qty' => $l['qty'], 'uom_id' => ! empty($l['uom']) ? Uom::query()->where('code', $l['uom'])->value('id') : null];
            }, $data['lines']);

            [$order, $created] = $orders->create($warehouse, $data['source'] ?? 'API', $data['external_ref'], $lines, array_filter([
                'customer_name' => $data['customer_name'] ?? null, 'ship_to' => $data['ship_to'] ?? null, 'required_by' => $data['required_by'] ?? null,
                'priority' => $data['priority'] ?? null, 'allow_partial' => $data['allow_partial'] ?? null,
            ], fn ($v) => $v !== null), $request->user());

            if ($created && ($data['release'] ?? true)) {
                $allocator->allocate($orders->release($order));
            }
        } catch (RuntimeException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->present($order->fresh('lines.item')), $created ? 201 : 200);
    }

    public function show(Request $request, string $ref): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');
        $order = FulfilmentOrder::query()->where(fn ($q) => $q->where('number', $ref)->orWhere('external_ref', $ref))
            ->with(['lines.item', 'shipments'])->firstOrFail();

        return response()->json($this->present($order));
    }

    public function cancel(Request $request, string $ref, FulfilmentOrders $orders): JsonResponse
    {
        $this->allow($request, RoleCatalog::MANAGE_ORDERS, 'wms:orders');
        $order = FulfilmentOrder::query()->where(fn ($q) => $q->where('number', $ref)->orWhere('external_ref', $ref))->firstOrFail();
        try {
            $orders->cancel($order, (string) $request->input('reason', 'Cancelled by the ordering system'), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($this->present($order->fresh('lines.item')));
    }

    /** What can be promised, per warehouse -- for a web shop's stock display. */
    public function stock(Request $request, StockAvailability $availability): JsonResponse
    {
        $this->allow($request, RoleCatalog::VIEW, 'wms:read');
        $codes = array_filter(explode(',', (string) $request->input('sku', '')));
        $items = FinStockItem::query()->whereIn('code', $codes)->get()->keyBy('id');
        $warehouses = FinWarehouse::query()->pluck('code', 'id');

        return response()->json(collect($availability->rows($items->keys()->all()))->map(fn ($r) => [
            'sku' => $items[$r['item_id']]->code, 'warehouse' => $warehouses[$r['warehouse_id']] ?? null,
            'available_to_promise' => $r['available_to_promise'], 'on_hand' => $r['physical'], 'expected' => $r['expected_receipts'],
        ])->values());
    }

    private function present(FulfilmentOrder $o): array
    {
        return [
            'number' => $o->number, 'external_ref' => $o->external_ref, 'source' => $o->source, 'status' => $o->status,
            'sla_due_at' => $o->sla_due_at?->toIso8601String(), 'dispatched_at' => $o->dispatched_at?->toIso8601String(),
            'lines' => $o->lines->map(fn ($l) => ['sku' => $l->item?->code, 'qty' => (float) $l->qty, 'allocated' => (float) $l->qty_allocated,
                'picked' => (float) $l->qty_picked, 'dispatched' => (float) $l->qty_dispatched, 'cancelled' => (float) $l->qty_cancelled])->values(),
            'shipments' => $o->shipments->map(fn ($s) => ['number' => $s->number, 'carrier' => $s->carrier, 'tracking_reference' => $s->tracking_reference,
                'status' => $s->status, 'delivered_at' => $s->delivered_at?->toIso8601String()])->values(),
        ];
    }

    /** The user must hold the permission, and a token limited to abilities must carry this one. */
    private function allow(Request $request, string $permission, string $ability): void
    {
        $user = $request->user();
        abort_unless($user->can($permission), 403, 'This user may not do that in the warehouse.');
        $token = $user->currentAccessToken();
        if ($token && method_exists($token, 'can') && ! $token->can($ability) && ! $token->can('*')) {
            abort(403, "This token lacks the {$ability} ability.");
        }
    }
}
