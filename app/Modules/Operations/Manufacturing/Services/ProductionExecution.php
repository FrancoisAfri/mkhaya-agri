<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * What happens to a released order on the floor: material in, operations done, output out
 * (MFG-006/MFG-007).
 *
 * Two rules live here because this is where they bite:
 *
 *  - The routing engine (MFG-003): "prevent the next mandatory operation from being completed until
 *    required preceding operations and quality gates have passed, unless an authorised, audited
 *    exception applies." Completing out of order needs mfg.order.override AND a reason, and is
 *    recorded as an exception on the order.
 *  - Operators record activity; they do not thereby change BOMs, costs or settings (MFG-007). Nothing
 *    here edits a recipe, a routing, a rate or a policy -- it only records against the order.
 */
class ProductionExecution
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly ProductionOrders $orders,
        private readonly ProductionOrderLifecycle $lifecycle,
    ) {}

    // ── Material ─────────────────────────────────────────────────────────────────────────────

    /**
     * Issue material to the order. With no lot named, picks first-expiring first from usable stock
     * and posts one movement per lot -- so traceability (MFG-010) knows exactly which lots went in.
     *
     * @return list<FinStockMovement>
     */
    public function issue(PO $order, ProductionOrderComponent $component, float $qty, ?User $actor, array $options = []): array
    {
        $this->assertActive($order);
        $this->assertBelongs($order, $component);

        if (! $component->isConsumed()) {
            throw new DomainException('A by-product comes out of the process; it is never issued into it.');
        }
        if ($qty <= 0) {
            throw new DomainException('Issue a quantity greater than zero.');
        }

        /*
         * A retry first. A scanner that resends after a timeout must get the movements it already
         * made -- checked before the over-issue rule, which would otherwise count the retry as a
         * second issue and refuse it.
         */
        if (! empty($options['idempotency_key'])) {
            $previous = FinStockMovement::withoutGlobalScopes()
                ->where('company_id', $order->company_id)
                ->where('idempotency_key', 'like', $options['idempotency_key'].':%')
                ->orderBy('id')->get();

            if ($previous->isNotEmpty()) {
                return $previous->all();
            }
        }

        $policy = FactoryPolicy::for($order->factory_id, $order->company_id);
        $over = round($component->netIssued() + $qty - (float) $component->required_qty, 6);

        if ($over > 0.0001 && ! $policy->allow_over_issue) {
            if (empty($options['override_reason']) || ! $actor?->can(RoleCatalog::OVERRIDE_CONTROLS)) {
                throw new DomainException(sprintf(
                    'That issues %s more than the order needs. Over-issue is not allowed at this factory without an authorised override and a reason.',
                    $this->num($over)));
            }
        }

        $item = FinStockItem::withoutGlobalScopes()->findOrFail($component->stock_item_id);
        $warehouse = $order->componentWarehouse()->first() ?? $order->warehouse()->first();
        $key = $options['idempotency_key'] ?? null;
        // MFG-021: only under an approved accounting treatment, and into an open period.
        $this->accounting()->assertAllowed($order->company_id, 'MATERIAL_ISSUE');
        $this->accounting()->assertPeriodOpen($order->company_id, null, $order);

        $picks = isset($options['lot_id']) || isset($options['bin_id'])
            ? [['lot_id' => $options['lot_id'] ?? null, 'bin_id' => $options['bin_id'] ?? null, 'qty' => $qty]]
            : $this->stock->pick($item, $warehouse->id, $qty, [PO::SOURCE_TYPE, $order->id]);

        return DB::transaction(function () use ($order, $component, $item, $warehouse, $picks, $key, $actor, $over, $options) {
            $movements = [];

            foreach ($picks as $i => $pick) {
                $movement = $this->stock->issueToProduction($item, $pick['qty'], $warehouse, [
                    'source_type' => PO::SOURCE_TYPE, 'source_id' => $order->id,
                    'lot_id' => $pick['lot_id'], 'bin_id' => $pick['bin_id'],
                    'reference' => $order->number,
                    'idempotency_key' => $key ? "{$key}:{$i}" : null,
                ]);

                // A retried request returns the movement it already made; count it once.
                if ($movement->wasRecentlyCreated) {
                    $component->issued_qty = round((float) $component->issued_qty + $pick['qty'], 6);
                    $component->issued_value = round((float) $component->issued_value + abs((float) $movement->value), 2);
                }
                $this->accounting()->recordMovement($movement, $order, $actor);

                $movements[] = $movement;
            }

            $component->save();
            $this->refreshMaterialCost($order);

            if ($over > 0.0001 && ! empty($options['override_reason'])) {
                $this->orders->event($order, 'OVER_ISSUED', $order->status, $order->status, $actor,
                    $options['override_reason'], ['item' => $item->code, 'over' => $over], true);
            }

            return $movements;
        });
    }

    /** Unused material back into stock, at the average cost it was issued at. */
    public function returnMaterial(PO $order, ProductionOrderComponent $component, float $qty, ?User $actor, array $options = []): FinStockMovement
    {
        $this->assertActive($order);
        $this->assertBelongs($order, $component);

        if ($qty <= 0 || $qty > $component->netIssued() + 0.0001) {
            throw new DomainException(sprintf('Only %s of that material is with the order to return.', $this->num($component->netIssued())));
        }

        $item = FinStockItem::withoutGlobalScopes()->findOrFail($component->stock_item_id);
        $warehouse = $order->componentWarehouse()->first() ?? $order->warehouse()->first();
        $unit = (float) $component->issued_qty > 0 ? (float) $component->issued_value / (float) $component->issued_qty : 0.0;
        $this->accounting()->assertAllowed($order->company_id, 'MATERIAL_RETURN');
        $this->accounting()->assertPeriodOpen($order->company_id, null, $order);

        return DB::transaction(function () use ($order, $component, $item, $warehouse, $qty, $unit, $options, $actor) {
            $movement = $this->stock->returnFromProduction($item, $qty, round($unit, 4), $warehouse, [
                'source_type' => PO::SOURCE_TYPE, 'source_id' => $order->id,
                'lot_id' => $options['lot_id'] ?? null, 'bin_id' => $options['bin_id'] ?? null,
                'reference' => $order->number, 'idempotency_key' => $options['idempotency_key'] ?? null,
            ]);

            if ($movement->wasRecentlyCreated) {
                $component->returned_qty = round((float) $component->returned_qty + $qty, 6);
                $component->save();
                $this->refreshMaterialCost($order);
            }
            $this->accounting()->recordMovement($movement, $order, $actor);

            return $movement;
        });
    }

    // ── Operations ───────────────────────────────────────────────────────────────────────────

    /** Start an operation. Starting the first one starts the order, recording who and when. */
    public function startOperation(PO $order, Op $op, ?User $actor): Op
    {
        $this->assertActive($order);
        $this->assertBelongs($order, $op);
        $this->assertNoQualityHold($order);

        if (! in_array($op->status, [Op::READY, Op::PENDING, Op::PAUSED], true)) {
            throw new DomainException("Operation {$op->sequence} is {$op->status}.");
        }

        if ($order->status === PO::RELEASED) {
            $this->lifecycle->start($order, $actor);
        }

        $op->update(['status' => Op::IN_PROGRESS, 'started_at' => $op->started_at ?? now()]);

        return $op;
    }

    public function pauseOperation(PO $order, Op $op): Op
    {
        $this->assertBelongs($order, $op);

        if ($op->status !== Op::IN_PROGRESS) {
            throw new DomainException("Operation {$op->sequence} is not running.");
        }

        $op->update(['status' => Op::PAUSED]);

        return $op;
    }

    /**
     * Complete an operation, with quantities and time. Refused while a mandatory earlier operation
     * or its gate is open -- unless an override holder gives a reason, which is recorded.
     */
    public function completeOperation(PO $order, Op $op, array $result, ?User $actor, ?string $overrideReason = null): Op
    {
        $this->assertActive($order);
        $this->assertBelongs($order, $op);
        $this->assertNoQualityHold($order);

        if ($op->status === Op::COMPLETED) {
            throw new DomainException("Operation {$op->sequence} is already complete.");
        }

        $blocking = $this->blockedBy($order, $op);

        if ($blocking !== []) {
            $message = 'Earlier operations are not cleared: '.implode(', ', $blocking).'.';

            if (! $actor?->can(RoleCatalog::OVERRIDE_CONTROLS) || blank($overrideReason)) {
                throw new DomainException($message.' Completing out of order needs an authorised override and a reason.');
            }
        }

        return DB::transaction(function () use ($order, $op, $result, $actor, $blocking, $overrideReason) {
            if ($order->status === PO::RELEASED) {
                $this->lifecycle->start($order, $actor);
            }

            $op->update([
                'status' => Op::COMPLETED,
                'qty_completed' => (float) ($result['completed'] ?? 0),
                'qty_rejected' => (float) ($result['rejected'] ?? 0),
                'qty_scrapped' => (float) ($result['scrapped'] ?? 0),
                'qty_reworked' => (float) ($result['reworked'] ?? 0),
                'actual_machine_minutes' => (float) ($result['machine_minutes'] ?? 0),
                'actual_labour_minutes' => (float) ($result['labour_minutes'] ?? 0),
                'started_at' => $op->started_at ?? now(),
                'completed_at' => now(),
                'completed_by' => $actor?->id,
            ]);

            if ($blocking !== []) {
                $this->orders->event($order, 'OUT_OF_SEQUENCE', $order->status, $order->status, $actor, $overrideReason,
                    ['operation' => $op->sequence, 'skipped_over' => $blocking], true);
            }

            $this->refreshActualCosts($order);
            $this->openNext($order);

            // An inspection point with a quality plan opens the inspections the plan asks for.
            $quality = app(QualityControl::class);
            if ($op->is_inspection_point && $quality->applicablePlan(\App\Modules\Operations\Manufacturing\Models\InspectionPlan::IN_PROCESS,
                null, $order->item_id, null, $op->routing_operation_id)) {
                $quality->openForOperation($order, $op->refresh(), $actor);
            }

            return $op->refresh();
        });
    }

    /**
     * Pass or fail a gate. An approval gate needs its named permission; a failed gate stops the
     * operations behind it until somebody decides what happens to the work (MFG-009 disposition).
     */
    public function recordGate(PO $order, Op $op, string $result, ?User $actor, ?string $notes = null, bool $fromInspection = false): Op
    {
        $this->assertBelongs($order, $op);

        // Where a quality plan covers the inspection point, its inspections decide the gate; a
        // hand-recorded pass would let the operation through without the measurements.
        if (! $fromInspection && \App\Modules\Operations\Manufacturing\Models\Inspection::query()->where('order_operation_id', $op->id)->exists()) {
            throw new DomainException("Operation {$op->sequence}'s gate is decided by its inspections. Record them under Quality.");
        }

        if (! $op->isGate()) {
            throw new DomainException("Operation {$op->sequence} is not a gate.");
        }
        if ($op->status !== Op::COMPLETED) {
            throw new DomainException('Finish the operation before recording its gate.');
        }
        if (! in_array($result, ['PASSED', 'FAILED'], true)) {
            throw new DomainException('A gate is passed or failed.');
        }
        if ($op->is_approval_gate && $op->gate_permission && ! $actor?->can($op->gate_permission)) {
            throw new DomainException("Passing this gate needs {$op->gate_permission}.");
        }

        $op->update(['gate_status' => $result, 'gate_passed_by' => $actor?->id, 'gate_passed_at' => now()]);

        $this->orders->event($order, $result === 'PASSED' ? 'GATE_PASSED' : 'GATE_FAILED', $order->status, $order->status,
            $actor, $notes, ['operation' => $op->sequence]);

        if ($result === 'PASSED') {
            $this->openNext($order);
        }

        return $op->refresh();
    }

    // ── Output ───────────────────────────────────────────────────────────────────────────────

    /**
     * Receive good output into the order's warehouse, at standard material cost (MFG-008 control 3).
     * Into quarantine when the factory requires inspection before putaway, so it cannot be issued or
     * shipped until quality releases it. A lot-tracked product must name its lot; the order number is
     * offered as the default. Output made by a subcontractor ('inspect' with its 'supplier_id') is
     * always quarantined and inspected, whatever the factory's own rule.
     */
    public function receiveOutput(PO $order, float $qty, ?User $actor, array $options = []): FinStockMovement
    {
        $this->assertActive($order);
        $this->assertNoQualityHold($order);

        if ($qty <= 0) {
            throw new DomainException('Receive a quantity greater than zero.');
        }

        $order->loadMissing('item');
        $item = FinStockItem::withoutGlobalScopes()->findOrFail($order->item->stock_item_id);
        $policy = FactoryPolicy::for($order->factory_id, $order->company_id);

        $lotId = $options['lot_id'] ?? null;
        if (! $lotId && ($order->item->is_lot_tracked || ! empty($options['lot_number']))) {
            $lotId = $this->stock->lot($item, $options['lot_number'] ?? $order->number, [
                'origin' => 'PRODUCTION', 'manufactured_on' => now()->toDateString(),
                'expires_on' => $order->item->shelf_life_days ? now()->addDays($order->item->shelf_life_days)->toDateString() : null,
                'source_type' => PO::SOURCE_TYPE, 'source_id' => $order->id,
            ])->id;
        }

        $quarantine = $policy->require_qc_before_putaway || ! empty($options['inspect']);
        $this->accounting()->assertAllowed($order->company_id, 'OUTPUT_RECEIPT');
        $this->accounting()->assertPeriodOpen($order->company_id, null, $order);

        return DB::transaction(function () use ($order, $item, $qty, $lotId, $quarantine, $options, $actor) {
            $movement = $this->stock->receiveFromProduction($item, $qty, $this->orders->standardMaterialUnitCost($order),
                $order->warehouse()->first(), [
                    'source_type' => PO::SOURCE_TYPE, 'source_id' => $order->id, 'lot_id' => $lotId,
                    'bin_id' => $options['bin_id'] ?? null, 'reference' => $order->number,
                    'status' => $quarantine ? FinStockPosition::QUARANTINE : FinStockPosition::AVAILABLE,
                    'idempotency_key' => $options['idempotency_key'] ?? null,
                ]);

            $this->accounting()->recordMovement($movement, $order, $actor);

            if ($movement->wasRecentlyCreated) {
                PO::query()->whereKey($order->id)->increment('qty_completed', $qty);
                $order->refresh();
                $this->orders->event($order, 'OUTPUT_RECEIVED', $order->status, $order->status, $actor, null,
                    ['qty' => $qty, 'lot_id' => $lotId, 'quarantined' => $quarantine]);

                // Quarantined output is inspected before anybody can use or dispatch it; usable output
                // made for a customer is held for that customer straight away (MFG-017).
                if ($quarantine) {
                    app(QualityControl::class)->openForOutput($order, $qty, $lotId, $actor, $options['supplier_id'] ?? null);
                } else {
                    app(CustomerOrders::class)->allocateOutput($order);
                }
            }

            return $movement;
        });
    }

    /** Count output lost to scrap or sent for rework. The material is already in WIP; nothing moves. */
    public function recordLoss(PO $order, float $scrapped, float $reworked, ?User $actor, ?string $reason = null): PO
    {
        $this->assertActive($order);

        if ($scrapped < 0 || $reworked < 0 || ($scrapped + $reworked) <= 0) {
            throw new DomainException('Record a scrapped or reworked quantity greater than zero.');
        }

        return DB::transaction(function () use ($order, $scrapped, $reworked, $actor, $reason) {
            PO::query()->whereKey($order->id)->update([
                'qty_scrapped' => DB::raw('qty_scrapped + '.(float) $scrapped),
                'qty_reworked' => DB::raw('qty_reworked + '.(float) $reworked),
            ]);
            $order->refresh();
            $this->orders->event($order, 'LOSS_RECORDED', $order->status, $order->status, $actor, $reason,
                ['scrapped' => $scrapped, 'reworked' => $reworked]);

            return $order;
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    /** @return list<string> mandatory earlier operations, or their gates, that are not cleared */
    public function blockedBy(PO $order, Op $op): array
    {
        return $order->operations()->get()
            ->filter(fn (Op $p) => $p->sequence < $op->sequence && $p->is_mandatory
                && ($op->parallel_group === null || $p->parallel_group !== $op->parallel_group)
                && ! $p->isCleared())
            ->map(fn (Op $p) => $p->status === Op::COMPLETED && $p->isGate()
                ? "{$p->sequence} {$p->name} (gate ".strtolower($p->gate_status ?? 'not recorded').')'
                : "{$p->sequence} {$p->name}")
            ->values()->all();
    }

    private function openNext(PO $order): void
    {
        foreach ($order->operations()->where('status', Op::PENDING)->get() as $op) {
            if ($this->blockedBy($order, $op) === []) {
                $op->update(['status' => Op::READY]);
            }
        }
    }

    private function refreshMaterialCost(PO $order): void
    {
        $value = 0.0;
        foreach ($order->components()->get() as $c) {
            $unit = (float) $c->issued_qty > 0 ? (float) $c->issued_value / (float) $c->issued_qty : 0;
            $value += (float) $c->issued_value - (float) $c->returned_qty * $unit;
        }

        PO::query()->whereKey($order->id)->update(['act_material_cost' => round($value, 2)]);
        $order->refresh();
    }

    /** Labour, machine and overhead actuals from what the floor recorded, at each centre's rates. */
    private function refreshActualCosts(PO $order): void
    {
        $labour = 0.0;
        $machine = 0.0;

        foreach ($order->operations()->with('workCentre')->get() as $op) {
            $wc = $op->workCentre;
            $machine += (float) $op->actual_machine_minutes / 60 * (float) ($wc?->cost_per_hour ?? 0);
            $labour += (float) $op->actual_labour_minutes / 60 * (float) ($wc?->labour_rate_per_hour ?? 0);
        }

        $policy = FactoryPolicy::for($order->factory_id, $order->company_id);
        $overhead = $policy->overhead_percent !== null ? ($labour + $machine) * (float) $policy->overhead_percent / 100 : 0.0;

        PO::query()->whereKey($order->id)->update([
            'act_labour_cost' => round($labour, 2), 'act_machine_cost' => round($machine, 2), 'act_overhead_cost' => round($overhead, 2),
        ]);
        $order->refresh();
    }

    private function assertActive(PO $order): void
    {
        $order->refresh();

        if ($order->status === PO::ON_HOLD) {
            throw new DomainException("Order {$order->number} is on hold.");
        }
        if (! $order->isActive()) {
            throw new DomainException("Order {$order->number} is ".strtolower(str_replace('_', ' ', $order->status)).'; nothing can be recorded against it.');
        }
    }

    /** A failed in-process inspection whose plan stops production has to be decided first. */
    private function assertNoQualityHold(PO $order): void
    {
        $blocking = app(QualityControl::class)->blocking($order);

        if ($blocking !== []) {
            throw new DomainException('Production is stopped until quality decides on failed inspection '.implode(', ', $blocking).'.');
        }
    }

    private function assertBelongs(PO $order, $line): void
    {
        if ((int) $line->order_id !== (int) $order->id) {
            throw new DomainException('That line belongs to another order.');
        }
    }

    private function accounting(): ManufacturingAccounting
    {
        return app(ManufacturingAccounting::class);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
