<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use App\Modules\Operations\WarehouseManagement\Models\Count;
use App\Modules\Operations\WarehouseManagement\Models\CountLine;
use App\Modules\Operations\WarehouseManagement\Models\ItemProfile;
use Illuminate\Support\Facades\DB;

/**
 * What a count tells you beyond the number (WMS-072, WMS-073).
 *
 * Cause hints are suggestions for the person investigating, never conclusions: a variance that is
 * exactly one carton is probably a unit-of-measure slip, one that matches a movement two days ago
 * is probably that movement missed or done twice, and one bin over while its neighbour is under by
 * the same amount is probably stock put in the wrong place. Each hint says what it saw.
 */
class CountInsights
{
    use ScopesRawQueries;

    /** Movement types that consume stock, for ABC usage value. Transfers, status and lot changes move it, not use it. */
    private const NON_USAGE = ['transfer', 'status', 'relot', 'adjustment'];

    /** @return list<array{code:string, text:string}> */
    public function causes(CountLine $line, Count $count): array
    {
        $variance = (float) $line->variance_qty;
        $abs = abs($variance);
        if ($abs < 0.0001) {
            return [];
        }
        $hints = [];

        if ($line->unexpected) {
            $hints[] = ['code' => 'UNEXPECTED', 'text' => 'Found where the ledger holds none of it: check put-away and moves into this bin.'];
        }

        // Unit of measure: the difference is a whole number of packs, or the counter counted packs not units.
        $packs = DB::table('wms_item_packaging')->where('stock_item_id', $line->item_id)->where('is_active', true)
            ->where('qty_in_base', '>', 1)->get(['name', 'qty_in_base']);
        foreach ($packs as $p) {
            $size = (float) $p->qty_in_base;
            $multiple = $abs / $size;
            if (abs($multiple - round($multiple)) < 0.0001) {
                $hints[] = ['code' => 'UOM', 'text' => "The difference is exactly {$this->num(round($multiple))} {$p->name} of {$this->num($size)}: a pack counted as a unit, or the reverse?"];
                break;
            }
            if ((float) $line->counted_qty > 0 && abs((float) $line->counted_qty * $size - (float) $line->expected_qty) < 0.0001) {
                $hints[] = ['code' => 'UOM', 'text' => "Counted {$this->num((float) $line->counted_qty)} looks like {$p->name}s: that is {$this->num((float) $line->expected_qty)} units, as expected."];
                break;
            }
        }

        // A recent movement of exactly this size: missed, posted twice, or posted to the wrong bin.
        $move = DB::table('fin_stock_movements')->where('item_id', $line->item_id)->where('warehouse_id', $count->warehouse_id)
            ->where(fn ($q) => $q->where('bin_id', $line->bin_id)->orWhere('to_bin_id', $line->bin_id))
            ->where('created_at', '>=', $count->snapshot_at->copy()->subDays(14))
            ->whereRaw('ABS(qty) = ?', [$abs])->orderByDesc('id')->first(['type', 'reference', 'movement_date']);
        if ($move) {
            $hints[] = ['code' => 'MOVEMENT', 'text' => "A {$move->type} of the same quantity on {$move->movement_date}"
                .($move->reference ? " ({$move->reference})" : '').': missed, recorded twice, or put to the wrong bin?'];
        }

        // Opposite variance on the same item elsewhere in this count: stock in the wrong bin or under the wrong lot.
        $other = CountLine::query()->where('count_id', $count->id)->where('item_id', $line->item_id)->where('id', '!=', $line->id)
            ->whereRaw('ABS(variance_qty + ?) < 0.0001', [$variance])->with(['bin:id,code', 'lot:id,lot_number'])->first();
        if ($other) {
            $hints[] = $other->bin_id === $line->bin_id
                ? ['code' => 'LOT', 'text' => 'Lot '.($other->lot?->lot_number ?? 'without a number').' is out by the same amount the other way: lots swapped?']
                : ['code' => 'LOCATION', 'text' => 'Bin '.($other->bin?->code ?? '?').' is out by the same amount the other way: stock in the wrong bin?'];
        }

        // Stock moved while counting: the entry was compared with the live figure, but say so.
        $moved = (float) $line->entries()->orderByDesc('id')->value('moved_since_snapshot');
        if (abs($moved) > 0.0001) {
            $hints[] = ['code' => 'MOVED_DURING_COUNT', 'text' => "{$this->num($moved)} moved through this place after the count opened; check those movements were completed before it was counted."];
        }

        if ($hints === [] && $variance < 0) {
            $hints[] = ['code' => 'LOSS', 'text' => 'No paperwork explains the shortfall: damage, theft or an unrecorded issue.'];
        }

        return $hints;
    }

