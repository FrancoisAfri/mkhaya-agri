<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use App\Modules\Operations\WarehouseManagement\Models\LossRecovery;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Damage and wastage after the fact (WMS-081): what was recovered from whom, and a register that
 * keeps confirmed write-offs apart from losses that are only suspected or still open -- the two
 * must never be added together, or a suspicion becomes a number in somebody's report.
 */
class LossControl
{
    use ScopesRawQueries;

    /** Reason categories that are a loss when stock goes out. */
    public const LOSS_CATEGORIES = ['LOSS', 'DAMAGE', 'QUALITY', 'COUNT'];

    public function openRecovery(StockAdjustment $adjustment, array $data, User $by): LossRecovery
    {
        if ((float) $adjustment->qty_delta >= 0) {
            throw new RuntimeException('Only a loss can be recovered.');
        }
        if (! in_array($data['kind'] ?? null, LossRecovery::KINDS, true)) {
            throw new RuntimeException('Say who the loss is being recovered from.');
        }
        $claimed = round((float) ($data['amount_claimed'] ?? 0), 2);
        if ($claimed <= 0) {
            throw new RuntimeException('Claim an amount greater than zero.');
        }
        $already = (float) LossRecovery::query()->where('adjustment_id', $adjustment->id)->whereNotIn('status', ['DECLINED', 'WITHDRAWN'])->sum('amount_claimed');
        $loss = abs((float) $adjustment->value_estimate);
        if ($loss > 0 && $already + $claimed > $loss + 0.005) {
            throw new RuntimeException('Claims would exceed the '.number_format($loss, 2).' lost; '.number_format($already, 2).' is already being claimed.');
        }

        return LossRecovery::create([
            'company_id' => $adjustment->company_id, 'adjustment_id' => $adjustment->id, 'kind' => $data['kind'],
            'party_id' => $data['party_id'] ?? null, 'claim_reference' => $data['claim_reference'] ?? null,
            'amount_claimed' => $claimed, 'status' => 'OPEN', 'notes' => $data['notes'] ?? null, 'created_by' => $by->id,
        ]);
    }

    public function progress(LossRecovery $recovery, string $status, ?float $recovered, ?string $note): LossRecovery
    {
        if (in_array($recovery->status, ['SETTLED', 'DECLINED', 'WITHDRAWN'], true)) {
            throw new RuntimeException('That claim is closed.');
        }
        if (! in_array($status, ['SUBMITTED', 'SETTLED', 'DECLINED', 'WITHDRAWN'], true)) {
            throw new RuntimeException("Unknown claim status {$status}.");
        }
        if ($status === 'SETTLED' && ($recovered === null || $recovered < 0 || $recovered > (float) $recovery->amount_claimed + 0.005)) {
            throw new RuntimeException('Record what was recovered, up to the amount claimed.');
        }
        $recovery->update([
            'status' => $status, 'amount_recovered' => $status === 'SETTLED' ? round((float) $recovered, 2) : $recovery->amount_recovered,
            'settled_at' => in_array($status, ['SETTLED', 'DECLINED', 'WITHDRAWN'], true) ? now() : null,
            'notes' => trim(($recovery->notes ? $recovery->notes."\n" : '').($note ?? '')) ?: null,
        ]);

        return $recovery;
    }

    /**
     * The loss register for a period.
     *
     * @return array{confirmed: array, suspected: array, recoveries: array, by_reason: list<array>}
     */
    public function register(?int $warehouseId, string $from, string $to): array
    {
        $range = [$from.' 00:00:00', $to.' 23:59:59'];
        $losses = fn () => DB::table('wms_stock_adjustments as a')->join('wms_reason_codes as r', 'r.id', '=', 'a.reason_code_id')
            ->where('a.qty_delta', '<', 0)->whereIn('r.category', self::LOSS_CATEGORIES)
            ->when($warehouseId, fn ($q, $v) => $q->where('a.warehouse_id', $v))
            ->when($this->tenantId(), fn ($q, $c) => $q->where('a.company_id', $c));

        $confirmed = $losses()->where('a.status', StockAdjustment::POSTED)->whereBetween('a.occurred_at', $range)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(a.value_estimate), 0) AS v')->first();
        // Confirmed elsewhere: stock scrapped by a recall, an inspection or a return decision.
        $scrapped = DB::table('fin_stock_movements')->where('type', 'scrap')->whereNull('reversal_of_id')
            ->whereIn('source_type', ['RECALL', 'INSPECTION', 'WMS_RETURN', 'WMS_STORAGE'])->whereBetween('created_at', $range)
            ->when($this->tenantId(), fn ($q, $c) => $q->where('company_id', $c))
            ->when($warehouseId, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(ABS(value)), 0) AS v')->first();

