<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLayer;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Operations\WarehouseManagement\Models\LandedCost;
use App\Modules\Operations\WarehouseManagement\Models\LandedCostLine;
use App\Modules\Operations\WarehouseManagement\Models\Revaluation;
use App\Modules\Procurement\Models\GoodsReceivedNote;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Changes to what stock is worth that are not movements (WMS-100, WMS-101): landed costs spread
 * over the goods they brought in, and revaluations. Both are documents that post exactly once --
 * the journal carries the document as its idempotency key -- through treatments finance approved.
 *
 * Value is changed where the costing method keeps it: the weighted average, the remaining FIFO
 * layers, or the standard cost. What has already left is not stock any more, so its share of a
 * landed cost goes to cost of sales rather than inflating what is left.
 */
class Valuation
{
    public function __construct(private readonly Ledger $ledger, private readonly WarehouseAccounting $accounting) {}

    // ── Landed cost ──────────────────────────────────────────────────────────────────────────

    /**
     * @param array{grn_id?:?int, lines?:list<array{item_id:int, qty:float, value?:float, weight_kg?:float, movement_id?:?int}>,
     *              components:list<array{kind:string, description?:string, amount:float}>, basis?:string, party_id?:?int, invoice_reference?:?string} $data
     */
    public function draftLandedCost(array $data, User $by): LandedCost
    {
        $components = array_values(array_filter($data['components'] ?? [], fn ($c) => (float) ($c['amount'] ?? 0) > 0));
        if ($components === []) {
            throw new RuntimeException('Enter at least one cost: freight, duty, insurance or handling.');
        }
        foreach ($components as $c) {
            if (! in_array($c['kind'], LandedCost::KINDS, true)) {
                throw new RuntimeException("Unknown cost type {$c['kind']}.");
            }
        }
        $basis = $data['basis'] ?? 'VALUE';
        if (! in_array($basis, ['VALUE', 'QTY', 'WEIGHT'], true)) {
            throw new RuntimeException('Spread the cost by value, quantity or weight.');
        }
        $lines = $data['lines'] ?? [];
        if (! empty($data['grn_id'])) {
            $grn = GoodsReceivedNote::query()->with('items')->findOrFail($data['grn_id']);
            $lines = $grn->items->filter(fn ($i) => $i->stock_item_id && (float) $i->base_qty > 0)->map(fn ($i) => [
                'item_id' => $i->stock_item_id, 'qty' => (float) $i->base_qty, 'value' => round((float) $i->base_qty * (float) $i->unit_cost, 2),
                'movement_id' => $i->movement_id,
            ])->values()->all();
        }
        if ($lines === []) {
            throw new RuntimeException('Name the goods the cost belongs to: a goods-received note or the items themselves.');
        }

        return DB::transaction(function () use ($data, $components, $basis, $lines, $by) {
            $companyId = (int) FinStockItem::query()->findOrFail($lines[0]['item_id'])->company_id;
            $lc = LandedCost::create([
                'company_id' => $companyId, 'number' => $this->nextNumber($companyId, 'LC', LandedCost::class), 'grn_id' => $data['grn_id'] ?? null,
                'party_id' => $data['party_id'] ?? null, 'invoice_reference' => $data['invoice_reference'] ?? null, 'basis' => $basis,
                'components' => $components, 'total' => round(array_sum(array_column($components, 'amount')), 2), 'status' => 'DRAFT', 'created_by' => $by->id,
            ]);
            foreach ($lines as $l) {
                $item = FinStockItem::query()->findOrFail($l['item_id']);
                $weight = (float) ($l['weight_kg'] ?? 0) ?: (float) DB::table('mfg_items')->where('stock_item_id', $item->id)->value('weight_kg') * (float) $l['qty'];
                LandedCostLine::create(['company_id' => $companyId, 'landed_cost_id' => $lc->id, 'item_id' => $item->id, 'movement_id' => $l['movement_id'] ?? null,
                    'qty' => $l['qty'], 'base_value' => $l['value'] ?? round((float) $l['qty'] * ((float) $item->avg_cost ?: (float) $item->standard_cost), 2),
                    'weight_kg' => $weight]);
            }

            return $lc->fresh('lines');
        });
    }

