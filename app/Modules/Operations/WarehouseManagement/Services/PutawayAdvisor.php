<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Item as MfgItem;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where should this stock go (WMS-040)? Every rule is a reason, and the suggestion carries its
 * reasons so a person overriding it can see what they are overriding.
 *
 * Hard rules exclude a bin: it is closed, the wrong kind for the stock's state, too warm or cold,
 * not cleared for hazardous goods, already holding a different lot of the same item (when the
 * warehouse rotates by lot), or without the room. Soft rules rank what is left: the same item and
 * lot already there (consolidation), then -- for an item that moves a lot -- the bins nearest the
 * front of the pick path, otherwise the back, so fast movers are not buried behind slow ones.
 */
class PutawayAdvisor
{
    /** Picks in the last 30 days above which an item is treated as a fast mover. */
    public const FAST_MOVER_PICKS = 20;

    /**
     * @return array{bin: ?FinStockBin, reasons: list<string>, rejected: array<string,string>}
     */
    public function suggest(FinWarehouse $warehouse, FinStockItem $item, float $qty, ?int $lotId = null, string $status = FinStockPosition::AVAILABLE, ?int $excludeBinId = null): array
    {
        $wantedKind = $status === FinStockPosition::AVAILABLE ? 'STORAGE' : 'QUARANTINE';
        $profile = ItemProfile::query()->where('stock_item_id', $item->id)->first();
        $mfg = MfgItem::withoutGlobalScopes()->where('stock_item_id', $item->id)->first();
        $unitWeight = (float) ($mfg->weight_kg ?? 0);
        $lot = $lotId ? FinStockLot::query()->find($lotId) : null;
        $rotatesByLot = in_array($warehouse->policies['rotation'] ?? 'FEFO', ['FEFO', 'FIFO'], true);

        $bins = FinStockBin::query()->where('warehouse_id', $warehouse->id)->where('level', 'BIN')->where('is_active', true)
            ->where('kind', $wantedKind)->when($excludeBinId, fn ($q) => $q->whereKeyNot($excludeBinId))->get();

        $held = DB::table('fin_stock_positions as p')->leftJoin('mfg_items as m', 'm.stock_item_id', '=', 'p.item_id')
            ->whereIn('p.bin_id', $bins->pluck('id'))->where('p.qty', '!=', 0)
            ->groupBy('p.bin_id')
            ->selectRaw('p.bin_id, SUM(p.qty) AS qty, SUM(p.qty * COALESCE(m.weight_kg, 0)) AS weight')
            ->selectRaw('BOOL_OR(p.item_id = ?) AS has_item', [$item->id])
            ->selectRaw('BOOL_OR(p.item_id = ? AND p.lot_id IS NOT DISTINCT FROM ?) AS has_same_lot', [$item->id, $lotId])
            ->selectRaw('BOOL_OR(p.item_id = ? AND p.lot_id IS DISTINCT FROM ?) AS has_other_lot', [$item->id, $lotId])
            ->get()->keyBy('bin_id');

        $fast = $this->picksLast30Days($item, $warehouse) >= self::FAST_MOVER_PICKS;
        $rejected = [];
        $candidates = new Collection;

        foreach ($bins as $bin) {
            $why = $this->exclusion($bin, $profile, $held[$bin->id] ?? null, $qty, $unitWeight, $lot, $rotatesByLot);
            if ($why !== null) {
                $rejected[$bin->code] = $why;

                continue;
            }
            $h = $held[$bin->id] ?? null;
            $score = 0;
            $reasons = [];
            if ($h?->has_same_lot) {
                $score += 100;
                $reasons[] = 'consolidates with the same item'.($lot ? ' and lot' : '');
            } elseif ($h?->has_item) {
                $score += 60;
                $reasons[] = 'already holds this item';
            } elseif (! $h || (float) $h->qty == 0.0) {
                $score += 20;
                $reasons[] = 'empty bin';
            }
            $seq = $bin->pick_sequence ?? 5000;
            $score += $fast ? max(0, 50 - $seq / 100) : min(50, $seq / 100);
            $reasons[] = $fast ? 'fast mover: near the front of the pick path' : 'slow mover: away from the pick face';
            if ($profile?->temperature_min_c !== null || $profile?->temperature_max_c !== null) {
                $reasons[] = 'temperature range fits';
            }
            $candidates->push(['bin' => $bin, 'score' => $score, 'reasons' => $reasons]);
        }

        $best = $candidates->sortByDesc('score')->first();

        return ['bin' => $best['bin'] ?? null, 'reasons' => $best['reasons'] ?? ['no bin satisfies every rule'], 'rejected' => $rejected];
    }