        // Suspected or unresolved: waiting for approval, count variances not yet adjusted, short picks on hold.
        $pending = $losses()->where('a.status', StockAdjustment::PENDING)->selectRaw('COUNT(*) AS n, COALESCE(SUM(a.value_estimate), 0) AS v')->first();
        $countOpen = DB::table('wms_count_lines as l')->join('wms_counts as c', 'c.id', '=', 'l.count_id')
            ->where('l.variance_qty', '<', 0)->whereIn('l.status', ['VARIANCE'])->whereIn('c.status', ['COUNTING', 'REVIEW'])
            ->when($this->tenantId(), fn ($q, $c) => $q->where('c.company_id', $c))
            ->when($warehouseId, fn ($q, $v) => $q->where('c.warehouse_id', $v))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(ABS(l.variance_value)), 0) AS v')->first();
        $shortHeld = DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')
            ->where('p.status', 'ON_HOLD')->where('p.qty', '>', 0)
            ->when($this->tenantId(), fn ($q, $c) => $q->where('p.company_id', $c))
            ->whereExists(fn ($q) => $q->from('fin_stock_movements as m')->whereColumn('m.item_id', 'p.item_id')
                ->whereRaw('m.bin_id IS NOT DISTINCT FROM p.bin_id')->where('m.reason_code', 'SHORT_PICK'))
            ->when($warehouseId, fn ($q, $v) => $q->where('p.warehouse_id', $v))
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(p.qty * CASE WHEN COALESCE(i.is_valued, true) THEN COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost) ELSE 0 END), 0) AS v')->first();

        $recoveries = DB::table('wms_loss_recoveries as lr')->join('wms_stock_adjustments as a', 'a.id', '=', 'lr.adjustment_id')
            ->when($this->tenantId(), fn ($q, $c) => $q->where('lr.company_id', $c))
            ->when($warehouseId, fn ($q, $v) => $q->where('a.warehouse_id', $v))
            ->whereBetween('lr.created_at', $range)
            ->selectRaw("COALESCE(SUM(CASE WHEN lr.status IN ('OPEN','SUBMITTED') THEN lr.amount_claimed ELSE 0 END), 0) AS outstanding")
            ->selectRaw("COALESCE(SUM(CASE WHEN lr.status = 'SETTLED' THEN lr.amount_recovered ELSE 0 END), 0) AS recovered")->first();

        $byReason = $losses()->where('a.status', StockAdjustment::POSTED)->whereBetween('a.occurred_at', $range)
            ->groupBy('r.code', 'r.name')->selectRaw('r.code, r.name, COUNT(*) AS n, SUM(a.value_estimate) AS v')->orderByDesc('v')->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'count' => (int) $r->n, 'value' => round((float) $r->v, 2)])->all();

        $confirmedValue = round((float) $confirmed->v + (float) $scrapped->v, 2);

        return [
            'confirmed' => ['adjustments' => (int) $confirmed->n, 'adjustment_value' => round((float) $confirmed->v, 2),
                'scrapped' => (int) $scrapped->n, 'scrapped_value' => round((float) $scrapped->v, 2), 'total' => $confirmedValue],
            'suspected' => ['pending_adjustments' => (int) $pending->n, 'pending_value' => round((float) $pending->v, 2),
                'count_variances' => (int) $countOpen->n, 'count_value' => round((float) $countOpen->v, 2),
                'short_picks_held' => (int) $shortHeld->n, 'short_pick_value' => round((float) $shortHeld->v, 2),
                'total' => round((float) $pending->v + (float) $countOpen->v + (float) $shortHeld->v, 2)],
            'recoveries' => ['outstanding' => round((float) $recoveries->outstanding, 2), 'recovered' => round((float) $recoveries->recovered, 2),
                'net_confirmed' => round($confirmedValue - (float) $recoveries->recovered, 2)],
            'by_reason' => $byReason,
        ];
    }
}