    /** Spread the cost and post it: Dr inventory (what is still here) and cost of sales (what has gone), Cr Landed Cost Clearing. */
    public function postLandedCost(LandedCost $lc, User $by): LandedCost
    {
        if ($lc->status !== 'DRAFT') {
            throw new RuntimeException("{$lc->number} is ".strtolower($lc->status).'.');
        }
        $rule = $this->accounting->assertAllowed((int) $lc->company_id, 'LANDED_COST');
        $clearing = $this->accounting->code($rule->credit);
        $cogs = $this->accounting->code('cogs');
        if (! $clearing) {
            throw new RuntimeException('Map an account for landed cost clearing before posting.');
        }
        $lines = $lc->lines()->with('item')->get();
        $weights = $lines->map(fn ($l) => match ($lc->basis) { 'QTY' => (float) $l->qty, 'WEIGHT' => (float) $l->weight_kg, default => (float) $l->base_value });
        $sum = $weights->sum();
        if ($sum <= 0) {
            throw new RuntimeException($lc->basis === 'WEIGHT' ? 'The goods have no weight recorded; spread the cost by value or quantity.' : 'Nothing to spread the cost over.');
        }

        return DB::transaction(function () use ($lc, $lines, $weights, $sum, $clearing, $cogs, $by) {
            $total = (float) $lc->total;
            $left = $total;
            $debits = [];
            $toInv = $toCogs = 0.0;
            foreach ($lines->values() as $i => $line) {
                $share = $i === $lines->count() - 1 ? round($left, 2) : round($total * $weights[$i] / $sum, 2);
                $left -= $share;
                $item = FinStockItem::query()->lockForUpdate()->findOrFail($line->item_id);
                // Only what is still on hand can carry the cost; the rest has been sold or used.
                $onHand = $item->isValued() ? min((float) $item->qty_on_hand, (float) $line->qty) : 0.0;
                $inv = (float) $line->qty > 0 ? round($share * $onHand / (float) $line->qty, 2) : 0.0;
                if ($item->cost_method === 'standard') {
                    $inv = 0.0; // standard cost does not move with a receipt; the difference is a cost of sales variance
                }
                $rest = round($share - $inv, 2);
                $line->update(['allocated' => $share, 'to_inventory' => $inv, 'to_cogs' => $rest]);
                if ($inv > 0) {
                    $this->addValue($item, $inv);
                    $debits[] = ['account' => $item->inventory_account_id, 'amount' => $inv, 'narration' => "{$lc->number} {$item->code}"];
                }
                if ($rest > 0) {
                    $debits[] = ['account' => $item->cogs_account_id ?: $cogs, 'amount' => $rest, 'narration' => "{$lc->number} {$item->code} (already gone)"];
                }
                $toInv += $inv;
                $toCogs += $rest;
            }
            $journal = $this->ledger->post(new FinancialEvent(
                sourceModule: 'inventory', eventType: 'stock.landed_cost', debits: $debits,
                credits: [['account' => $clearing, 'amount' => round($toInv + $toCogs, 2), 'narration' => "{$lc->number} landed cost"]],
                eventDate: now(), companyId: (int) $lc->company_id, sourceRef: "wms_landed_cost:{$lc->id}",
                description: "Landed cost {$lc->number}".($lc->invoice_reference ? " ({$lc->invoice_reference})" : ''),
                idempotencyKey: "wms_landed_cost:{$lc->id}",
            ));
            $lc->update(['status' => 'POSTED', 'journal_id' => $journal->id, 'to_inventory' => round($toInv, 2), 'to_cogs' => round($toCogs, 2),
                'posted_by' => $by->id, 'posted_at' => now()]);

            return $lc->fresh('lines');
        });
    }

    // ── Revaluation ──────────────────────────────────────────────────────────────────────────

    public function requestRevaluation(FinStockItem $item, float $newCost, string $reason, User $by): Revaluation
    {
        if (blank($reason)) {
            throw new RuntimeException('Say why the stock is being revalued.');
        }
        if (! $item->isValued()) {
            throw new RuntimeException("{$item->code} is held for a client and carries no value.");
        }
        if ($newCost < 0) {
            throw new RuntimeException('A cost cannot be negative.');
        }
        $qty = (float) $item->qty_on_hand;
        if ($qty <= 0) {
            throw new RuntimeException("There is no {$item->code} on hand to revalue.");
        }
        $old = $this->unitCost($item);
        $delta = round(($newCost - $old) * $qty, 2);
        if (abs($delta) < 0.01) {
            throw new RuntimeException('The new cost is the same as the current one.');
        }
        $this->accounting->rule((int) $item->company_id, 'REVALUATION');

        return DB::transaction(function () use ($item, $qty, $old, $newCost, $delta, $reason, $by) {
            $r = Revaluation::create([
                'company_id' => $item->company_id, 'number' => $this->nextNumber((int) $item->company_id, 'RVL', Revaluation::class), 'item_id' => $item->id,
                'qty' => $qty, 'old_cost' => $old, 'new_cost' => $newCost, 'delta' => $delta, 'reason' => $reason, 'status' => 'PENDING_APPROVAL', 'requested_by' => $by->id,
            ]);
            app(WarehouseApprovals::class)->start((int) $item->company_id, 'REVALUATION', $r->id, $r->number, abs($delta), $by->id);

            return $r;
        });
    }