    /**
     * Count accuracy for a warehouse: the share of first counts that matched the ledger, and the
     * value of what did not, by item, location, counter, shift and cause.
     *
     * @return array{summary: array, by_item: list<array>, by_location: list<array>, by_counter: list<array>, by_shift: list<array>, by_cause: list<array>}
     */
    public function accuracy(?int $warehouseId, string $from, string $to): array
    {
        $lines = DB::table('wms_count_lines as cl')->join('wms_counts as c', 'c.id', '=', 'cl.count_id')
            ->where('c.status', Count::POSTED)
            ->when($this->tenantId(), fn ($q, $c) => $q->where('c.company_id', $c))
            ->when($warehouseId, fn ($q, $v) => $q->where('c.warehouse_id', $v))
            ->whereBetween('c.posted_at', [$from.' 00:00:00', $to.' 23:59:59']);

        // The first entry on each line: accuracy is about the stock as found, before recounts tidy it.
        $first = DB::table('wms_count_entries as e')->selectRaw('DISTINCT ON (e.count_line_id) e.count_line_id, e.user_id, e.counted_at, e.qty, e.expected_at_entry')
            ->orderBy('e.count_line_id')->orderBy('e.id');

        $base = (clone $lines)->joinSub($first, 'fe', 'fe.count_line_id', '=', 'cl.id')
            ->selectRaw('cl.*, fe.user_id AS counter_id, fe.counted_at AS first_at, (ABS(fe.qty - fe.expected_at_entry) < 0.0001) AS first_matched');

        $group = function (string $key, string $label) use ($base) {
            return DB::query()->fromSub($base, 'b')
                ->selectRaw("{$key} AS k, {$label} AS label, COUNT(*) AS lines, SUM(CASE WHEN b.first_matched THEN 1 ELSE 0 END) AS matched")
                ->selectRaw("SUM(CASE WHEN b.status IN ('ADJUSTED','ACCEPTED') THEN ABS(b.variance_value) ELSE 0 END) AS variance_value")
                ->selectRaw("SUM(CASE WHEN b.status IN ('ADJUSTED','ACCEPTED') THEN b.variance_value ELSE 0 END) AS net_value");
        };
        $shape = fn ($rows) => collect($rows)->map(fn ($r) => [
            'key' => $r->k, 'label' => $r->label ?? '—', 'lines' => (int) $r->lines, 'matched' => (int) $r->matched,
            'accuracy' => $r->lines ? round($r->matched / $r->lines * 100, 1) : null,
            'variance_value' => round((float) $r->variance_value, 2), 'net_value' => round((float) $r->net_value, 2),
        ])->sortBy('accuracy')->values()->all();

        $byItem = $group('b.item_id', 'i.code')->leftJoin('fin_stock_items as i', 'i.id', '=', 'b.item_id')->groupBy('b.item_id', 'i.code')->get();
        // Locations roll up to the zone (the top of the bin tree) so the result says where, not just which bin.
        $zone = '(WITH RECURSIVE up AS (SELECT id, parent_id, code FROM fin_stock_bins WHERE id = b.bin_id '
            .'UNION ALL SELECT p.id, p.parent_id, p.code FROM fin_stock_bins p JOIN up ON p.id = up.parent_id) '
            .'SELECT code FROM up WHERE parent_id IS NULL LIMIT 1)';
        $byLocation = $group("COALESCE({$zone}, 'No bin')", "COALESCE({$zone}, 'No bin')")->groupByRaw("COALESCE({$zone}, 'No bin')")->get();
        $byCounter = $group('b.counter_id', 'u.email')->leftJoin('users as u', 'u.id', '=', 'b.counter_id')->groupBy('b.counter_id', 'u.email')->get();
        $shift = "CASE WHEN EXTRACT(HOUR FROM b.first_at) >= 6 AND EXTRACT(HOUR FROM b.first_at) < 14 THEN 'Morning (06-14)' "
            ."WHEN EXTRACT(HOUR FROM b.first_at) >= 14 AND EXTRACT(HOUR FROM b.first_at) < 22 THEN 'Afternoon (14-22)' ELSE 'Night (22-06)' END";
        $byShift = $group($shift, $shift)->groupByRaw($shift)->get();

        $byCause = DB::query()->fromSub($base, 'b')->whereNotNull('b.causes')
            ->crossJoin(DB::raw("LATERAL jsonb_array_elements(b.causes::jsonb) AS cz"))
            ->selectRaw("cz->>'code' AS cause, COUNT(*) AS lines, SUM(ABS(COALESCE(b.variance_value,0))) AS value")
            ->groupByRaw("cz->>'code'")->orderByDesc('value')->get()
            ->map(fn ($r) => ['cause' => $r->cause, 'lines' => (int) $r->lines, 'value' => round((float) $r->value, 2)])->all();

        $total = DB::query()->fromSub($base, 'b')->selectRaw('COUNT(*) AS lines, SUM(CASE WHEN b.first_matched THEN 1 ELSE 0 END) AS matched')
            ->selectRaw("SUM(CASE WHEN b.status IN ('ADJUSTED','ACCEPTED') AND b.variance_value > 0 THEN b.variance_value ELSE 0 END) AS gains")
            ->selectRaw("SUM(CASE WHEN b.status IN ('ADJUSTED','ACCEPTED') AND b.variance_value < 0 THEN -b.variance_value ELSE 0 END) AS losses")
            ->first();

        return [
            'summary' => [
                'lines' => (int) $total->lines, 'matched' => (int) $total->matched,
                'accuracy' => $total->lines ? round($total->matched / $total->lines * 100, 1) : null,
                'gains' => round((float) $total->gains, 2), 'losses' => round((float) $total->losses, 2),
            ],
            'by_item' => array_slice($shape($byItem), 0, 25), 'by_location' => $shape($byLocation), 'by_counter' => $shape($byCounter),
            'by_shift' => $shape($byShift), 'by_cause' => $byCause,
        ];
    }

