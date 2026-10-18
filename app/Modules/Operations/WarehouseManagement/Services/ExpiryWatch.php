<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use App\Modules\Operations\WarehouseManagement\Models\WarehouseStaff;
use Illuminate\Support\Facades\DB;

/**
 * Stock about to expire (WMS-093): how much, how soon, how much of it the expected demand will use
 * before then, and what the rest is worth -- the money at risk, not merely the quantity.
 *
 * Warning points are the product's own (e.g. 90, 60, 30 and 7 days), else the warehouse's, else
 * that default; none of them is fixed in code. Each lot is warned about once per point it crosses.
 */
class ExpiryWatch
{
    use ScopesRawQueries;

    public const DEFAULT_ALERT_DAYS = [90, 60, 30, 7];

    /** Days of history the demand rate is taken from. */
    public const DEMAND_WINDOW_DAYS = 90;

    /**
     * @return list<array{lot_id:int, lot:string, item_id:int, item:string, name:string, uom:string, warehouse_id:int, warehouse:string,
     *                    expires_on:string, days_left:int, qty:float, daily_demand:float, demand_before_expiry:float, at_risk_qty:float,
     *                    unit_cost:float, exposure:float, thresholds:list<int>, next_threshold:?int}>
     */
    public function atRisk(?int $warehouseId = null, ?int $withinDays = null): array
    {
        $today = now()->startOfDay();
        $horizon = $withinDays ?? 365;
        $rows = DB::table('fin_stock_positions as p')->join('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')
            ->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')->join('fin_warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->leftJoin('wms_item_profiles as ip', 'ip.stock_item_id', '=', 'p.item_id')
            ->whereNotNull('l.expires_on')->where('l.expires_on', '<=', $today->copy()->addDays($horizon)->toDateString())
            ->where('p.qty', '>', 0)
            ->when($warehouseId, fn ($q, $v) => $q->where('p.warehouse_id', $v))
            ->when($this->tenantId(), fn ($q, $c) => $q->where('p.company_id', $c))
            ->groupBy('l.id', 'l.lot_number', 'l.expires_on', 'i.id', 'i.code', 'i.name', 'i.uom', 'i.is_valued', 'i.avg_cost', 'i.standard_cost', 'w.id', 'w.code', 'w.policies', 'ip.expiry_alert_days')
            ->selectRaw('l.id AS lot_id, l.lot_number, l.expires_on, i.id AS item_id, i.code, i.name, i.uom, w.id AS warehouse_id, w.code AS warehouse, w.policies, ip.expiry_alert_days, SUM(p.qty) AS qty')
            ->selectRaw('CASE WHEN COALESCE(i.is_valued, true) THEN COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost) ELSE 0 END AS unit_cost')
            ->orderBy('l.expires_on')->get();

        $demand = $this->dailyDemand($rows->pluck('item_id')->unique()->all());
        $out = [];
        // Demand is consumed by the earliest-expiring lots first, as FEFO issues them.
        $consumed = [];
        foreach ($rows as $r) {
            $daysLeft = (int) $today->diffInDays($r->expires_on, false);
            $key = $r->item_id.':'.$r->warehouse_id;
            $daily = (float) ($demand[$key] ?? 0);
            $before = max(0.0, $daily * max(0, $daysLeft) - ($consumed[$key] ?? 0));
            $usedHere = min((float) $r->qty, $before);
            $consumed[$key] = ($consumed[$key] ?? 0) + $usedHere;
            $atRisk = round(max(0.0, (float) $r->qty - $usedHere), 4);
            $thresholds = $this->thresholds($r->expiry_alert_days, $r->policies);
            $out[] = [
                'lot_id' => (int) $r->lot_id, 'lot' => $r->lot_number, 'item_id' => (int) $r->item_id, 'item' => $r->code, 'name' => $r->name, 'uom' => $r->uom,
                'warehouse_id' => (int) $r->warehouse_id, 'warehouse' => $r->warehouse, 'expires_on' => $r->expires_on, 'days_left' => $daysLeft,
                'qty' => round((float) $r->qty, 4), 'daily_demand' => round($daily, 4), 'demand_before_expiry' => round($usedHere, 4),
                'at_risk_qty' => $atRisk, 'unit_cost' => round((float) $r->unit_cost, 4), 'exposure' => round($atRisk * (float) $r->unit_cost, 2),
                'thresholds' => $thresholds,
                'next_threshold' => collect($thresholds)->sort()->first(fn ($t) => $daysLeft <= $t),
                // With no usage history the demand estimate says nothing; the screen says so rather than promising sales.
                'demand_known' => array_key_exists($key, $demand),
            ];
        }

        return $out;
    }