    public function decideRevaluation(Revaluation $r, bool $approve, ?string $note, User $by): Revaluation
    {
        if ($r->status !== 'PENDING_APPROVAL') {
            throw new RuntimeException("{$r->number} has already been decided.");
        }
        if ((int) $r->requested_by === $by->id && ! app(WarehouseOverrides::class)->active($by, 'REVALUATION')) {
            throw new RuntimeException('A revaluation is approved by somebody other than the person who asked for it.');
        }
        if (! $approve) {
            if (blank($note)) {
                throw new RuntimeException('Say why the revaluation is rejected.');
            }
            app(WarehouseApprovals::class)->reject('REVALUATION', $r->id, (int) $r->requested_by, $by, (string) $note);
            $r->update(['status' => 'REJECTED', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);

            return $r;
        }
        $rule = $this->accounting->assertAllowed((int) $r->company_id, 'REVALUATION');
        $other = $this->accounting->code($rule->credit);
        if (! $other) {
            throw new RuntimeException('Map an account for inventory revaluation before posting.');
        }

        return DB::transaction(function () use ($r, $by, $note, $other) {
            // Every level of the company's policy approves before the cost changes (WMS-191).
            if (! app(WarehouseApprovals::class)->approve((int) $r->company_id, 'REVALUATION', $r->id, $r->number, abs((float) $r->delta), (int) $r->requested_by, $by, $note)) {
                return $r->fresh();
            }
            $item = FinStockItem::query()->lockForUpdate()->findOrFail($r->item_id);
            // Stock may have moved since the request: revalue what is there now, at the approved cost.
            $qty = (float) $item->qty_on_hand;
            $delta = round(((float) $r->new_cost - $this->unitCost($item)) * $qty, 2);
            if (abs($delta) >= 0.01) {
                $inv = ['account' => $item->inventory_account_id, 'amount' => abs($delta), 'narration' => "{$r->number} {$item->code}"];
                $rev = ['account' => $other, 'amount' => abs($delta), 'narration' => "{$r->number} {$item->code}"];
                $journal = $this->ledger->post(new FinancialEvent(
                    sourceModule: 'inventory', eventType: 'stock.revaluation', debits: [$delta > 0 ? $inv : $rev], credits: [$delta > 0 ? $rev : $inv],
                    eventDate: now(), companyId: (int) $r->company_id, sourceRef: "wms_revaluation:{$r->id}",
                    description: "Revaluation {$r->number}: {$item->code} to ".number_format((float) $r->new_cost, 4), idempotencyKey: "wms_revaluation:{$r->id}",
                ));
                $this->setCost($item, (float) $r->new_cost);
            }
            $override = (int) $r->requested_by === $by->id ? app(WarehouseOverrides::class)->active($by, 'REVALUATION') : null;
            $r->update(['status' => 'POSTED', 'qty' => $qty, 'delta' => $delta, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note, 'override_id' => $override?->id,
                'journal_id' => $journal->id ?? null]);

            return $r;
        });
    }

    public function unitCost(FinStockItem $item): float
    {
        return match ($item->cost_method) {
            'standard' => (float) $item->standard_cost,
            'fifo' => ($q = (float) FinStockLayer::query()->where('item_id', $item->id)->sum('qty_remaining')) > 0
                ? round((float) FinStockLayer::query()->where('item_id', $item->id)->sum(DB::raw('qty_remaining * unit_cost')) / $q, 4) : (float) $item->avg_cost,
            default => (float) $item->avg_cost,
        };
    }

    /** Add value to what is on hand, where the costing method keeps it. */
    private function addValue(FinStockItem $item, float $value): void
    {
        $qty = (float) $item->qty_on_hand;
        if ($qty <= 0) {
            return;
        }
        if ($item->cost_method === 'fifo') {
            $remaining = (float) FinStockLayer::query()->where('item_id', $item->id)->where('qty_remaining', '>', 0)->sum('qty_remaining');
            if ($remaining > 0) {
                FinStockLayer::query()->where('item_id', $item->id)->where('qty_remaining', '>', 0)
                    ->update(['unit_cost' => DB::raw('unit_cost + '.round($value / $remaining, 6))]);
            }
        }
        $item->update(['avg_cost' => round((float) $item->avg_cost + $value / $qty, 4)]);
    }

    private function setCost(FinStockItem $item, float $cost): void
    {
        if ($item->cost_method === 'fifo') {
            FinStockLayer::query()->where('item_id', $item->id)->where('qty_remaining', '>', 0)->update(['unit_cost' => $cost]);
        }
        $item->update($item->cost_method === 'standard' ? ['standard_cost' => $cost] : ['avg_cost' => $cost]);
    }

    private function nextNumber(int $companyId, string $prefix, string $model): string
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [crc32("wms-{$prefix}-number:{$companyId}")]);
        $p = $prefix.'-'.now()->format('Y').'-';
        $last = $model::query()->where('company_id', $companyId)->where('number', 'like', $p.'%')->max('number');

        return $p.str_pad((string) ((int) substr((string) $last, strlen($p)) + 1), 5, '0', STR_PAD_LEFT);
    }
}
