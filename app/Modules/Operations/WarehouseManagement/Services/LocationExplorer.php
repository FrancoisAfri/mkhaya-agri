<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use Illuminate\Support\Facades\DB;

/**
 * The location explorer (WMS-005) and capacity picture (WMS-042): every location in a warehouse
 * with what it holds, rolled up the hierarchy so an aisle shows the stock in all of its bins.
 *
 * Quantity comes from the ledger's positions -- the same rows the balance is made of -- so the
 * explorer cannot disagree with the stock report.
 */
class LocationExplorer
{
    /** Occupancy at or above this share of capacity is a warning (WMS-042). */
    public const NEAR_FULL = 0.9;

    /**
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function tree(FinWarehouse $warehouse): array
    {
        $bins = FinStockBin::query()->where('warehouse_id', $warehouse->id)
            ->orderBy('code')->get();

        // What each bin holds, with weight where the item master knows it.
        $held = DB::table('fin_stock_positions as p')
            ->leftJoin('mfg_items as m', 'm.stock_item_id', '=', 'p.item_id')
            ->where('p.warehouse_id', $warehouse->id)->whereNotNull('p.bin_id')->where('p.qty', '!=', 0)
            ->groupBy('p.bin_id')
            ->selectRaw('p.bin_id, SUM(p.qty) AS qty, COUNT(DISTINCT p.item_id) AS items, '
                .'SUM(p.qty * COALESCE(m.weight_kg, 0)) AS weight_kg, '
                ."SUM(CASE WHEN p.status <> 'AVAILABLE' THEN p.qty ELSE 0 END) AS held_qty")
            ->get()->keyBy('bin_id');

        $byId = [];
        foreach ($bins as $bin) {
            $h = $held[$bin->id] ?? null;
            $byId[$bin->id] = [
                'id' => $bin->uuid,
                'db_id' => $bin->id,
                'parent_db_id' => $bin->parent_id,
                'code' => $bin->code,
                'name' => $bin->name,
                'level' => $bin->level,
                'kind' => $bin->kind,
                'status' => $bin->status,
                'effective_refusal' => $bin->level === 'BIN' ? $bin->refusalForIncoming() : null,
                'status_reason' => $bin->status_reason,
                'restricted_until' => $bin->restricted_until?->toIso8601String(),
                'is_active' => $bin->is_active,
                'barcode' => $bin->barcode,
                'max_units' => $bin->max_units !== null ? (float) $bin->max_units : null,
                'max_weight_kg' => $bin->max_weight_kg !== null ? (float) $bin->max_weight_kg : null,
                'max_volume_m3' => $bin->max_volume_m3 !== null ? (float) $bin->max_volume_m3 : null,
                'max_pallets' => $bin->max_pallets,
                'length_cm' => $bin->length_cm !== null ? (float) $bin->length_cm : null,
                'width_cm' => $bin->width_cm !== null ? (float) $bin->width_cm : null,
                'height_cm' => $bin->height_cm !== null ? (float) $bin->height_cm : null,
                'requires_temperature_monitoring' => $bin->requires_temperature_monitoring,
                'temperature_min_c' => $bin->temperature_min_c !== null ? (float) $bin->temperature_min_c : null,
                'temperature_max_c' => $bin->temperature_max_c !== null ? (float) $bin->temperature_max_c : null,
                'hazmat_allowed' => $bin->hazmat_allowed,
                'handling_notes' => $bin->handling_notes,
                'pick_sequence' => $bin->pick_sequence,
                'qty' => (float) ($h->qty ?? 0),
                'held_qty' => (float) ($h->held_qty ?? 0),
                'items' => (int) ($h->items ?? 0),
                'weight_kg' => (float) ($h->weight_kg ?? 0),
                'bins' => $bin->level === 'BIN' ? 1 : 0,
                'bins_with_stock' => ($h->qty ?? 0) != 0 ? 1 : 0,
                'bins_blocked' => $bin->level === 'BIN' && $bin->status === FinStockBin::BLOCKED ? 1 : 0,
                'depth' => 0,
            ];
        }

        // Roll each bin's figures up into every ancestor. Five levels at most.
        foreach ($byId as $id => $row) {
            $parent = $row['parent_db_id'];
            for ($d = 0; $parent !== null && isset($byId[$parent]) && $d < 5; $d++) {
                foreach (['qty', 'held_qty', 'weight_kg', 'bins', 'bins_with_stock', 'bins_blocked'] as $k) {
                    $byId[$parent][$k] += $row[$k];
                }
                $byId[$id]['depth']++;
                $parent = $byId[$parent]['parent_db_id'];
            }
        }

        $rows = [];
        foreach ($byId as $row) {
            $row['occupancy'] = $this->occupancy($row);
            $row['warning'] = $row['occupancy'] !== null && $row['occupancy'] >= self::NEAR_FULL
                ? ($row['occupancy'] > 1 ? 'OVER' : 'NEAR_FULL') : null;
            $rows[] = $row;
        }

        // Depth-first order so the list reads as a tree.
        $ordered = [];
        $children = [];
        foreach ($rows as $r) {
            $children[$r['parent_db_id'] ?? 0][] = $r;
        }
        $walk = function ($pid) use (&$walk, &$ordered, $children) {
            foreach ($children[$pid] ?? [] as $r) {
                $ordered[] = $r;
                $walk($r['db_id']);
            }
        };
        $walk(0);
        // Rows whose parent is in another warehouse or missing still appear.
        $seen = array_column($ordered, 'db_id');
        foreach ($rows as $r) {
            if (! in_array($r['db_id'], $seen, true)) {
                $ordered[] = $r;
            }
        }

        $leaves = array_filter($rows, fn ($r) => $r['level'] === 'BIN');

        return [
            'rows' => array_map(fn ($r) => array_diff_key($r, ['db_id' => 1, 'parent_db_id' => 1]) + [
                'parent_id' => $r['parent_db_id'] ? ($byId[$r['parent_db_id']]['id'] ?? null) : null,
            ], $ordered),
            'totals' => [
                'locations' => count($rows),
                'bins' => count($leaves),
                'bins_with_stock' => count(array_filter($leaves, fn ($r) => $r['qty'] != 0)),
                'bins_empty' => count(array_filter($leaves, fn ($r) => $r['qty'] == 0 && $r['is_active'])),
                'bins_blocked' => count(array_filter($leaves, fn ($r) => $r['status'] === FinStockBin::BLOCKED)),
                'bins_restricted' => count(array_filter($leaves, fn ($r) => $r['status'] === FinStockBin::RESTRICTED)),
                'bins_near_full' => count(array_filter($leaves, fn ($r) => $r['warning'] !== null)),
                'unbinned_qty' => (float) DB::table('fin_stock_positions')->where('warehouse_id', $warehouse->id)
                    ->whereNull('bin_id')->sum('qty'),
            ],
        ];
    }

    /** The fullest of the limits that are set, as a share; null when no limit is configured. */
    private function occupancy(array $row): ?float
    {
        $shares = [];
        if ($row['max_units']) {
            $shares[] = $row['qty'] / $row['max_units'];
        }
        if ($row['max_weight_kg'] && $row['weight_kg'] > 0) {
            $shares[] = $row['weight_kg'] / $row['max_weight_kg'];
        }

        return $shares === [] ? null : round(max($shares), 4);
    }
}