    /** Warn once per lot and threshold crossed; returns how many warnings were given. */
    public function alert(): int
    {
        $sent = 0;
        foreach ($this->atRisk() as $r) {
            $crossed = collect($r['thresholds'])->filter(fn ($t) => $r['days_left'] <= $t)->sort()->first();
            if ($crossed === null || $r['qty'] <= 0) {
                continue;
            }
            $inserted = DB::table('wms_expiry_alerts')->insertOrIgnore([
                'company_id' => DB::table('fin_stock_lots')->where('id', $r['lot_id'])->value('company_id'), 'lot_id' => $r['lot_id'],
                'warehouse_id' => $r['warehouse_id'], 'threshold_days' => $crossed, 'days_left' => $r['days_left'], 'qty' => $r['qty'],
                'at_risk_qty' => $r['at_risk_qty'], 'exposure' => $r['exposure'], 'alerted_at' => now(),
            ]);
            if (! $inserted) {
                continue;
            }
            $sent++;
            $warehouse = FinWarehouse::query()->find($r['warehouse_id']);
            $staff = WarehouseStaff::query()->where('warehouse_id', $r['warehouse_id'])->where('is_active', true)->get()
                ->filter(fn ($s) => array_intersect($s->activities ?? [], ['SUPERVISE', 'COUNT']) !== [])->pluck('user_id');
            $notifier = app(WarehouseNotifier::class);
            $notifier->raise((int) DB::table('fin_stock_lots')->where('id', $r['lot_id'])->value('company_id'), 'EXPIRY', [$notifier->expiryOccurrence($r['lot_id'], $r['warehouse_id'], $r['item'], $r['lot'], $r['warehouse'],
                $r['days_left'], $r['qty'], $r['at_risk_qty'], $r['exposure'], $crossed, $staff->push($warehouse?->manager_user_id)->filter()->unique()->values()->all())]);
        }

        return $sent;
    }

    /** @return list<int> */
    private function thresholds($profile, $policies): array
    {
        $own = is_string($profile) ? json_decode($profile, true) : $profile;
        if (is_array($own) && $own !== []) {
            return array_values(array_map('intval', $own));
        }
        $policies = is_string($policies) ? json_decode($policies, true) : (array) $policies;
        $wh = $policies['expiry_alert_days'] ?? null;

        return is_array($wh) && $wh !== [] ? array_values(array_map('intval', $wh)) : self::DEFAULT_ALERT_DAYS;
    }

    /** Average daily outbound use per item and warehouse over the window. @return array<string, float> */
    private function dailyDemand(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('fin_stock_movements')->whereIn('item_id', $itemIds)->where('qty', '<', 0)
            ->whereIn('type', ['dispatch', 'sale', 'issue', 'mfg_issue'])->whereNull('reversal_of_id')
            ->where('movement_date', '>=', now()->subDays(self::DEMAND_WINDOW_DAYS)->toDateString())
            ->groupBy('item_id', 'warehouse_id')->selectRaw('item_id, warehouse_id, SUM(ABS(qty)) AS used')->get()
            ->mapWithKeys(fn ($r) => [$r->item_id.':'.$r->warehouse_id => (float) $r->used / self::DEMAND_WINDOW_DAYS])->all();
    }
}
