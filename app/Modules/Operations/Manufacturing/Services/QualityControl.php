<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\InspectionCharacteristic;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Models\InspectionResult;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Inspections and what follows from them (MFG-009).
 *
 * Three ways in, one way out. Stock received for inspection lands in quarantine; output from an
 * order that inspects before putaway lands in quarantine; an inspection point on the routing opens
 * an in-process inspection when its operation completes. Each becomes an inspection that is
 * recorded (and judged against the plan's limits automatically) and then disposed of by somebody
 * authorised -- and only the disposition moves stock out of quarantine, writes it off, or lets
 * production carry on past a failure.
 */
class QualityControl
{
    public function __construct(
        private readonly StockLedger $stock,
        private readonly NumberSequences $numbers,
        private readonly Nonconformances $ncrs,
    ) {}

    // ── Plans ────────────────────────────────────────────────────────────────────────────────

    /**
     * The active plan for a stage, most specific first: an operation's own plan beats the
     * product's, a supplier's beats the material's.
     */
    public function applicablePlan(string $stage, ?int $stockItemId = null, ?int $itemId = null, ?int $supplierId = null, ?int $routingOperationId = null): ?InspectionPlan
    {
        return InspectionPlan::query()
            ->where('stage', $stage)->where('is_active', true)
            ->when($stage === InspectionPlan::INCOMING,
                fn ($q) => $q->where('stock_item_id', $stockItemId)
                    ->where(fn ($s) => $s->whereNull('supplier_id')->when($supplierId, fn ($x) => $x->orWhere('supplier_id', $supplierId))),
                fn ($q) => $q->where('item_id', $itemId))
            ->when($stage === InspectionPlan::IN_PROCESS,
                fn ($q) => $q->where(fn ($s) => $s->whereNull('routing_operation_id')
                    ->when($routingOperationId, fn ($x) => $x->orWhere('routing_operation_id', $routingOperationId))))
            ->orderByRaw('routing_operation_id IS NULL')
            ->orderByRaw('supplier_id IS NULL')
            ->orderBy('id')
            ->first();
    }

    // ── Ways in ──────────────────────────────────────────────────────────────────────────────

    /**
     * Receive purchased material into quarantine and open its incoming inspection. The lot carries
     * the supplier and the supplier's own lot number, which is what traceability follows back.
     *
     * @param  array{stock_item: FinStockItem, warehouse: FinWarehouse, qty: float, unit_cost: float, lot_number: string,
     *               supplier_id?: ?int, purchase_order_id?: ?int, supplier_lot_number?: ?string, expires_on?: ?string,
     *               reference?: ?string, idempotency_key?: ?string}  $data
     */
    public function receiveForInspection(array $data, ?User $actor): Inspection
    {
        $item = $data['stock_item'];
        $qty = (float) $data['qty'];

        if ($qty <= 0) {
            throw new DomainException('Receive a quantity greater than zero.');
        }
        if (blank($data['lot_number'] ?? null)) {
            throw new DomainException('Material received for inspection needs a lot number, so the inspection knows exactly what it is about.');
        }

        return DB::transaction(function () use ($data, $item, $qty, $actor) {
            $lot = $this->stock->lot($item, $data['lot_number'], array_filter([
                'origin' => 'RECEIPT',
                'supplier_id' => $data['supplier_id'] ?? null,
                'supplier_lot_number' => $data['supplier_lot_number'] ?? null,
                'expires_on' => $data['expires_on'] ?? null,
                'source_type' => isset($data['purchase_order_id']) ? 'PURCHASE_ORDER' : null,
                'source_id' => $data['purchase_order_id'] ?? null,
            ], fn ($v) => $v !== null));

            $this->stock->receive($item, $qty, (float) $data['unit_cost'], $data['warehouse'], [
                'lot_id' => $lot->id, 'status' => FinStockPosition::QUARANTINE,
                'reference' => $data['reference'] ?? $lot->lot_number,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            $plan = $this->applicablePlan(InspectionPlan::INCOMING, $item->id, null, $data['supplier_id'] ?? null);

            return $this->open([
                'stage' => InspectionPlan::INCOMING, 'plan' => $plan, 'stock_item_id' => $item->id, 'lot_id' => $lot->id,
                'warehouse_id' => $data['warehouse']->id, 'qty' => $qty, 'held_status' => FinStockPosition::QUARANTINE,
                'supplier_id' => $data['supplier_id'] ?? null, 'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'receipt_reference' => $data['reference'] ?? null,
            ], $item->company_id, $actor);
        });
    }

    /**
     * Open the incoming inspection for stock a warehouse receipt has already posted into a held
     * status (WHMI receiving). The receipt owns the posting -- its bin, unit, accrual account and
     * idempotency -- and this owns the inspection, so neither writes the other's records.
     *
     * @param  array{stock_item: FinStockItem, warehouse: FinWarehouse, qty: float, lot_id: ?int, held_status: string,
     *               supplier_id?: ?int, purchase_order_id?: ?int, reference?: ?string}  $data
     */
    public function openForReceipt(array $data, ?User $actor): Inspection
    {
        $item = $data['stock_item'];
        $plan = $this->applicablePlan(InspectionPlan::INCOMING, $item->id, null, $data['supplier_id'] ?? null);

        return $this->open([
            'stage' => InspectionPlan::INCOMING, 'plan' => $plan, 'stock_item_id' => $item->id, 'lot_id' => $data['lot_id'] ?? null,
            'warehouse_id' => $data['warehouse']->id, 'qty' => (float) $data['qty'], 'held_status' => $data['held_status'],
            'supplier_id' => $data['supplier_id'] ?? null, 'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'receipt_reference' => $data['reference'] ?? null,
        ], $item->company_id, $actor);
    }

    /**
     * Open an inspection the warehouse runs (WMS-082): stock checked in its bin, a customer return,
     * an order before it leaves. The warehouse holds and releases the stock itself -- in the bin it
     * is in -- so the inspection carries no held status of its own and its decision moves nothing
     * here; the warehouse acts on the decision.
     *
     * @param  array{stage: string, stock_item: FinStockItem, warehouse: FinWarehouse, qty: float, lot_id?: ?int, bin_id?: ?int,
     *               source_type: string, source_id: int, reference?: ?string, notes?: ?string}  $data
     */
    public function openInWarehouse(array $data, ?User $actor): Inspection
    {
        if (! in_array($data['stage'], [InspectionPlan::STORAGE, InspectionPlan::RETURN, InspectionPlan::DISPATCH], true)) {
            throw new DomainException('That is not a warehouse inspection stage.');
        }
        $item = $data['stock_item'];
        $plan = InspectionPlan::query()->where('stage', $data['stage'])->where('is_active', true)
            ->where(fn ($q) => $q->where('stock_item_id', $item->id)->orWhereNull('stock_item_id'))
            ->orderByRaw('stock_item_id IS NULL')->orderBy('id')->first();

        return $this->open([
            'stage' => $data['stage'], 'plan' => $plan, 'stock_item_id' => $item->id, 'lot_id' => $data['lot_id'] ?? null,
            'bin_id' => $data['bin_id'] ?? null, 'warehouse_id' => $data['warehouse']->id, 'qty' => (float) $data['qty'],
            'held_status' => null, 'source_type' => $data['source_type'], 'source_id' => $data['source_id'],
            'receipt_reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null,
        ], $item->company_id, $actor);
    }

    /**
     * Put usable stock on hold pending inspection -- a suspect lot, a customer complaint, a recall.
     * It stays where it is, in a status nothing can issue or dispatch.
     */
    public function holdForInspection(FinStockItem $item, FinWarehouse $warehouse, float $qty, ?int $lotId, string $stage, string $reason, ?User $actor): Inspection
    {
        if (blank($reason)) {
            throw new DomainException('Say why the stock is being held.');
        }

        return DB::transaction(function () use ($item, $warehouse, $qty, $lotId, $stage, $reason, $actor) {
            $this->stock->changeStatus($item, $warehouse, $qty, FinStockPosition::AVAILABLE, FinStockPosition::ON_HOLD, [
                'lot_id' => $lotId, 'reason' => $reason,
            ]);

            $mfgItem = \App\Modules\Operations\Manufacturing\Models\Item::query()->where('stock_item_id', $item->id)->first();
            $plan = $stage === InspectionPlan::INCOMING
                ? $this->applicablePlan($stage, $item->id)
                : ($mfgItem ? $this->applicablePlan($stage, null, $mfgItem->id) : null);

            return $this->open([
                'stage' => $stage, 'plan' => $plan, 'stock_item_id' => $item->id, 'lot_id' => $lotId,
                'warehouse_id' => $warehouse->id, 'qty' => $qty, 'held_status' => FinStockPosition::ON_HOLD,
                'notes' => $reason,
            ], $item->company_id, $actor);
        });
    }

    /** Output received into quarantine: its final inspection, against the product's plan if it has one. */
    public function openForOutput(PO $order, float $qty, ?int $lotId, ?User $actor, ?int $supplierId = null): Inspection
    {
        $order->loadMissing('item');

        return $this->open([
            'stage' => InspectionPlan::FINAL,
            'plan' => $this->applicablePlan(InspectionPlan::FINAL, null, $order->item_id, $supplierId),
            'stock_item_id' => $order->item->stock_item_id, 'lot_id' => $lotId, 'warehouse_id' => $order->warehouse_id,
            'qty' => $qty, 'held_status' => FinStockPosition::QUARANTINE, 'production_order_id' => $order->id,
            'supplier_id' => $supplierId,
        ], $order->company_id, $actor);
    }

    /**
     * Work back from a subcontractor (MFG-017): the operation's inspection, naming the supplier so
     * a failure raises its non-conformance against them. The operation is an inspection point, so
     * the next step waits on this. An inspection the operation's own plan already opened is reused.
     */
    public function openForSubcontractReceipt(PO $order, Op $op, float $qty, int $supplierId, ?User $actor): Inspection
    {
        $existing = Inspection::query()->where('order_operation_id', $op->id)->where('status', Inspection::OPEN)->latest('id')->first();
        if ($existing) {
            $existing->update(['supplier_id' => $supplierId]);

            return $existing->refresh();
        }
        $order->loadMissing('item');

        return $this->open([
            'stage' => InspectionPlan::IN_PROCESS,
            'plan' => $this->applicablePlan(InspectionPlan::IN_PROCESS, null, $order->item_id, $supplierId, $op->routing_operation_id),
            'stock_item_id' => $order->item->stock_item_id, 'qty' => $qty, 'production_order_id' => $order->id,
            'order_operation_id' => $op->id, 'supplier_id' => $supplierId,
            'notes' => 'Returned from subcontractor',
        ], $order->company_id, $actor);
    }

    /**
     * An inspection point's operation is complete: open the inspections its plan asks for. With an
     * interval ("every 50 units"), one inspection per interval started; otherwise one.
     *
     * @return Collection<int, Inspection>
     */
    public function openForOperation(PO $order, Op $op, ?User $actor): Collection
    {
        $order->loadMissing('item');
        $plan = $this->applicablePlan(InspectionPlan::IN_PROCESS, null, $order->item_id, null, $op->routing_operation_id);
        $made = max((float) $op->qty_completed, 0.0);
        $count = $plan?->every_qty && (float) $plan->every_qty > 0 ? max(1, (int) ceil($made / (float) $plan->every_qty)) : 1;
        $already = Inspection::query()->where('order_operation_id', $op->id)->whereNull('disposition')->count();

        $opened = collect();
        for ($i = $already; $i < $count; $i++) {
            $slice = $plan?->every_qty ? min((float) $plan->every_qty, max($made - $i * (float) $plan->every_qty, 0)) : $made;
            $opened->push($this->open([
                'stage' => InspectionPlan::IN_PROCESS, 'plan' => $plan, 'stock_item_id' => $order->item->stock_item_id,
                'qty' => $slice, 'production_order_id' => $order->id, 'order_operation_id' => $op->id,
            ], $order->company_id, $actor));
        }

        return $opened;
    }

    // ── Recording ────────────────────────────────────────────────────────────────────────────

    /**
     * Record what was found. Each value is judged against the limits it was asked against and the
     * inspection is flagged failed automatically on any failure -- nobody types "pass".
     *
     * A failure raises a non-conformance, and at an inspection point it fails the operation's gate,
     * which stops the operations behind it until a disposition is made.
     *
     * @param  list<array{characteristic?: ?string, name?: ?string, sample_no?: ?int, value?: float|string|null, passed?: ?bool, notes?: ?string}>  $results
     */
    public function record(Inspection $inspection, array $results, ?User $actor, ?string $notes = null): Inspection
    {
        if ($inspection->status !== Inspection::OPEN) {
            throw new DomainException("Inspection {$inspection->number} has already been recorded.");
        }
        if ($results === []) {
            throw new DomainException('Record at least one result.');
        }

        $characteristics = $inspection->plan_id
            ? InspectionCharacteristic::query()->where('plan_id', $inspection->plan_id)->get()->keyBy('uuid')
            : collect();

        $rows = [];
        foreach ($results as $r) {
            $c = isset($r['characteristic']) ? $characteristics->get($r['characteristic']) : null;
            if (isset($r['characteristic']) && ! $c) {
                throw new DomainException('A result names a check that is not on this inspection\'s plan.');
            }

            $kind = $c?->kind ?? (array_key_exists('value', $r) && $r['value'] !== null && $r['value'] !== '' ? InspectionCharacteristic::MEASURED : InspectionCharacteristic::ATTRIBUTE);
            $lower = $c?->lower_limit !== null ? (float) $c->lower_limit : null;
            $upper = $c?->upper_limit !== null ? (float) $c->upper_limit : null;

            if ($kind === InspectionCharacteristic::MEASURED) {
                if (! isset($r['value']) || $r['value'] === '' || ! is_numeric($r['value'])) {
                    throw new DomainException(sprintf('Enter the measured value for %s.', $c?->name ?? ($r['name'] ?? 'each check')));
                }
                $value = (float) $r['value'];
                $passed = ($lower === null || $value >= $lower - 1e-9) && ($upper === null || $value <= $upper + 1e-9);
                // An ad-hoc measurement with no limits says nothing by itself; the inspector judges it.
                if ($c === null && $lower === null && $upper === null && array_key_exists('passed', $r) && $r['passed'] !== null) {
                    $passed = (bool) $r['passed'];
                }
            } else {
                if (! array_key_exists('passed', $r) || $r['passed'] === null) {
                    throw new DomainException(sprintf('Pass or fail %s.', $c?->name ?? ($r['name'] ?? 'each check')));
                }
                $value = null;
                $passed = (bool) $r['passed'];
            }

            $rows[] = [
                'company_id' => $inspection->company_id, 'inspection_id' => $inspection->id, 'characteristic_id' => $c?->id,
                'sample_no' => max(1, (int) ($r['sample_no'] ?? 1)),
                'name' => $c?->name ?? trim((string) ($r['name'] ?? 'Check')), 'kind' => $kind, 'uom' => $c?->uom,
                'target' => $c?->target, 'lower_limit' => $lower, 'upper_limit' => $upper, 'is_critical' => (bool) ($c?->is_critical ?? false),
                'measured_value' => $value, 'passed' => $passed, 'notes' => $r['notes'] ?? null,
                'defect_code_id' => ! empty($r['defect_code']) && ! $passed
                    ? \App\Modules\Operations\Manufacturing\Models\DefectCode::query()->where('code', $r['defect_code'])->value('id') : null,
                'recorded_by' => $actor?->id, 'recorded_at' => now(),
            ];
        }

        // Every check on the plan, as many samples as the plan asks for.
        $samples = max(1, (int) ($inspection->sample_size ?? 1));
        foreach ($characteristics as $c) {
            $got = collect($rows)->where('characteristic_id', $c->id)->count();
            if ($got < $samples) {
                throw new DomainException(sprintf('%s needs %d result%s; %d recorded.', $c->name, $samples, $samples === 1 ? '' : 's', $got));
            }
        }

        return DB::transaction(function () use ($inspection, $rows, $actor, $notes) {
            foreach ($rows as $row) {
                InspectionResult::create($row);
            }

            $failed = collect($rows)->contains(fn ($r) => ! $r['passed']);
            $inspection->fill([
                'result' => $failed ? Inspection::FAILED : Inspection::PASSED,
                'status' => Inspection::AWAITING_DISPOSITION,
                'inspected_by' => $actor?->id, 'inspected_at' => now(),
                'notes' => $notes ?? $inspection->notes,
            ])->save();

            if ($failed) {
                $critical = collect($rows)->contains(fn ($r) => ! $r['passed'] && $r['is_critical']);
                $ncr = $this->ncrs->raise([
                    'source' => $inspection->stage, 'inspection_id' => $inspection->id,
                    'stock_item_id' => $inspection->stock_item_id, 'lot_id' => $inspection->lot_id,
                    'production_order_id' => $inspection->production_order_id, 'supplier_id' => $inspection->supplier_id,
                    'purchase_order_id' => $inspection->purchase_order_id, 'qty_affected' => (float) $inspection->qty,
                    'severity' => $critical ? 'MAJOR' : 'MINOR',
                    'description' => "Failed {$inspection->number}: ".collect($rows)->where('passed', false)->pluck('name')->unique()->implode(', ').'.',
                ], $inspection->company_id, $actor);
                $inspection->update(['ncr_id' => $ncr->id]);

                if ($op = $inspection->operation()->first()) {
                    $this->execution()->recordGate($inspection->order()->first(), $op, 'FAILED', $actor,
                        "Inspection {$inspection->number} failed", fromInspection: true);
                }
            } elseif ($inspection->stage === InspectionPlan::IN_PROCESS) {
                // Nothing is held for an in-process check that passed: it closes itself, and the
                // gate opens once every inspection the operation needs has passed.
                $this->close($inspection, Inspection::ACCEPT, null, $actor);
                $this->openGateIfClear($inspection, $actor);
            }

            return $inspection->refresh();
        });
    }

    // ── Way out ──────────────────────────────────────────────────────────────────────────────

    /** The decisions each stage allows. */
    public function allowedDispositions(Inspection $inspection): array
    {
        $byStage = match ($inspection->stage) {
            InspectionPlan::INCOMING => [Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT, Inspection::REJECT, Inspection::RETURN_TO_SUPPLIER, Inspection::SCRAP],
            InspectionPlan::FINAL => [Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT, Inspection::REJECT, Inspection::REWORK, Inspection::SCRAP],
            // Warehouse stages (WMS-082): a return or a held bin may go anywhere; an order is passed or stopped.
            InspectionPlan::STORAGE, InspectionPlan::RETURN => [Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT, Inspection::REJECT,
                Inspection::RETURN_TO_SUPPLIER, Inspection::REWORK, Inspection::SCRAP],
            InspectionPlan::DISPATCH => [Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT, Inspection::REJECT],
            default => [Inspection::CONDITIONAL_ACCEPT, Inspection::REWORK, Inspection::SCRAP],
        };

        // A failed inspection is never simply accepted: using it anyway is a concession, with a reason.
        return $inspection->result === Inspection::FAILED
            ? array_values(array_diff($byStage, [Inspection::ACCEPT]))
            : $byStage;
    }

    /**
     * The authorised decision. Moves held stock to where the decision says, and lets production
     * carry on past a failed gate only through a decision recorded with its reason.
     */
    public function dispose(Inspection $inspection, string $decision, ?string $reason, ?User $actor): Inspection
    {
        if ($inspection->status !== Inspection::AWAITING_DISPOSITION) {
            throw new DomainException($inspection->status === Inspection::OPEN
                ? 'Record the inspection before deciding what happens to it.'
                : "Inspection {$inspection->number} has already been decided.");
        }
        if (! in_array($decision, $this->allowedDispositions($inspection), true)) {
            throw new DomainException($inspection->result === Inspection::FAILED && $decision === Inspection::ACCEPT
                ? 'A failed inspection cannot simply be accepted. Accept it as a concession, with the reason.'
                : 'That decision is not available for this inspection.');
        }
        if ($decision !== Inspection::ACCEPT && blank($reason)) {
            throw new DomainException('Give the reason for this decision.');
        }

        $plan = $inspection->plan()->first();
        if (in_array($decision, [Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT], true)
            && $plan?->requires_certificate && ! $inspection->libraryDocuments()->exists()) {
            throw new DomainException('This plan requires a certificate. File it against the inspection before releasing the stock.');
        }

        return DB::transaction(function () use ($inspection, $decision, $reason, $actor) {
            $this->moveHeldStock($inspection, $decision, $reason);

            $order = $inspection->order()->first();
            $op = $inspection->operation()->first();

            if ($inspection->stage === InspectionPlan::IN_PROCESS && $order && $op) {
                $this->disposeInProcess($inspection, $order, $op, $decision, $reason, $actor);
            }

            $this->close($inspection, $decision, $reason, $actor);

            if ($inspection->stage === InspectionPlan::FINAL && $decision === Inspection::REWORK && $order) {
                // The order goes back for rework and the same lot is inspected again afterwards.
                if ($order->status === PO::QUALITY_REVIEW) {
                    app(ProductionOrderLifecycle::class)->returnToProduction($order, $actor, "Rework after {$inspection->number}: {$reason}");
                }
                $this->open([
                    'stage' => InspectionPlan::FINAL, 'plan' => $inspection->plan()->first(), 'stock_item_id' => $inspection->stock_item_id,
                    'lot_id' => $inspection->lot_id, 'warehouse_id' => $inspection->warehouse_id, 'qty' => (float) $inspection->qty,
                    'held_status' => $inspection->held_status, 'production_order_id' => $inspection->production_order_id,
                    'notes' => "Re-inspection after rework ({$inspection->number})",
                ], $inspection->company_id, $actor);
            }

            if ($inspection->stage === InspectionPlan::IN_PROCESS && in_array($decision, [Inspection::CONDITIONAL_ACCEPT, Inspection::SCRAP], true)) {
                $this->openGateIfClear($inspection, $actor);
            }

            // Released finished output made for a customer is held for that customer (MFG-017).
            if ($inspection->stage === InspectionPlan::FINAL && $order && in_array($decision, [Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT], true)) {
                app(CustomerOrders::class)->allocateOutput($order);
            }

            return $inspection->refresh();
        });
    }

    // ── What production asks ─────────────────────────────────────────────────────────────────

    /**
     * Failed in-process inspections whose plan stops production until they are decided. While any
     * exist, the order's operations and output cannot be recorded.
     *
     * @return list<string> inspection numbers
     */
    public function blocking(PO $order): array
    {
        return Inspection::query()
            ->where('production_order_id', $order->id)
            ->where('stage', InspectionPlan::IN_PROCESS)
            ->where('result', Inspection::FAILED)
            ->where('status', '!=', Inspection::CLOSED)
            ->where(fn ($q) => $q->whereNull('plan_id')->orWhereIn('plan_id', InspectionPlan::query()->where('blocks_production', true)->select('id')))
            ->orderBy('id')->pluck('number')->all();
    }

    /** @return list<string> inspection numbers for this order that have not been decided */
    public function undecided(PO $order): array
    {
        return Inspection::query()->where('production_order_id', $order->id)
            ->where('status', '!=', Inspection::CLOSED)->orderBy('id')->pluck('number')->all();
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function open(array $data, ?int $companyId, ?User $actor): Inspection
    {
        $plan = $data['plan'] ?? null;
        unset($data['plan']);

        return Inspection::create($data + [
            'company_id' => $companyId,
            'number' => $this->numbers->next($companyId, 'QUALITY_INSPECTION'),
            'plan_id' => $plan?->id,
            'sample_size' => $plan?->sampleFor((float) ($data['qty'] ?? 0)),
            'result' => Inspection::PENDING,
            'status' => Inspection::OPEN,
            'created_by' => $actor?->id,
        ]);
    }

    private function close(Inspection $inspection, string $decision, ?string $reason, ?User $actor): void
    {
        $inspection->fill([
            'status' => Inspection::CLOSED, 'disposition' => $decision, 'disposition_reason' => $reason,
            'disposed_by' => $actor?->id, 'disposed_at' => now(),
        ])->save();
    }

    /** Scrapped output of an order goes into its cost subledger (MFG-021). */
    private function recordScrap(Inspection $inspection, \App\Modules\Finance\Accounting\Models\FinStockMovement $movement): \App\Modules\Finance\Accounting\Models\FinStockMovement
    {
        if ($order = $inspection->order()->first()) {
            app(ManufacturingAccounting::class)->recordMovement($movement, $order, \Illuminate\Support\Facades\Auth::user());
        }

        return $movement;
    }

    private function moveHeldStock(Inspection $inspection, string $decision, ?string $reason): void
    {
        if (! $inspection->held_status || ! $inspection->stock_item_id || ! $inspection->warehouse_id || (float) $inspection->qty <= 0) {
            return;
        }

        $item = FinStockItem::withoutGlobalScopes()->findOrFail($inspection->stock_item_id);
        $warehouse = FinWarehouse::withoutGlobalScopes()->findOrFail($inspection->warehouse_id);
        $qty = (float) $inspection->qty;
        $options = ['lot_id' => $inspection->lot_id, 'reason' => trim("{$inspection->number} {$decision}".($reason ? ": {$reason}" : '')),
            'idempotency_key' => "inspection:{$inspection->id}:{$decision}"];

        match ($decision) {
            Inspection::ACCEPT, Inspection::CONDITIONAL_ACCEPT => $this->stock->changeStatus($item, $warehouse, $qty, $inspection->held_status, FinStockPosition::AVAILABLE, $options),
            Inspection::REJECT, Inspection::RETURN_TO_SUPPLIER => $this->stock->changeStatus($item, $warehouse, $qty, $inspection->held_status, FinStockPosition::REJECTED, $options),
            Inspection::SCRAP => $this->recordScrap($inspection, $this->stock->scrap($item, $qty, $warehouse, $options + [
                'status' => $inspection->held_status, 'source_type' => Inspection::SOURCE_TYPE, 'source_id' => $inspection->id,
                'reference' => $inspection->number,
            ])),
            default => null, // REWORK: stays held until it is inspected again
        };
    }

    private function disposeInProcess(Inspection $inspection, PO $order, Op $op, string $decision, ?string $reason, ?User $actor): void
    {
        $qty = (float) $inspection->qty;

        if ($decision === Inspection::REWORK) {
            // The operation is done again; its gate is decided by the next inspection.
            $op->update(['status' => Op::READY, 'gate_status' => null, 'gate_passed_by' => null, 'gate_passed_at' => null, 'completed_at' => null]);
            if ($qty > 0) {
                $this->execution()->recordLoss($order, 0, $qty, $actor, "Rework after {$inspection->number}: {$reason}");
            }
        } elseif ($decision === Inspection::SCRAP && $qty > 0) {
            $this->execution()->recordLoss($order, $qty, 0, $actor, "Scrapped after {$inspection->number}: {$reason}");
        }
    }

    /** Pass the operation's gate once none of its inspections is still open or failed-and-blocking. */
    private function openGateIfClear(Inspection $inspection, ?User $actor): void
    {
        $op = $inspection->operation()->first();
        if (! $op || ! $op->is_inspection_point || $op->status !== Op::COMPLETED) {
            return;
        }

        $outstanding = Inspection::query()->where('order_operation_id', $op->id)->where('status', '!=', Inspection::CLOSED)->exists();
        if ($outstanding) {
            return;
        }

        $this->execution()->recordGate($inspection->order()->first(), $op->refresh(), 'PASSED', $actor,
            "Inspections cleared ({$inspection->number})", fromInspection: true);
    }

    /** Resolved when needed: execution opens inspections, and inspections pass execution's gates. */
    private function execution(): ProductionExecution
    {
        return app(ProductionExecution::class);
    }
}
