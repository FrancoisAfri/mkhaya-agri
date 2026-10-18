<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockReservation;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\WarehouseManagement\Models\CustomerReturn;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Models\TrackingEvent;
use Illuminate\Support\Facades\DB;

/**
 * Where warehouse events come from (WMS-172): the records themselves, as they are written. Each
 * listener runs inside the writer's transaction, so an event exists exactly when its record does.
 */
class WarehouseEventSources
{
    public function __construct(private readonly WarehouseEvents $events) {}

    public function listen(): void
    {
        FinStockMovement::created(fn (FinStockMovement $m) => $this->movement($m));
        FinStockReservation::saved(function (FinStockReservation $r) {
            if ($r->wasRecentlyCreated || $r->wasChanged(['status', 'qty', 'qty_consumed'])) {
                $this->reservation($r);
            }
        });
        Shipment::updated(function (Shipment $s) {
            if ($s->wasChanged('status') && $s->status === 'DELIVERED') {
                $this->delivered($s);
            }
        });
        TrackingEvent::created(fn (TrackingEvent $t) => $this->events->record((int) $t->company_id, $this->warehouseOf($t->shipment_id), 'warehouse.shipment.tracking',
            'SHIPMENT', $this->shipmentNumber($t->shipment_id), ['shipment' => $this->shipmentNumber($t->shipment_id), 'status' => $t->status,
                'occurred_at' => $t->occurred_at?->toIso8601String(), 'location' => $t->location, 'source' => $t->source], $t->occurred_at));
        CustomerReturn::saved(function (CustomerReturn $r) {
            if ($r->wasRecentlyCreated || $r->wasChanged('status')) {
                $this->events->record((int) $r->company_id, $r->warehouse_id ? (int) $r->warehouse_id : null, 'warehouse.return.status_changed', 'CUSTOMER_RETURN', (string) $r->number,
                    ['return' => $r->number, 'status' => $r->status, 'customer' => $r->customer_name, 'reference' => $r->reference]);
            }
        });
        LossFinding::created(function (LossFinding $f) {
            if ($f->warehouse_id) {
                $this->events->record((int) $f->company_id, (int) $f->warehouse_id, 'warehouse.exception.raised', 'FINDING', (string) $f->number,
                    ['finding' => $f->number, 'category' => $f->category, 'priority' => $f->priority, 'warehouse' => $this->code((int) $f->warehouse_id),
                        'item' => $f->subject_label, 'summary' => trim("{$f->category}: {$f->subject_label}")]);
            }
        });
    }

    private function movement(FinStockMovement $m): void
    {
        if (! $m->warehouse_id || ! $m->company_id) {
            return;
        }
        $base = ['movement' => $m->uuid, 'movement_type' => $m->type, 'item' => DB::table('fin_stock_items')->where('id', $m->item_id)->value('code'),
            'warehouse' => $this->code((int) $m->warehouse_id), 'qty' => (float) $m->qty,
            'lot' => $m->lot_id ? DB::table('fin_stock_lots')->where('id', $m->lot_id)->value('lot_number') : null,
            'status' => $m->stock_status, 'reference' => $m->reference, 'occurred_at' => ($m->occurred_at ?? $m->created_at ?? now())->toIso8601String()];
        if (in_array($m->type, WarehouseEvents::RECEIVING, true) && (float) $m->qty > 0) {
            $this->events->record((int) $m->company_id, (int) $m->warehouse_id, 'warehouse.receipt.posted', 'MOVEMENT', (string) $m->uuid, $base);
        }
        $this->events->record((int) $m->company_id, (int) $m->warehouse_id, 'warehouse.stock.moved', 'MOVEMENT', (string) $m->uuid,
            $base + ['to_status' => $m->to_stock_status, 'reason_code' => $m->reason_code]);
    }

    private function reservation(FinStockReservation $r): void
    {
        if (! $r->warehouse_id || ! $r->company_id) {
            return;
        }
        $this->events->record((int) $r->company_id, (int) $r->warehouse_id, 'warehouse.reservation.changed', 'RESERVATION', (string) $r->uuid,
            ['reservation' => $r->uuid, 'item' => DB::table('fin_stock_items')->where('id', $r->item_id)->value('code'), 'warehouse' => $this->code((int) $r->warehouse_id),
                'qty' => (float) $r->qty, 'qty_consumed' => (float) $r->qty_consumed, 'status' => $r->status, 'source_type' => $r->source_type]);
    }

    /** Called by Dispatching once the packages are on the shipment, inside its transaction. */
    public function dispatched(Shipment $s): void
    {
        $order = DB::table('wms_orders')->where('id', $s->order_id)->first(['number', 'external_ref']);
        $this->events->record((int) $s->company_id, (int) $s->warehouse_id, 'warehouse.shipment.dispatched', 'SHIPMENT', (string) $s->number,
            ['shipment' => $s->number, 'order' => $order?->number, 'external_ref' => $order?->external_ref, 'carrier' => $s->carrier, 'service' => $s->service,
                'tracking_reference' => $s->tracking_reference, 'packages' => $s->packages()->pluck('sscc')->all(), 'dispatched_at' => $s->dispatched_at?->toIso8601String()]);
    }

    private function delivered(Shipment $s): void
    {
        $order = DB::table('wms_orders')->where('id', $s->order_id)->first(['number', 'external_ref']);
        $this->events->record((int) $s->company_id, (int) $s->warehouse_id, 'warehouse.shipment.delivered', 'SHIPMENT', (string) $s->number,
            ['shipment' => $s->number, 'order' => $order?->number, 'external_ref' => $order?->external_ref, 'received_by' => $s->pod_received_by,
                'delivered_at' => $s->delivered_at?->toIso8601String()]);
    }

    private function code(int $warehouseId): ?string
    {
        return DB::table('fin_warehouses')->where('id', $warehouseId)->value('code');
    }

    private function warehouseOf(int $shipmentId): ?int
    {
        return ($id = DB::table('wms_shipments')->where('id', $shipmentId)->value('warehouse_id')) ? (int) $id : null;
    }

    private function shipmentNumber(int $shipmentId): string
    {
        return (string) DB::table('wms_shipments')->where('id', $shipmentId)->value('number');
    }
}
