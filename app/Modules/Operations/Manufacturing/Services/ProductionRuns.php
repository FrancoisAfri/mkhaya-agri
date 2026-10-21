<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOutput;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A production run: a batch recorded from what actually went in and what came out.
 *
 * Grading, sorting and packing are not made to a bill of materials — the shares come from the
 * produce. So a run takes its inputs (by lot) and packaging as they were used, issues them into
 * work in progress, and receives every output — as many items, lots and grades as the batch made —
 * out of it at its share of that cost by quantity, so work in progress clears to nothing. The run
 * is an ordinary order otherwise: numbered at its factory, evented, closed, and traced by the
 * stock ledger, so Traceability and Recalls follow its lots like any other order's.
 */
class ProductionRuns
{
    public const DEMAND_TYPE = 'RUN';

    public function __construct(
        private readonly StockLedger $stock,
        private readonly ProductionOrders $orders,
        private readonly NumberSequences $numbers,
    ) {}

    /**
     * @param  array{factory: Factory, warehouse: FinWarehouse, performed_on?: ?string, description?: ?string,
     *               demand_type?: ?string, demand_id?: ?int, demand_reference?: ?string, notes?: ?string,
     *               loss_qty?: float, loss_reason?: ?string, labour_cost?: ?float, labour_minutes?: ?int,
     *               quality_passed?: bool, quality_checks?: array, dimensions?: array, key?: string,
     *               inputs: list<array{item: FinStockItem, qty: float, lot_id?: ?int, warehouse?: ?FinWarehouse}>,
     *               packaging?: list<array{item: FinStockItem, qty: float, warehouse?: ?FinWarehouse}>,
     *               outputs: list<array{item: FinStockItem, qty: float, lot_number: string, grade?: ?string, expires_on?: ?string,
     *                                   warehouse?: ?FinWarehouse, bin_id?: ?int, quarantine?: bool}>}  $run
     */
    public function record(array $run, ?User $actor): PO
    {
        $factory = $run['factory'];
        $inputs = $run['inputs'] ?? [];
        $outputs = $run['outputs'] ?? [];
        if (! $inputs || ! $outputs) {
            throw new DomainException('A run takes at least one input and makes at least one output.');
        }
        foreach ([...$inputs, ...($run['packaging'] ?? []), ...$outputs] as $line) {
            if ((float) $line['qty'] <= 0) {
                throw new DomainException('Every input and output needs a quantity greater than zero.');
            }
        }
        $on = $run['performed_on'] ?? today()->toDateString();
        $outQty = array_sum(array_map(fn ($o) => (float) $o['qty'], $outputs));
        $passed = $run['quality_passed'] ?? true;
        $key = $run['key'] ?? null;

        if (! \App\Modules\Operations\Manufacturing\Models\NumberSequence::query()->where('company_id', $factory->company_id)
            ->where('document_type', 'PRODUCTION_ORDER')->exists()) {
            app(ManufacturingReferenceData::class)->installFor((int) $factory->company_id);
        }

        return DB::transaction(function () use ($run, $factory, $inputs, $outputs, $on, $outQty, $passed, $key, $actor) {
            $order = PO::create([
                'company_id' => $factory->company_id, 'order_type' => PO::RUN,
                'number' => $this->numbers->next($factory->company_id, 'PRODUCTION_ORDER', $factory->id),
                'factory_id' => $factory->id, 'warehouse_id' => $run['warehouse']->id, 'component_warehouse_id' => $run['warehouse']->id,
                'item_id' => $this->itemFor($outputs[0]['item'], $actor)->id, 'planned_qty' => $outQty, 'due_date' => $on,
                // A run made to a recipe keeps the exact approved revision it was made to.
                'bom_id' => $run['bom_id'] ?? null,
                'priority' => 3, 'status' => PO::IN_PRODUCTION, 'demand_type' => $run['demand_type'] ?? self::DEMAND_TYPE,
                'demand_id' => $run['demand_id'] ?? null, 'demand_reference' => $run['demand_reference'] ?? null,
                'planned_start' => $on, 'planned_finish' => $on, 'notes' => $run['notes'] ?? ($run['description'] ?? null),
                'created_by' => $actor?->id, 'approved_by' => $actor?->id, 'approved_at' => now(), 'released_by' => $actor?->id,
                'released_at' => now(), 'started_by' => $actor?->id, 'started_at' => now(),
            ]);
            $src = fn (string $part) => ['source_type' => PO::SOURCE_TYPE, 'source_id' => $order->id, 'date' => $on, 'reference' => $order->number,
                'idempotency_key' => $key ? "{$key}:{$part}" : null, 'dimensions' => $run['dimensions'] ?? null];

            // Inputs and packaging into work in progress, each a component line as it was used.
            $material = 0.0;
            foreach ([['COMPONENT', $inputs], ['PACKAGING', $run['packaging'] ?? []]] as [$kind, $lines]) {
                foreach ($lines as $k => $l) {
                    $wh = $l['warehouse'] ?? $run['warehouse'];
                    $m = $this->stock->issueToProduction($l['item'], (float) $l['qty'], $wh, $src("{$kind}{$k}") + ['lot_id' => $l['lot_id'] ?? null]);
                    $value = abs((float) $m->value);
                    $material += $value;
                    ProductionOrderComponent::create(['company_id' => $order->company_id, 'order_id' => $order->id, 'stock_item_id' => $l['item']->id,
                        'line_kind' => $kind, 'required_qty' => $l['qty'], 'uom' => $l['item']->uom, 'issued_qty' => $l['qty'], 'returned_qty' => 0,
                        'est_unit_cost' => (float) $l['qty'] > 0 ? round($value / (float) $l['qty'], 4) : 0, 'issued_value' => round($value, 2)]);
                }
            }

            // Outputs out of it at their share of the cost by quantity; the last takes the rounding.
            $left = round($material, 2);
            foreach (array_values($outputs) as $k => $o) {
                $qty = (float) $o['qty'];
                $value = $k === count($outputs) - 1 ? $left : round($material * $qty / $outQty, 2);
                $left = round($left - $value, 2);
                $wh = $o['warehouse'] ?? $run['warehouse'];
                $lot = $this->stock->lot($o['item'], $o['lot_number'], ['kind' => 'LOT', 'origin' => 'PRODUCTION', 'manufactured_on' => $on,
                    'expires_on' => $o['expires_on'] ?? null, 'source_type' => PO::SOURCE_TYPE, 'source_id' => $order->id]);
                $status = ! $passed || ! empty($o['quarantine']) ? FinStockPosition::QUARANTINE : FinStockPosition::AVAILABLE;
                $m = $this->stock->receiveFromProduction($o['item'], $qty, $value / $qty, $wh, $src("OUT{$k}") + [
                    'lot_id' => $lot->id, 'bin_id' => $o['bin_id'] ?? null, 'status' => $status]);
                ProductionOrderOutput::create(['company_id' => $order->company_id, 'order_id' => $order->id, 'stock_item_id' => $o['item']->id,
                    'warehouse_id' => $wh->id, 'lot_id' => $lot->id, 'movement_id' => $m->id, 'grade' => $o['grade'] ?? null, 'qty' => $qty,
                    'unit_cost' => round($value / $qty, 4), 'value' => $value, 'stock_status' => $status]);
            }

            $order->update(['status' => PO::CLOSED, 'qty_completed' => $outQty, 'qty_scrapped' => (float) ($run['loss_qty'] ?? 0),
                'act_material_cost' => round($material, 2), 'act_labour_cost' => (float) ($run['labour_cost'] ?? 0),
                'completed_at' => now(), 'closed_by' => $actor?->id, 'closed_at' => now()]);
            $this->orders->event($order, 'RUN_RECORDED', null, PO::CLOSED, $actor, $run['loss_reason'] ?? null, array_filter([
                'description' => $run['description'] ?? null, 'loss_qty' => (float) ($run['loss_qty'] ?? 0), 'labour_minutes' => $run['labour_minutes'] ?? null,
                'quality_passed' => $passed, 'quality_checks' => $run['quality_checks'] ?? null,
            ], fn ($v) => $v !== null));

            return $order->fresh(['outputs', 'components']);
        });
    }

    /** The order names a product; a run's is its first output, registered as a finished item if it is not one yet. */
    private function itemFor(FinStockItem $stock, ?User $actor): Item
    {
        return Item::withoutGlobalScopes()->firstOrCreate(['company_id' => $stock->company_id, 'stock_item_id' => $stock->id],
            ['kind' => 'FINISHED', 'is_lot_tracked' => true, 'is_active' => true, 'created_by' => $actor?->id]);
    }
}
