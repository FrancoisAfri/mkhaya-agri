<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use App\Modules\Operations\Manufacturing\Models\StandardCost;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Standard cost (MFG-011): material at standard prices through the approved BOM (scrap and batch
 * rounding included), labour and machine time through the approved routing at the work centres'
 * rates, overhead at the configured share, subcontracting at its agreed price, all divided by the
 * output the routing's standard yield expects.
 *
 * A calculation is a draft. Approving it is the decision that it is the standard -- and the price a
 * product's own stock item carries as its standard cost from then on.
 */
class StandardCosting
{
    public function __construct(
        private readonly BomResolver $boms,
        private readonly RoutingResolver $routings,
        private readonly BomExploder $exploder,
        private readonly RoutingCalculator $calculator,
    ) {}

    public function calculate(Item $item, float $costingQty, ?User $actor, ?DateTimeInterface $asOf = null): StandardCost
    {
        $asOf = $asOf ? Carbon::instance($asOf) : now();

        if ($costingQty <= 0) {
            throw new DomainException('Cost a quantity greater than zero.');
        }
        if (! $item->isManufactured()) {
            throw new DomainException('Only a manufactured product has a production standard cost.');
        }

        $bom = $this->boms->applicable($item->id, $asOf);
        if (! $bom) {
            throw new DomainException('This product has no approved bill of materials to cost.');
        }
        $routing = $this->routings->applicable($item->id, $asOf);

        $exploded = $this->exploder->explode($bom->load(['lines.uom', 'lines.component', 'yieldUom']), $costingQty, true, $asOf);
        $lines = [];
        $warnings = [];

        // ── Material, by-products credited ──────────────────────────────────────────────────
        $material = 0.0;
        foreach ([[$exploded['requirements'], 1], [$exploded['by_products'], -1]] as [$rows, $sign]) {
            foreach ($rows as $r) {
                $stock = FinStockItem::withoutGlobalScopes()->find($r['stock_item_id']);
                [$rate, $basis] = $this->materialRate($stock, $asOf);
                if ($rate <= 0 && $sign > 0) {
                    $warnings[] = "No price for material {$stock?->code}";
                } elseif (str_contains($basis, 'average')) {
                    $warnings[] = "{$stock?->code} priced at average cost: it has no standard price";
                }
                $amount = $sign * $rate * (float) $r['quantity'];
                $material += $amount;
                $lines[] = ['category' => 'MATERIAL', 'reference' => trim(($stock?->code ?? '?').($sign < 0 ? ' (by-product credit)' : '')),
                    'stock_item_id' => $stock?->id, 'quantity' => (float) $r['quantity'], 'uom' => $r['uom'] ?? null,
                    'rate' => $rate, 'amount' => $amount, 'basis' => $sign < 0 ? "{$basis}, credited" : $basis];
            }
        }

        // ── Labour, machine, subcontract through the routing ────────────────────────────────
        $labour = $machine = $subcontract = $labourMinutes = $machineMinutes = 0.0;
        if ($routing) {
            $estimate = $this->calculator->estimate($routing, $costingQty);
            $ops = RoutingOperation::query()->where('routing_id', $routing->id)->with('workCentre')->get()->keyBy('uuid');

            foreach ($estimate['operations'] as $row) {
                $op = $ops[$row['operation_id']] ?? null;
                if (! $op) {
                    continue;
                }
                $ref = "{$op->sequence} {$op->name}";

                if ($op->is_subcontracted) {
                    $rate = (float) $op->subcontract_cost_per_unit;
                    if ($rate <= 0) {
                        $warnings[] = "No subcontract price for {$ref}";
                    }
                    $subcontract += $rate * $costingQty;
                    $lines[] = ['category' => 'SUBCONTRACT', 'reference' => $ref, 'routing_operation_id' => $op->id,
                        'quantity' => $costingQty, 'rate' => $rate, 'amount' => $rate * $costingQty, 'basis' => 'subcontract price per unit on the routing'];

                    continue;
                }

                $wc = $op->workCentre;
                $machineRate = (float) ($wc?->cost_per_hour ?? 0);
                $labourRate = (float) ($wc?->labour_rate_per_hour ?? 0);
                if ($row['minutes'] > 0 && $machineRate <= 0) {
                    $warnings[] = "No machine rate at ".($wc?->code ?? $ref);
                }
                if ($row['labour_minutes'] > 0 && $labourRate <= 0) {
                    $warnings[] = "No labour rate at ".($wc?->code ?? $ref);
                }

                $machineMinutes += $row['minutes'];
                $labourMinutes += $row['labour_minutes'];
                $machine += $row['minutes'] / 60 * $machineRate;
                $labour += $row['labour_minutes'] / 60 * $labourRate;

                $lines[] = ['category' => 'MACHINE', 'reference' => $ref, 'routing_operation_id' => $op->id, 'quantity' => $row['minutes'] / 60,
                    'uom' => 'h', 'rate' => $machineRate, 'amount' => $row['minutes'] / 60 * $machineRate, 'basis' => "machine rate at {$wc?->code}"];
                $lines[] = ['category' => 'LABOUR', 'reference' => $ref, 'routing_operation_id' => $op->id, 'quantity' => $row['labour_minutes'] / 60,
                    'uom' => 'h', 'rate' => $labourRate, 'amount' => $row['labour_minutes'] / 60 * $labourRate, 'basis' => "labour rate at {$wc?->code}"];
            }
        } else {
            $warnings[] = 'No approved routing: labour, machine and overhead are not costed';
        }

        // ── Overhead ────────────────────────────────────────────────────────────────────────
        $percent = FactoryPolicy::for(null, $item->company_id)->overhead_percent;
        $overhead = $percent !== null ? ($labour + $machine) * (float) $percent / 100 : 0.0;
        if ($percent === null && ($labour + $machine) > 0) {
            $warnings[] = 'No overhead rate configured';
        }
        $lines[] = ['category' => 'OVERHEAD', 'reference' => 'Overhead', 'quantity' => $labour + $machine, 'rate' => (float) ($percent ?? 0) / 100,
            'amount' => $overhead, 'basis' => $percent !== null ? "{$percent}% of labour and machine" : 'no overhead rate configured'];

        // Output: whole batches, less the routing's standard yield.
        $output = (float) $exploded['produced'] * (($routing ? (float) $routing->standard_yield_percent : 100) / 100);
        $total = $material + $labour + $machine + $overhead + $subcontract;

        return DB::transaction(function () use ($item, $costingQty, $actor, $asOf, $bom, $routing, $lines, $warnings, $material, $labour,
            $machine, $overhead, $subcontract, $total, $output, $labourMinutes, $machineMinutes, $percent) {
            $sc = StandardCost::create([
                'company_id' => $item->company_id, 'item_id' => $item->id,
                'version' => (int) StandardCost::withoutGlobalScopes()->where('item_id', $item->id)->max('version') + 1,
                'status' => StandardCost::DRAFT, 'costing_qty' => $costingQty, 'bom_id' => $bom->id, 'routing_id' => $routing?->id,
                'expected_output' => round($output, 6),
                'material_cost' => round($material, 4), 'labour_cost' => round($labour, 4), 'machine_cost' => round($machine, 4),
                'overhead_cost' => round($overhead, 4), 'subcontract_cost' => round($subcontract, 4), 'total_cost' => round($total, 4),
                'unit_cost' => $output > 0 ? round($total / $output, 6) : 0,
                'labour_minutes' => round($labourMinutes, 4), 'machine_minutes' => round($machineMinutes, 4),
                'overhead_percent' => $percent, 'warnings' => array_values(array_unique($warnings)) ?: null,
                'effective_from' => $asOf->toDateString(), 'created_by' => $actor?->id,
            ]);

            foreach ($lines as $l) {
                $sc->lines()->create($l + ['company_id' => $item->company_id, 'quantity' => round($l['quantity'] ?? 0, 6),
                    'rate' => round($l['rate'], 6), 'amount' => round($l['amount'], 4)]);
            }

            return $sc->refresh();
        });
    }