    /**
     * ABC classes from the last year's usage value: A is what makes the first 80% of value, B the
     * next 15%, C the rest. Written to the item profile so count plans and slotting can use it.
     *
     * @return array{A:int, B:int, C:int}
     */
    public function classify(int $companyId): array
    {
        $usage = DB::table('fin_stock_movements')->where('company_id', $companyId)
            ->where('qty', '<', 0)->whereNotIn('type', self::NON_USAGE)->whereNull('reversal_of_id')
            ->where('movement_date', '>=', now()->subYear()->toDateString())
            ->groupBy('item_id')->selectRaw('item_id, SUM(ABS(value)) + SUM(ABS(qty)) * 0.000001 AS v')->orderByDesc('v')
            ->pluck('v', 'item_id');
        $total = (float) $usage->sum();
        $out = ['A' => 0, 'B' => 0, 'C' => 0];
        $running = 0.0;

        $items = DB::table('fin_stock_items')->where('company_id', $companyId)->pluck('id');
        foreach ($items as $id) {
            $usage[$id] ??= 0;
        }
        foreach ($usage as $itemId => $v) {
            $share = $total > 0 ? $running / $total : 1.0;
            $class = $v <= 0 ? 'C' : ($share < 0.80 ? 'A' : ($share < 0.95 ? 'B' : 'C'));
            $running += (float) $v;
            ItemProfile::query()->updateOrCreate(['stock_item_id' => $itemId], ['company_id' => $companyId, 'abc_class' => $class, 'abc_computed_at' => now()]);
            $out[$class]++;
        }

        return $out;
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