    /** Why a person's chosen bin breaks a hard rule, or null when it may be used (WMS-041). */
    public function refusalFor(FinStockBin $bin, FinStockItem $item, float $qty, ?int $lotId, string $status, bool $keepLotsApart = true): ?string
    {
        $closed = $bin->refusalForIncoming();
        if ($closed !== null) {
            return $closed;
        }
        $warehouse = $bin->warehouse;
        $profile = ItemProfile::query()->where('stock_item_id', $item->id)->first();
        $mfg = MfgItem::withoutGlobalScopes()->where('stock_item_id', $item->id)->first();
        $held = DB::table('fin_stock_positions as p')->leftJoin('mfg_items as m', 'm.stock_item_id', '=', 'p.item_id')
            ->where('p.bin_id', $bin->id)->where('p.qty', '!=', 0)
            ->selectRaw('SUM(p.qty) AS qty, SUM(p.qty * COALESCE(m.weight_kg, 0)) AS weight')
            ->selectRaw('BOOL_OR(p.item_id = ? AND p.lot_id IS DISTINCT FROM ?) AS has_other_lot', [$item->id, $lotId])
            ->first();
        if ($status === FinStockPosition::AVAILABLE && in_array($bin->kind, ['QUARANTINE', 'RETURNS'], true)) {
            return "{$bin->code} is a {$bin->kind} location; usable stock is not stored there.";
        }

        return $this->exclusion($bin, $profile, $held, $qty, (float) ($mfg->weight_kg ?? 0),
            $lotId ? FinStockLot::query()->find($lotId) : null,
            $keepLotsApart && in_array($warehouse?->policies['rotation'] ?? 'FEFO', ['FEFO', 'FIFO'], true));
    }

    private function exclusion(FinStockBin $bin, ?ItemProfile $profile, $held, float $qty, float $unitWeight, ?FinStockLot $lot, bool $rotatesByLot): ?string
    {
        if (($closed = $bin->refusalForIncoming()) !== null) {
            return $closed;
        }
        $min = $profile?->temperature_min_c;
        $max = $profile?->temperature_max_c;
        if ($min !== null || $max !== null) {
            if (! $bin->requires_temperature_monitoring) {
                return 'not temperature controlled';
            }
            if (($min !== null && $bin->temperature_min_c !== null && (float) $bin->temperature_min_c < (float) $min)
                || ($max !== null && $bin->temperature_max_c !== null && (float) $bin->temperature_max_c > (float) $max)) {
                return "kept at {$bin->temperature_min_c}–{$bin->temperature_max_c} °C; the item needs {$min}–{$max} °C";
            }
        }
        if ($profile?->hazard_class && ! $bin->hazmat_allowed) {
            return 'not cleared for hazardous goods';
        }
        if ($rotatesByLot && $lot && ($held->has_other_lot ?? false)) {
            return 'holds another lot of this item, which would break rotation';
        }
        $inBin = (float) ($held->qty ?? 0);
        if ($bin->max_units !== null && $inBin + $qty > (float) $bin->max_units + 1e-6) {
            return 'not enough room: '.max(0, (float) $bin->max_units - $inBin).' units free';
        }
        if ($bin->max_weight_kg !== null && $unitWeight > 0 && (float) ($held->weight ?? 0) + $qty * $unitWeight > (float) $bin->max_weight_kg + 1e-6) {
            return 'over its weight limit';
        }

        return null;
    }

    private function picksLast30Days(FinStockItem $item, FinWarehouse $warehouse): int
    {
        return (int) DB::table('fin_stock_movements')->where('item_id', $item->id)->where('warehouse_id', $warehouse->id)
            ->where('qty', '<', 0)->whereIn('type', ['issue', 'sale', 'mfg_issue', 'dispatch'])
            ->where('created_at', '>=', now()->subDays(30))->count();
    }
}