    /**
     * Approve a draft: it becomes the standard, the previous one is superseded, and the product's
     * stock item carries the new unit cost as its standard cost. A standard with a material nobody
     * has priced is refused -- it would understate every variance measured against it.
     */
    public function approve(StandardCost $sc, ?User $actor): StandardCost
    {
        if ($sc->status !== StandardCost::DRAFT) {
            throw new DomainException('Only a draft can be approved.');
        }
        if (collect($sc->warnings ?? [])->contains(fn ($w) => str_starts_with($w, 'No price for material'))) {
            throw new DomainException('A material has no price. Give it a standard price, then calculate again.');
        }

        return DB::transaction(function () use ($sc, $actor) {
            StandardCost::query()->where('item_id', $sc->item_id)->where('status', StandardCost::APPROVED)
                ->update(['status' => StandardCost::SUPERSEDED]);

            $sc->update(['status' => StandardCost::APPROVED, 'approved_by' => $actor?->id, 'approved_at' => now()]);

            $stockItemId = Item::query()->whereKey($sc->item_id)->value('stock_item_id');
            FinStockItem::withoutGlobalScopes()->whereKey($stockItemId)->update(['standard_cost' => round((float) $sc->unit_cost, 4)]);

            return $sc->refresh();
        });
    }

    public function current(int $itemId): ?StandardCost
    {
        return StandardCost::query()->where('item_id', $itemId)->where('status', StandardCost::APPROVED)->latest('version')->first();
    }

    /** @return array{0: float, 1: string} rate and how it was found */
    private function materialRate(?FinStockItem $stock, Carbon $asOf): array
    {
        if (! $stock) {
            return [0.0, 'unknown item'];
        }

        // A made component is priced at its own approved standard -- the roll-up.
        $made = Item::query()->where('stock_item_id', $stock->id)->first();
        if ($made && $made->isManufactured() && ($std = $this->current($made->id))) {
            return [(float) $std->unit_cost, "approved standard v{$std->version}"];
        }

        if ((float) $stock->standard_cost > 0) {
            return [(float) $stock->standard_cost, 'standard price'];
        }
        if ((float) $stock->avg_cost > 0) {
            return [(float) $stock->avg_cost, 'average cost (no standard price)'];
        }

        return [0.0, 'no price'];
    }
}
