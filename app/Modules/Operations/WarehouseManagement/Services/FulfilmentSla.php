<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use App\Modules\Operations\WarehouseManagement\Models\WarehouseStaff;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fulfilment service levels (WMS-066): how long each stage takes, how often the deadline is met,
 * how accurate the picking is -- and a warning, once per order, to the people who can still make
 * the deadline when an order is about to miss it.
 */
class FulfilmentSla
{
    /** @return array<string,mixed> */
    public function metrics(Carbon $from, Carbon $to, ?int $warehouseId = null): array
    {
        $orders = DB::table('wms_orders')->whereBetween('released_at', [$from, $to])->where('status', '!=', 'CANCELLED')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId));
        $minutes = fn (string $a, string $b) => (clone $orders)->whereNotNull($a)->whereNotNull($b)
            ->selectRaw("AVG(EXTRACT(EPOCH FROM ({$b} - {$a})) / 60.0) AS m")->value('m');

        $dispatched = (clone $orders)->whereNotNull('dispatched_at');
        $tasks = DB::table('wms_pick_tasks as t')->join('wms_orders as o', 'o.id', '=', 't.order_id')
            ->whereBetween('o.released_at', [$from, $to])->whereIn('t.status', ['PICKED', 'SHORT'])
            ->when($warehouseId, fn ($q) => $q->where('o.warehouse_id', $warehouseId));

        $done = (clone $tasks)->count();
        $clean = (clone $tasks)->where('t.status', 'PICKED')->where('t.scan_errors', 0)->count();

        return [
            'orders' => (clone $orders)->count(),
            'dispatched' => (clone $dispatched)->count(),
            'release_to_pick_minutes' => $this->round($minutes('released_at', 'pick_started_at')),
            'pick_minutes' => $this->round($minutes('pick_started_at', 'picked_at')),
            'pack_minutes' => $this->round($minutes('picked_at', 'packed_at')),
            'release_to_dispatch_minutes' => $this->round($minutes('released_at', 'dispatched_at')),
            'on_time_rate' => ($n = (clone $dispatched)->whereNotNull('sla_due_at')->count()) > 0
                ? round((clone $dispatched)->whereNotNull('sla_due_at')->whereColumn('dispatched_at', '<=', 'sla_due_at')->count() / $n, 4) : null,
            'short_lines' => DB::table('wms_order_lines as l')->join('wms_orders as o', 'o.id', '=', 'l.order_id')
                ->whereBetween('o.released_at', [$from, $to])->where('l.qty_short', '>', 0)
                ->when($warehouseId, fn ($q) => $q->where('o.warehouse_id', $warehouseId))->count(),
            'picking_accuracy' => $done > 0 ? round($clean / $done, 4) : null,
            'at_risk_now' => FulfilmentOrder::query()->whereIn('status', FulfilmentOrder::LIVE)->whereNotNull('sla_at_risk_at')
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))->count(),
        ];
    }

    /** Flag and tell, once, about live orders inside their warning window. @return int newly flagged */
    public function checkAtRisk(): int
    {
        $flagged = 0;
        $orders = FulfilmentOrder::query()->whereIn('status', FulfilmentOrder::LIVE)->whereNull('sla_at_risk_at')
            ->whereNotNull('sla_due_at')->with('warehouse')->get();

        foreach ($orders as $order) {
            $window = (int) ($order->warehouse->policies['sla_risk_minutes'] ?? 60);
            if (now()->lt($order->sla_due_at->copy()->subMinutes($window))) {
                continue;
            }
            $order->update(['sla_at_risk_at' => now()]);
            $flagged++;

            $staff = WarehouseStaff::query()->where('warehouse_id', $order->warehouse_id)->where('is_active', true)->get()
                ->filter(fn ($s) => array_intersect($s->activities ?? [], ['PICK', 'PACK', 'DISPATCH', 'SUPERVISE']) !== [])->pluck('user_id');
            // Through the notifier: its rules add recipients, and the same order is not announced twice (WMS-192).
            $notifier = app(WarehouseNotifier::class);
            $notifier->raise((int) $order->company_id, 'ORDER_AT_RISK', [$notifier->orderOccurrence($order->id, $order->uuid, $order->number, (int) $order->warehouse_id,
                $order->sla_due_at, $order->status, $staff->push($order->warehouse->manager_user_id)->filter()->unique()->values()->all())]);
        }

        return $flagged;
    }

    private function round($v): ?float
    {
        return $v === null ? null : round((float) $v, 1);
    }
}
