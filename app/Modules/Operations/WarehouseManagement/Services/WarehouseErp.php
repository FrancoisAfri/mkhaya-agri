<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Integration;
use App\Modules\Operations\Manufacturing\Models\IntegrationEvent as Event;
use App\Modules\Operations\Manufacturing\Services\IntegrationHub;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The warehouse's side of connections to an ERP or commerce system (WMS-171), carried by
 * Manufacturing's integration hub -- its connections, identifier mappings, idempotency keys,
 * retries and dead letters -- rather than a second framework.
 *
 * In: orders to fulfil (WMS_ORDER) and the other system's stock levels to reconcile against
 * (WMS_STOCK_LEVELS). Out: receipts, dispatches, adjustments and returns, taken from the warehouse's
 * own event outbox so the other system hears exactly what webhook subscribers hear.
 *
 * Products map as STOCK_ITEM and warehouses as WAREHOUSE; without a mapping the SKU or warehouse
 * code is tried, and a message naming neither fails into the error queue rather than guessing.
 */
class WarehouseErp
{
    /** Export name in an integration's settings => [outbox event type, outgoing message type, optional movement filter]. */
    public const EXPORTS = [
        'WMS_RECEIPTS' => ['warehouse.receipt.posted', 'WMS_RECEIPT', null],
        'WMS_DISPATCHES' => ['warehouse.shipment.dispatched', 'WMS_DISPATCH', null],
        'WMS_ADJUSTMENTS' => ['warehouse.stock.moved', 'WMS_ADJUSTMENT', 'adjustment'],
        'WMS_RETURNS' => ['warehouse.return.status_changed', 'WMS_RETURN', null],
    ];

    public const ENTITIES = ['STOCK_ITEM', 'WAREHOUSE'];

    public function register(IntegrationHub $hub): void
    {
        $hub->handles('WMS_ORDER', fn (Integration $i, Event $e) => $this->order($i, $e, $hub));
        $hub->handles('WMS_STOCK_LEVELS', fn (Integration $i, Event $e) => $this->stockLevels($i, $e, $hub));
        foreach (array_keys(self::EXPORTS) as $name) {
            $hub->exportsWith($name, fn (Integration $i) => $this->queue($i, $name));
        }
    }

    private function order(Integration $i, Event $e, IntegrationHub $hub): array
    {
        $d = $e->payload;
        $warehouse = $this->warehouse($i, (string) ($d['warehouse'] ?? ''), $hub);
        $lines = array_map(fn ($l) => ['item_id' => $this->item($i, (string) ($l['product'] ?? ''), $hub)->id, 'qty' => (float) ($l['qty'] ?? 0)], (array) ($d['lines'] ?? []));
        if ($lines === []) {
            throw new RuntimeException('The order has no lines.');
        }
        [$order] = app(FulfilmentOrders::class)->create($warehouse, 'API', (string) ($d['order_ref'] ?? $e->external_id), $lines, array_filter([
            'customer_name' => $d['customer'] ?? null, 'ship_to' => $d['ship_to'] ?? null, 'required_by' => $d['required_by'] ?? null,
        ], fn ($v) => $v !== null));

        return ['WMS_ORDER', $order->id, $order->number];
    }

