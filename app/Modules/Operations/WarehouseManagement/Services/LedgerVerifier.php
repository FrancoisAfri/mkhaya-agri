<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use Illuminate\Support\Facades\DB;

/**
 * The ledger is the truth; everything else is a projection that must agree with it (§25.4, WMS-212,
 * QA-027). For one company this rebuilds what the movements say and compares:
 *
 *  - each item's balance per warehouse  = the sum of its movements there (status changes and re-lots
 *    move stock between positions inside a warehouse and change no balance, so they are left out);
 *  - each item's positions per warehouse = its balance there;
 *  - each item's quantity on hand        = the sum of its balances;
 *  - no position is negative, and no warehouse has more reserved than it holds available.
 *
 * Nothing is corrected here: a difference is reported, because which side is wrong is a question
 * for a person.
 */
class LedgerVerifier
{
    /** Movement types that move stock between positions inside one warehouse. */
    public const POSITION_ONLY = ['status', 'relot'];

    private const TOLERANCE = 0.0001;

    /** @return array{ok: bool, checked: array<string,int>, problems: list<array>} */
    public function verify(int $companyId): array
    {
        $problems = [];
        $moves = DB::table('fin_stock_movements')->where('company_id', $companyId)->whereNotNull('warehouse_id')->whereNotIn('type', self::POSITION_ONLY)
            ->groupBy('item_id', 'warehouse_id')->selectRaw('item_id, warehouse_id, SUM(qty) AS q')->get()->keyBy(fn ($r) => $r->item_id.':'.$r->warehouse_id);
        $balances = DB::table('fin_stock_balances as b')->join('fin_stock_items as i', 'i.id', '=', 'b.item_id')->where('i.company_id', $companyId)
            ->get(['b.item_id', 'b.warehouse_id', 'b.qty'])->keyBy(fn ($r) => $r->item_id.':'.$r->warehouse_id);
        $positions = DB::table('fin_stock_positions')->where('company_id', $companyId)->groupBy('item_id', 'warehouse_id')
            ->selectRaw('item_id, warehouse_id, SUM(qty) AS q, MIN(qty) AS lowest')->get()->keyBy(fn ($r) => $r->item_id.':'.$r->warehouse_id);

        foreach ($balances->keys()->merge($moves->keys())->merge($positions->keys())->unique() as $key) {
            [$item, $wh] = array_map('intval', explode(':', $key));
            $b = (float) ($balances[$key]->qty ?? 0);
            $m = (float) ($moves[$key]->q ?? 0);
            $p = (float) ($positions[$key]->q ?? 0);
            if (abs($b - $m) > self::TOLERANCE) {
                $problems[] = $this->problem('BALANCE_VS_LEDGER', $item, $wh, ['balance' => $b, 'ledger' => round($m, 4)]);
            }
            if (abs($p - $b) > self::TOLERANCE) {
                $problems[] = $this->problem('POSITIONS_VS_BALANCE', $item, $wh, ['positions' => round($p, 4), 'balance' => $b]);
            }
            if (isset($positions[$key]) && (float) $positions[$key]->lowest < -self::TOLERANCE) {
                $problems[] = $this->problem('NEGATIVE_POSITION', $item, $wh, ['lowest' => (float) $positions[$key]->lowest]);
            }
        }

        $onHand = DB::table('fin_stock_items as i')->leftJoin('fin_stock_balances as b', 'b.item_id', '=', 'i.id')->where('i.company_id', $companyId)
            ->groupBy('i.id', 'i.qty_on_hand')->havingRaw('ABS(COALESCE(SUM(b.qty), 0) - i.qty_on_hand) > ?', [self::TOLERANCE])
            ->get(['i.id', 'i.qty_on_hand', DB::raw('COALESCE(SUM(b.qty), 0) AS balances')]);
        foreach ($onHand as $r) {
            $problems[] = $this->problem('ON_HAND_VS_BALANCES', (int) $r->id, null, ['on_hand' => (float) $r->qty_on_hand, 'balances' => round((float) $r->balances, 4)]);
        }

        $over = DB::table('fin_stock_reservations as r')->where('r.company_id', $companyId)->where('r.status', 'OPEN')->groupBy('r.item_id', 'r.warehouse_id')
            ->selectRaw('r.item_id, r.warehouse_id, SUM(r.qty - r.qty_consumed) AS reserved')
            ->selectSub(fn ($q) => $q->from('fin_stock_positions as p')->whereColumn('p.item_id', 'r.item_id')->whereColumn('p.warehouse_id', 'r.warehouse_id')
                ->where('p.status', 'AVAILABLE')->selectRaw('COALESCE(SUM(p.qty), 0)'), 'available')->get()
            ->filter(fn ($r) => (float) $r->reserved - (float) $r->available > self::TOLERANCE);
        foreach ($over as $r) {
            $problems[] = $this->problem('OVER_RESERVED', (int) $r->item_id, (int) $r->warehouse_id, ['reserved' => round((float) $r->reserved, 4), 'available' => round((float) $r->available, 4)]);
        }

        return ['ok' => $problems === [], 'checked' => ['item_warehouses' => $balances->count(), 'items' => (int) DB::table('fin_stock_items')->where('company_id', $companyId)->count()],
            'problems' => $problems];
    }

    private function problem(string $kind, int $itemId, ?int $warehouseId, array $figures): array
    {
        return ['kind' => $kind, 'item' => DB::table('fin_stock_items')->where('id', $itemId)->value('code'), 'item_id' => $itemId,
            'warehouse' => $warehouseId ? DB::table('fin_warehouses')->where('id', $warehouseId)->value('code') : null, 'warehouse_id' => $warehouseId] + $figures;
    }
}
