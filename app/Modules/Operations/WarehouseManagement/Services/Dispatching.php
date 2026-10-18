<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Services\SalesOrderService;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Operations\Manufacturing\Services\ProductionExecution;
use App\Modules\Operations\WarehouseManagement\Models\Allocation;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dispatch (WMS-065): the packages that leave, verified, with the carrier, the vehicle and who took
 * them -- and the moment stock leaves the books.
 *
 * Each source leaves through its owner's door. A sales order's goods are issued at cost against
 * the order's reservation and Finance records the delivery against the sales order (its delivery
 * note is the dispatch note). A production order's material is issued through Manufacturing's own
 * issue, so the order's components and material cost stay right. Everything else is issued
 * against its reservation. A dispatch can be partial; what is left waits for the next one.
 */
class Dispatching
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly FulfilmentOrders $orders,
    ) {}

    /** @param Collection<int,Package> $packages */
    public function dispatch(FulfilmentOrder $order, Collection $packages, array $attrs, User $user, ?UploadedFile $signature = null): Shipment
    {
        if (! in_array($order->status, ['PICKING', 'PICKED', 'PACKED', 'PARTIALLY_DISPATCHED'], true)) {
            throw new RuntimeException("{$order->number} is ".strtolower(str_replace('_', ' ', $order->status)).' and has nothing ready to dispatch.');
        }
        if ($packages->isEmpty()) {
            throw new RuntimeException('Choose the packages that are leaving.');
        }
        foreach ($packages as $p) {
            if ($p->order_id !== $order->id) {
                throw new RuntimeException("Package {$p->sscc} belongs to another order.");
            }
            if ($p->status !== 'CLOSED') {
                throw new RuntimeException("Package {$p->sscc} is ".strtolower($p->status).'; only closed packages ship.');
            }
        }
        if (trim((string) ($attrs['handover_name'] ?? '')) === '') {
            throw new RuntimeException('Record who the goods were handed to.');
        }
        if (($why = app(WarehouseQuality::class)->dispatchRefusal($order)) !== null) {
            throw new RuntimeException($why);
        }
        if (($order->warehouse->policies['dispatch_requires_pack_check'] ?? true) && $order->packages()->where('status', 'OPEN')->exists()) {
            throw new RuntimeException('A package for this order is still open. Close or empty it before dispatching -- this warehouse checks the pack first.');
        }

        return DB::transaction(function () use ($order, $packages, $attrs, $user, $signature) {
            $shipment = Shipment::create([
                'company_id' => $order->company_id, 'number' => $this->nextNumber((int) $order->company_id), 'order_id' => $order->id,
                'warehouse_id' => $order->warehouse_id, 'status' => 'DISPATCHED', 'dispatched_at' => now(), 'dispatched_by' => $user->id,
            ] + array_intersect_key($attrs, array_flip(['carrier_id', 'carrier', 'service', 'vehicle_reg', 'driver_name', 'tracking_reference', 'collection_at', 'handover_name'])));
            if ($signature) {
                $shipment->update(['handover_signature_path' => $this->store($signature, $shipment, 'signature')]);
            }

            $packing = $this->orders->packingBin($order->warehouse);
            $byLine = [];
            foreach ($packages as $package) {
                foreach ($package->contents as $c) {
                    $this->issue($order, $c->order_line_id, (int) $c->item_id, $c->lot_id, (float) $c->qty, $packing->id, $shipment, $c->id, $user);
                    $byLine[$c->order_line_id] = ($byLine[$c->order_line_id] ?? 0) + (float) $c->qty;
                }
                $package->update(['status' => 'DISPATCHED']);
                $shipment->packages()->attach($package->id, ['company_id' => $order->company_id]);
            }

            foreach ($byLine as $lineId => $qty) {
                $line = $order->lines()->whereKey($lineId)->firstOrFail();
                $line->update(['qty_dispatched' => round((float) $line->qty_dispatched + $qty, 4)]);
            }

            if ($order->source === 'SALES_ORDER' && $order->source_id) {
                $this->recordSalesDelivery($order, $byLine, $shipment);
            }

            $remaining = $order->lines()->get()->sum(fn ($l) => max(0.0, (float) $l->qty - (float) $l->qty_cancelled - (float) $l->qty_dispatched));
            $order->update(['status' => $remaining <= 1e-6 ? 'DISPATCHED' : 'PARTIALLY_DISPATCHED', 'dispatched_at' => now(),
                'carrier' => $attrs['carrier'] ?? $order->carrier]);
            app(WarehouseEventSources::class)->dispatched($shipment);

            return $shipment->fresh('packages');
        });
    }

    /** Proof of delivery, from the carrier or entered by somebody authorised. */
    public function recordDelivery(Shipment $shipment, string $receivedBy, ?string $deliveredAt, ?UploadedFile $pod, ?string $note): Shipment
    {
        if ($shipment->status !== 'DISPATCHED') {
            throw new RuntimeException("{$shipment->number} is already ".strtolower($shipment->status).'.');
        }
        if (trim($receivedBy) === '') {
            throw new RuntimeException('Record who received the goods.');
        }

        return DB::transaction(function () use ($shipment, $receivedBy, $deliveredAt, $pod, $note) {
            $shipment->update(['status' => 'DELIVERED', 'pod_received_by' => $receivedBy, 'delivered_at' => $deliveredAt ?? now(),
                'pod_note' => $note, 'pod_path' => $pod ? $this->store($pod, $shipment, 'pod') : null]);
            $order = $shipment->order;
            if ($order->status === 'DISPATCHED' && ! $order->shipments()->where('status', 'DISPATCHED')->exists()) {
                $order->update(['status' => 'DELIVERED', 'delivered_at' => now()]);
            }

            return $shipment;
        });
    }

    private function issue(FulfilmentOrder $order, int $lineId, int $itemId, ?int $lotId, float $qty, int $packingBinId, Shipment $shipment, int $contentId, User $user): void
    {
        $item = FinStockItem::query()->findOrFail($itemId);
        $allocations = Allocation::query()->where('order_line_id', $lineId)->whereIn('status', ['ACTIVE', 'PICKED'])
            ->when($lotId, fn ($q) => $q->where('lot_id', $lotId))
            ->whereRaw('qty_picked > qty_dispatched')->orderBy('id')->lockForUpdate()->get();
        $left = $qty;

        foreach ($allocations as $a) {
            if ($left <= 1e-6) {
                break;
            }
            $take = min($left, round((float) $a->qty_picked - (float) $a->qty_dispatched, 4));
            $key = "dispatch:{$shipment->id}:{$contentId}:{$a->id}";

            if ($order->source === 'MANUFACTURING') {
                $po = ProductionOrder::query()->findOrFail($order->source_id);
                $component = ProductionOrderComponent::query()->where('order_id', $po->id)->where('stock_item_id', $itemId)->firstOrFail();
                try {
                    app(ProductionExecution::class)->issue($po, $component, $take, $user, ['bin_id' => $packingBinId, 'lot_id' => $a->lot_id, 'idempotency_key' => $key]);
                } catch (\DomainException $e) {
                    // Manufacturing's refusals are DomainExceptions; the warehouse reports refusals as RuntimeExceptions.
                    throw new RuntimeException($e->getMessage(), 0, $e);
                }
            } else {
                $this->ledger->dispatchToCustomer($item, $take, $order->warehouse, [
                    'source_type' => 'WMS_ALLOCATION', 'source_id' => $a->id, 'bin_id' => $packingBinId, 'lot_id' => $a->lot_id,
                    'reference' => "{$order->number} / {$shipment->number}", 'idempotency_key' => $key,
                ]);
            }
            $dispatched = round((float) $a->qty_dispatched + $take, 4);
            $a->update(['qty_dispatched' => $dispatched, 'status' => $dispatched >= (float) $a->qty - 1e-6 ? 'DISPATCHED' : $a->status]);
            $left = round($left - $take, 4);
        }

        if ($left > 1e-6) {
            throw new RuntimeException("{$item->code}: {$left} more is packed than was picked for this order.");
        }
    }

    /** Finance's delivery against the sales order; its delivery note doubles as the dispatch note. */
    private function recordSalesDelivery(FulfilmentOrder $order, array $byLine, Shipment $shipment): void
    {
        $so = FinSalesOrder::query()->find($order->source_id);
        if (! $so) {
            return;
        }
        $deliveries = [];
        foreach ($order->lines()->whereIn('id', array_keys($byLine))->get() as $line) {
            if ($line->source_line_id) {
                $deliveries[$line->source_line_id] = ($deliveries[$line->source_line_id] ?? 0) + $byLine[$line->id];
            }
        }
        if ($deliveries !== []) {
            $dn = app(SalesOrderService::class)->deliver($so, $deliveries, "Dispatched by the warehouse: {$order->number} / {$shipment->number}");
            $shipment->update(['delivery_note_id' => $dn->id]);
        }
    }

    private function store(UploadedFile $file, Shipment $shipment, string $what): string
    {
        // Every upload passes the platform's malware scanner (structural checks; ClamAV where configured) -- WMS-221.
        if (($why = app(\App\Core\Storage\MalwareScanner::class)->scan($file)) !== null) {
            throw new RuntimeException("The file was refused: {$why}");
        }
        // The extension the server detects, never the one the browser claims.
        $path = "wms/shipments/{$shipment->uuid}/{$what}-".Str::uuid().'.'.($file->guessExtension() ?: 'bin');
        Storage::disk('documents')->put($path, $file->get());

        return $path;
    }

    private function nextNumber(int $companyId): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('wms-shipment-number:'.$companyId)]);
        $prefix = 'SHP-'.now()->format('Y').'-';
        $last = Shipment::query()->where('company_id', $companyId)->where('number', 'like', $prefix.'%')->max('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 6, '0', STR_PAD_LEFT);
    }
}
