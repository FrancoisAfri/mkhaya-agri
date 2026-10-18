<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Operations\Manufacturing\Models\OrderVariance;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Operations\Manufacturing\Models\StandardCost;
use Illuminate\Support\Facades\DB;

/**
 * Standard against actual for one order (MFG-011): what it cost, what it should have cost for the
 * good output it made, and why the two differ -- each variance with its formula, its inputs and
 * the records behind them, and a flag where the data cannot support the figure.
 *
 * Sign convention: positive is adverse (it cost more than standard).
 *
 * The additive variances -- material price and usage, labour rate and efficiency, machine,
 * overhead, subcontract -- add up to the total. Yield and scrap/rework are shown as well, as
 * explanations of the usage and efficiency figures; they are not added again.
 */
class CostAnalysis
{
    public const ESTIMATED = 'ESTIMATED';
    public const PROVISIONAL = 'PROVISIONAL';
    public const FINAL = 'FINAL';

    /**
     * Every variance of an order, calculated now.
     *
     * @return array{standard: ?array, actual: array, variances: list<array>, status: string, outstanding: list<string>}
     */
    public function analyse(PO $order): array
    {
        $order->loadMissing('item');
        $std = $order->standard_cost_id
            ? StandardCost::query()->find($order->standard_cost_id)
            : StandardCost::query()->where('item_id', $order->item_id)->where('status', StandardCost::APPROVED)->latest('version')->first();

        $good = (float) $order->qty_completed;
        $components = $order->components()->with('stockItem:id,code')->get();
        $actual = $this->actuals($order, $components);
        $status = $this->status($order, $std, $actual);

        if (! $std || (float) $std->expected_output <= 0) {
            return ['standard' => null, 'actual' => $actual, 'status' => $status['status'], 'outstanding' => $status['outstanding'],
                'variances' => [['kind' => 'TOTAL', 'amount' => null, 'data_quality' => 'INSUFFICIENT', 'additive' => false,
                    'method' => 'Actual cost less standard cost of good output.', 'inputs' => [],
                    'notes' => 'There is no approved standard cost for this product, so nothing can be measured against it.']]];
        }

        $scale = $good / (float) $std->expected_output;   // standard quantities for the output actually made
        $lines = $std->lines()->get();
        $variances = [];

        // ── Material price and usage, per material ─────────────────────────────────────────
        $price = $usage = 0.0;
        $priceInputs = $usageInputs = [];
        $unstandard = [];
        foreach ($components->filter->isConsumed() as $c) {
            $line = $lines->where('category', 'MATERIAL')->firstWhere('stock_item_id', $c->stock_item_id);
            $qty = $c->netIssued();
            $value = $this->netValue($c);
            $actualRate = $qty > 0 ? $value / $qty : 0.0;
            $stdRate = $line ? (float) $line->rate : 0.0;
            $stdQty = $line ? (float) $line->quantity * $scale : 0.0;

            if (! $line) {
                // A substitute or added material the standard never had: all of it is usage.
                $unstandard[] = $c->stockItem?->code;
                $usage += $value;
                $usageInputs[] = ['item' => $c->stockItem?->code, 'actual_qty' => $qty, 'standard_qty' => 0, 'standard_rate' => null, 'variance' => round($value, 2)];

                continue;
            }

            $p = ($actualRate - $stdRate) * $qty;
            $u = ($qty - $stdQty) * $stdRate;
            $price += $p;
            $usage += $u;
            $priceInputs[] = ['item' => $c->stockItem?->code, 'actual_qty' => round($qty, 4), 'actual_rate' => round($actualRate, 4), 'standard_rate' => round($stdRate, 4), 'variance' => round($p, 2)];
            $usageInputs[] = ['item' => $c->stockItem?->code, 'actual_qty' => round($qty, 4), 'standard_qty' => round($stdQty, 4), 'standard_rate' => round($stdRate, 4), 'variance' => round($u, 2)];
        }
        $sources = ['movements' => FinStockMovement::withoutGlobalScopes()->where('source_type', PO::SOURCE_TYPE)->where('source_id', $order->id)
            ->whereIn('type', ['mfg_issue', 'mfg_return'])->pluck('uuid')->all()];

        $variances[] = $this->v('MATERIAL_PRICE', $price, '(actual unit cost − standard price) × actual quantity, per material',
            ['lines' => $priceInputs] + $sources, 'COMPLETE');
        $variances[] = $this->v('MATERIAL_USAGE', $usage, '(actual quantity − standard quantity for the good output) × standard price, per material',
            ['lines' => $usageInputs, 'good_output' => $good, 'standard_output' => (float) $std->expected_output] + $sources,
            $unstandard ? 'PARTIAL' : 'COMPLETE', $unstandard ? 'Not in the standard, counted entirely as usage: '.implode(', ', $unstandard).'.' : null);

        // ── Labour ─────────────────────────────────────────────────────────────────────────
        $stdLabourHours = (float) $std->labour_minutes / 60 * $scale;
        $stdLabourRate = (float) $std->labour_minutes > 0 ? (float) $std->labour_cost / ((float) $std->labour_minutes / 60) : 0.0;
        $actLabourHours = $actual['labour_minutes'] / 60;
        $actLabourRate = $actLabourHours > 0 ? $actual['labour'] / $actLabourHours : 0.0;
        $opIds = $order->operations()->pluck('uuid')->all();

        $variances[] = $this->v('LABOUR_RATE', ($actLabourRate - $stdLabourRate) * $actLabourHours,
            '(actual rate − standard rate) × actual labour hours',
            ['actual_hours' => round($actLabourHours, 4), 'actual_rate' => round($actLabourRate, 4), 'standard_rate' => round($stdLabourRate, 4), 'operations' => $opIds],
            'PARTIAL', 'Operator pay rates are held in payroll, not here: the actual rate is each work centre\'s labour rate, so this shows rate differences between centres only.');
        $variances[] = $this->v('LABOUR_EFFICIENCY', ($actLabourHours - $stdLabourHours) * $stdLabourRate,
            '(actual labour hours − standard hours for the good output) × standard rate',
            ['actual_hours' => round($actLabourHours, 4), 'standard_hours' => round($stdLabourHours, 4), 'standard_rate' => round($stdLabourRate, 4), 'operations' => $opIds],
            $actual['labour_minutes'] > 0 ? 'COMPLETE' : 'INSUFFICIENT', $actual['labour_minutes'] > 0 ? null : 'No labour time has been recorded on this order.');

        // ── Machine ────────────────────────────────────────────────────────────────────────
        $stdMachineHours = (float) $std->machine_minutes / 60 * $scale;
        $stdMachineRate = (float) $std->machine_minutes > 0 ? (float) $std->machine_cost / ((float) $std->machine_minutes / 60) : 0.0;
        $actMachineHours = $actual['machine_minutes'] / 60;
        $utilisation = ($actMachineHours - $stdMachineHours) * $stdMachineRate;
        $rate = $actual['machine'] - $actMachineHours * $stdMachineRate;
        $variances[] = $this->v('MACHINE', $utilisation + $rate,
            'utilisation: (actual machine hours − standard hours) × standard rate; cost: actual machine cost − actual hours × standard rate',
            ['actual_hours' => round($actMachineHours, 4), 'standard_hours' => round($stdMachineHours, 4), 'standard_rate' => round($stdMachineRate, 4),
                'utilisation' => round($utilisation, 2), 'cost' => round($rate, 2), 'operations' => $opIds],
            $actual['machine_minutes'] > 0 ? 'COMPLETE' : 'INSUFFICIENT', $actual['machine_minutes'] > 0 ? null : 'No machine time has been recorded on this order.');

        // ── Overhead and subcontract ───────────────────────────────────────────────────────
        $variances[] = $this->v('OVERHEAD', $actual['overhead'] - (float) $std->overhead_cost * $scale,
            'overhead applied on actual labour and machine − standard overhead for the good output',
            ['applied' => round($actual['overhead'], 2), 'standard' => round((float) $std->overhead_cost * $scale, 2)], 'COMPLETE');
        if ((float) $std->subcontract_cost > 0 || $actual['subcontract'] > 0) {
            $variances[] = $this->v('SUBCONTRACT', $actual['subcontract'] - (float) $std->subcontract_cost * $scale,
                'subcontract cost − standard subcontract cost for the good output',
                ['actual' => round($actual['subcontract'], 2), 'standard' => round((float) $std->subcontract_cost * $scale, 2)],
                'PARTIAL', 'Subcontract cost is at the routing\'s agreed price until supplier invoices are linked to the order.');
        }

        // ── Explanations, not added again ──────────────────────────────────────────────────
        $expected = (float) $order->planned_qty * ((float) $std->expected_output / (float) $std->costing_qty);
        $variances[] = $this->v('YIELD', ($expected - $good) * (float) $std->unit_cost,
            '(output the standard expects from the planned quantity − good output) × standard unit cost',
            ['planned' => (float) $order->planned_qty, 'expected_output' => round($expected, 4), 'good_output' => $good,
                'standard_unit_cost' => round((float) $std->unit_cost, 4)],
            in_array($order->status, [PO::COMPLETED, PO::CLOSED], true) ? 'COMPLETE' : 'PARTIAL',
            'Explains part of material usage and efficiency; not added to the total. '.(in_array($order->status, [PO::COMPLETED, PO::CLOSED], true) ? '' : 'The order is not finished, so output is not final.'),
            false);
        $variances[] = $this->v('SCRAP_REWORK', $actual['scrap_cost'] + $actual['rework_cost'],
            'scrapped quantity × standard unit cost + reworked quantity × standard labour, machine and overhead per unit',
            ['scrapped' => (float) $order->qty_scrapped, 'reworked' => (float) $order->qty_reworked,
                'scrap_cost' => round($actual['scrap_cost'], 2), 'rework_cost' => round($actual['rework_cost'], 2)],
            'COMPLETE', 'The cost of scrap and rework, already inside the variances above; not added to the total.', false);

        // ── Total ──────────────────────────────────────────────────────────────────────────
        $standardForOutput = (float) $std->total_cost * $scale;
        $total = $actual['total'] - $standardForOutput;
        $explained = collect($variances)->where('additive', true)->sum('amount');
        $variances[] = $this->v('TOTAL', $total, 'actual cost − standard cost of the good output',
            ['actual' => round($actual['total'], 2), 'standard_for_output' => round($standardForOutput, 2),
                'explained_by_variances' => round($explained, 2), 'unexplained' => round($total - $explained, 2)],
            collect($variances)->contains('data_quality', 'INSUFFICIENT') ? 'PARTIAL' : 'COMPLETE', null, false);

        return [
            'standard' => ['id' => $std->uuid, 'version' => $std->version, 'unit_cost' => (float) $std->unit_cost, 'for_output' => round($standardForOutput, 2)],
            'actual' => $actual, 'variances' => $variances, 'status' => $status['status'], 'outstanding' => $status['outstanding'],
        ];
    }