    /** Compare the other system's stock with the ledger's, per product, for one warehouse. */
    private function stockLevels(Integration $i, Event $e, IntegrationHub $hub): array
    {
        $d = $e->payload;
        $warehouse = $this->warehouse($i, (string) ($d['warehouse'] ?? ''), $hub);
        $lines = [];
        $unmapped = 0;
        foreach ((array) ($d['lines'] ?? []) as $l) {
            try {
                $item = $this->item($i, (string) ($l['product'] ?? ''), $hub);
            } catch (RuntimeException) {
                $unmapped++;
                $lines[] = ['product' => $l['product'] ?? null, 'item' => null, 'theirs' => (float) ($l['qty'] ?? 0), 'ours' => null, 'difference' => null];

                continue;
            }
            $ours = (float) DB::table('fin_stock_positions')->where('company_id', $i->company_id)->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->sum('qty');
            $theirs = (float) ($l['qty'] ?? 0);
            $lines[] = ['product' => $l['product'], 'item' => $item->code, 'theirs' => $theirs, 'ours' => round($ours, 4), 'difference' => round($ours - $theirs, 4)];
        }
        $differences = collect($lines)->filter(fn ($l) => $l['difference'] !== null && abs($l['difference']) > 1e-6)->count();
        $id = DB::table('wms_erp_reconciliations')->insertGetId(['company_id' => $i->company_id, 'integration_id' => $i->id, 'integration_event_id' => $e->id,
            'compared' => count($lines) - $unmapped, 'differences' => $differences, 'unmapped' => $unmapped, 'lines' => json_encode($lines), 'created_at' => now()]);

        return ['WMS_RECONCILIATION', $id, "{$differences} difference(s), {$unmapped} unmapped"];
    }

    /** Queue the outbox's events of one kind for the other system, once each. */
    public function queue(Integration $i, string $name): int
    {
        [$type, $message, $movement] = self::EXPORTS[$name];
        $hub = app(IntegrationHub::class);
        $rows = DB::table('wms_outbox')->where('company_id', $i->company_id)->where('event_type', $type)
            ->where('occurred_at', '>=', $i->validated_at ?? $i->created_at)
            ->when($movement, fn ($q, $m) => $q->whereRaw("payload->>'movement_type' = ?", [$m]))
            ->whereNotExists(fn ($q) => $q->from('mfg_integration_events as x')->where('x.integration_id', $i->id)
                ->whereRaw("x.idempotency_key = 'WMS:' || wms_outbox.event_id::text"))
            ->orderBy('id')->limit(500)->get();
        foreach ($rows as $row) {
            $env = WarehouseEvents::envelope($row);
            $data = $env['data'];
            if (isset($data['item']) && ($id = DB::table('fin_stock_items')->where('company_id', $i->company_id)->where('code', $data['item'])->value('id'))) {
                $data['product'] = $hub->external($i, 'STOCK_ITEM', (int) $id) ?? $data['item'];
            }
            if ($row->warehouse_id) {
                $data['warehouse_ref'] = $hub->external($i, 'WAREHOUSE', (int) $row->warehouse_id) ?? $env['warehouse'];
            }
            Event::create(['company_id' => $i->company_id, 'integration_id' => $i->id, 'direction' => 'OUT', 'event_type' => $message,
                'external_id' => $row->event_id, 'idempotency_key' => 'WMS:'.$row->event_id, 'status' => 'RECEIVED',
                'payload' => ['event_id' => $row->event_id, 'type' => $row->event_type, 'schema_version' => (int) $row->schema_version, 'data' => $data]]);
        }

        return $rows->count();
    }

    private function warehouse(Integration $i, string $ref, IntegrationHub $hub): FinWarehouse
    {
        $id = $hub->localOrNull($i, 'WAREHOUSE', $ref);
        $w = FinWarehouse::withoutGlobalScopes()->where('company_id', $i->company_id)
            ->when($id, fn ($q) => $q->whereKey($id), fn ($q) => $q->where('code', $ref))->first();

        return $w ?? throw new RuntimeException("No warehouse is mapped to \"{$ref}\".");
    }

    private function item(Integration $i, string $ref, IntegrationHub $hub): FinStockItem
    {
        $id = $hub->localOrNull($i, 'STOCK_ITEM', $ref);
        $item = FinStockItem::withoutGlobalScopes()->where('company_id', $i->company_id)
            ->when($id, fn ($q) => $q->whereKey($id), fn ($q) => $q->where('code', $ref))->first();

        return $item ?? throw new RuntimeException("No product is mapped to \"{$ref}\".");
    }
}