    /** Store the variances of an order as they stand -- called when it closes, so they are frozen with it. */
    public function record(PO $order): void
    {
        $analysis = $this->analyse($order);

        DB::transaction(function () use ($order, $analysis) {
            OrderVariance::query()->where('order_id', $order->id)->delete();
            foreach ($analysis['variances'] as $v) {
                if ($v['amount'] === null) {
                    continue;
                }
                OrderVariance::create([
                    'company_id' => $order->company_id, 'order_id' => $order->id,
                    'standard_cost_id' => $order->standard_cost_id, 'kind' => $v['kind'], 'amount' => round($v['amount'], 4),
                    'method' => $v['method'], 'inputs' => $v['inputs'], 'data_quality' => $v['data_quality'], 'notes' => $v['notes'],
                    'calculated_at' => now(),
                ]);
            }
        });
    }

    /** Actual cost of an order, by element, as recorded. */
    public function actuals(PO $order, $components = null): array
    {
        $components ??= $order->components()->get();
        $material = $components->filter->isConsumed()->sum(fn ($c) => $this->netValue($c));
        $ops = $order->operations()->get();
        $std = $order->standard_cost_id ? StandardCost::query()->find($order->standard_cost_id) : null;
        $unit = $std ? (float) $std->unit_cost : 0.0;
        $conversionPerUnit = $std && (float) $std->expected_output > 0
            ? ((float) $std->labour_cost + (float) $std->machine_cost + (float) $std->overhead_cost) / (float) $std->expected_output : 0.0;

        $labour = (float) $order->act_labour_cost;
        $machine = (float) $order->act_machine_cost;
        $overhead = (float) $order->act_overhead_cost;
        // What subcontractors returned, at the agreed price (MFG-017); with no subcontract orders on
        // record, the standard price per unit of good output stands in.
        $subcontract = \App\Modules\Operations\Manufacturing\Models\SubcontractOrder::query()->where('production_order_id', $order->id)->exists()
            ? (float) $order->act_subcontract_cost
            : ($std && (float) $std->expected_output > 0 ? (float) $std->subcontract_cost / (float) $std->expected_output * (float) $order->qty_completed : 0.0);

        return [
            'material' => round($material, 2), 'labour' => round($labour, 2), 'machine' => round($machine, 2),
            'overhead' => round($overhead, 2), 'subcontract' => round($subcontract, 2),
            'total' => round($material + $labour + $machine + $overhead + $subcontract, 2),
            'labour_minutes' => (float) $ops->sum('actual_labour_minutes'), 'machine_minutes' => (float) $ops->sum('actual_machine_minutes'),
            'scrap_cost' => round((float) $order->qty_scrapped * $unit, 2),
            'rework_cost' => round((float) $order->qty_reworked * $conversionPerUnit, 2),
            'good_output' => (float) $order->qty_completed,
            'unit_cost' => (float) $order->qty_completed > 0 ? round(($material + $labour + $machine + $overhead + $subcontract) / (float) $order->qty_completed, 4) : null,
        ];
    }

    /**
     * Estimated, provisional or final -- and what stands in the way of final. A cost is final only
     * when the order is closed, its work in progress settled, it was measured against an approved
     * standard and nothing it depends on is still an estimate.
     *
     * @return array{status: string, outstanding: list<string>}
     */
    public function status(PO $order, ?StandardCost $std, array $actual): array
    {
        if (in_array($order->status, [PO::DRAFT, PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::REJECTED, PO::CANCELLED], true)
            && $actual['material'] == 0 && $actual['labour'] == 0) {
            return ['status' => self::ESTIMATED, 'outstanding' => ['Production has not started: these are estimates.']];
        }

        $outstanding = [];
        if ($order->status !== PO::CLOSED) {
            $outstanding[] = 'The order is not closed.';
        } elseif ($order->close_journal_id === null && abs(app(ProductionOrderLifecycle::class)->wipBalance($order)) >= 0.01) {
            $outstanding[] = 'Work in progress has not been settled to the ledger.';
        }
        if (! $std) {
            $outstanding[] = 'No approved standard cost to measure against.';
        }
        if ($actual['labour_minutes'] <= 0 && $order->operations()->exists()) {
            $outstanding[] = 'No labour time recorded.';
        }
        if ($std && (float) $std->subcontract_cost > 0) {
            $outstanding[] = 'Subcontract cost is still at the agreed price, not the supplier\'s invoice.';
        }

        return ['status' => $outstanding === [] ? self::FINAL : self::PROVISIONAL, 'outstanding' => $outstanding];
    }

    /** What a component's material actually cost: issued value less what came back at its issue price. */
    private function netValue(ProductionOrderComponent $c): float
    {
        $issuedQty = (float) $c->issued_qty;
        $unit = $issuedQty > 0 ? (float) $c->issued_value / $issuedQty : 0.0;

        return (float) $c->issued_value - (float) $c->returned_qty * $unit;
    }

    private function v(string $kind, float $amount, string $method, array $inputs, string $quality, ?string $notes = null, bool $additive = true): array
    {
        return ['kind' => $kind, 'amount' => round($amount, 2), 'method' => $method, 'inputs' => $inputs,
            'data_quality' => $quality, 'notes' => $notes ? trim($notes) : null, 'additive' => $additive];
    }
}
