<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\InspectionPlan;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderComponent;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\SubcontractMaterial;
use App\Modules\Operations\Manufacturing\Models\SubcontractOrder as SCO;
use App\Modules\Operations\Manufacturing\Models\SubcontractReceipt;
use App\Modules\Procurement\Services\PurchaseRequestService;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Work done by a supplier (MFG-017).
 *
 * Two shapes. A subcontracted operation: the work in progress goes out, the supplier does that one
 * step, and it comes back to finish the routing. A subcontracted order: the supplier makes the
 * product from material we send, and finished goods come back.
 *
 * Material sent is issued to the production order -- it is the order's WIP, sitting at the
 * supplier -- and anything unused is returned to the order's warehouse. What comes back is always
 * inspected: an operation's return becomes an inspection point the next step waits on, and an
 * order's output is received into quarantine. The supplier's charge becomes the order's actual
 * subcontract cost and reaches procurement as a draft purchase request when the work is sent;
 * ordering, receiving the invoice and paying it are procurement's and accounts payable's.
 */
class Subcontracting
{
    public function __construct(
        private readonly NumberSequences $numbers,
        private readonly ProductionExecution $execution,
        private readonly QualityControl $quality,
        private readonly PurchaseRequestService $requests,
    ) {}

    /**
     * @param  array{supplier_id?: ?int, qty?: ?float, unit_price?: ?float, due_date?: ?string, notes?: ?string,
     *               materials?: array<int, float>}  $data  materials: component id => quantity to send
     */
    public function create(PO $order, ?Op $op, array $data, ?User $actor): SCO
    {
        if (! in_array($order->status, [PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION], true)) {
            throw new DomainException("Order {$order->number} is ".strtolower(str_replace('_', ' ', $order->status)).'; work is sent out only for an approved or running order.');
        }

        $routingOp = null;
        if ($op) {
            if ((int) $op->order_id !== (int) $order->id) {
                throw new DomainException('That operation belongs to another order.');
            }
            if (! $op->is_subcontracted) {
                throw new DomainException("Operation {$op->sequence} {$op->name} is done in-house; the routing does not send it out.");
            }
            if (in_array($op->status, [Op::COMPLETED, Op::SKIPPED], true)) {
                throw new DomainException("Operation {$op->sequence} is already {$op->status}.");
            }
            if (SCO::query()->where('order_operation_id', $op->id)->whereIn('status', SCO::OPEN)->exists()) {
                throw new DomainException("Operation {$op->sequence} is already out with a supplier.");
            }
            $routingOp = $op->routing_operation_id ? \App\Modules\Operations\Manufacturing\Models\RoutingOperation::withoutGlobalScopes()->find($op->routing_operation_id) : null;
        }

        $supplierId = $data['supplier_id'] ?? $routingOp?->subcontractor_supplier_id;
        if (! $supplierId) {
            throw new DomainException('Name the supplier doing the work.');
        }
        $qty = (float) ($data['qty'] ?? ($op ? (float) $order->planned_qty : max(0, (float) $order->planned_qty - (float) $order->qty_completed)));
        if ($qty <= 0) {
            throw new DomainException('Send a quantity greater than zero.');
        }
        $price = (float) ($data['unit_price'] ?? $routingOp?->subcontract_cost_per_unit ?? 0);
        $due = $data['due_date'] ?? ($routingOp?->subcontract_lead_days !== null ? today()->addDays((int) $routingOp->subcontract_lead_days)->toDateString() : null);

        // Material for the supplier: what was asked for, or for a whole order everything still owed to it.
        $materials = $data['materials'] ?? ($op ? [] : $order->components()->get()
            ->filter(fn (ProductionOrderComponent $c) => $c->isConsumed() && $c->stock_item_id && $c->outstanding() > 0)
            ->mapWithKeys(fn ($c) => [$c->id => round($c->outstanding(), 6)])->all());

        return DB::transaction(function () use ($order, $op, $supplierId, $qty, $price, $due, $data, $materials, $actor) {
            $sco = SCO::create([
                'company_id' => $order->company_id, 'number' => $this->numbers->next($order->company_id, 'SUBCONTRACT', $order->factory_id),
                'production_order_id' => $order->id, 'order_operation_id' => $op?->id, 'supplier_id' => $supplierId,
                'qty' => $qty, 'unit_price' => $price, 'currency' => config('finance.base_currency', 'ZAR'),
                'due_date' => $due, 'status' => SCO::DRAFT, 'notes' => $data['notes'] ?? null, 'created_by' => $actor?->id,
            ]);

            foreach ($materials as $componentId => $q) {
                if ((float) $q <= 0) {
                    continue;
                }
                $c = $order->components()->whereKey($componentId)->first();
                if (! $c || ! $c->isConsumed() || ! $c->stock_item_id) {
                    throw new DomainException('Material sent to a supplier must be a component of the order.');
                }
                SubcontractMaterial::create(['company_id' => $order->company_id, 'subcontract_order_id' => $sco->id,
                    'component_id' => $c->id, 'stock_item_id' => $c->stock_item_id, 'qty_planned' => (float) $q]);
            }

            $this->orders()->event($order, 'SUBCONTRACT_RAISED', $order->status, $order->status, $actor, null,
                ['subcontract' => $sco->number, 'operation' => $op?->sequence, 'qty' => $qty]);

            return $sco->refresh();
        });
    }

    /**
     * The work leaves: material is issued to the order and goes with it, an operation is marked
     * as running at the supplier, and procurement gets a draft request for the supplier's charge.
     */
    public function send(SCO $sco, ?User $actor, ?string $dispatchReference = null, array $materials = []): SCO
    {
        if ($sco->status !== SCO::DRAFT) {
            throw new DomainException("{$sco->number} has already been sent.");
        }
        $order = $sco->order()->firstOrFail();

        return DB::transaction(function () use ($sco, $order, $actor, $dispatchReference, $materials) {
            foreach ($sco->materials()->get() as $m) {
                $q = (float) ($materials[$m->component_id] ?? $m->qty_planned);
                if ($q <= 0) {
                    continue;
                }
                $component = ProductionOrderComponent::query()->findOrFail($m->component_id);
                $this->execution->issue($order, $component, $q, $actor, [
                    'idempotency_key' => "sco:{$sco->id}:send:{$m->component_id}", 'reference' => $sco->number,
                ]);
                $m->update(['qty_sent' => round((float) $m->qty_sent + $q, 6)]);
            }

            if ($op = $sco->operation()->first()) {
                $this->execution->startOperation($order, $op, $actor);
            }

            $request = $this->requests->create([
                'justification' => "Subcontracted work {$sco->number} for production order {$order->number}.",
                'needed_by' => $sco->due_date?->toDateString(), 'urgency' => 'normal',
                'currency' => $sco->currency, 'status' => 'draft', 'estimated_total' => round((float) $sco->qty * (float) $sco->unit_price, 2),
            ], $actor?->id);
            $request->items()->create([
                'item_type' => 'service', 'name' => $this->description($sco, $order),
                'description' => "Supplier processing. Production order {$order->number}.",
                'supplier_id' => $sco->supplier_id, 'quantity' => (float) $sco->qty, 'unit_price' => (float) $sco->unit_price,
                'line_total' => round((float) $sco->qty * (float) $sco->unit_price, 2), 'source' => 'MANUFACTURING', 'external_ref' => $sco->number,
            ]);

            $sco->update(['status' => SCO::SENT, 'qty_sent' => $sco->qty, 'sent_at' => now(), 'sent_by' => $actor?->id,
                'dispatch_reference' => $dispatchReference, 'purchase_request_id' => $request->id]);
            $this->orders()->event($order, 'SUBCONTRACT_SENT', $order->status, $order->status, $actor, null,
                ['subcontract' => $sco->number, 'dispatch' => $dispatchReference]);

            return $sco->refresh();
        });
    }

    /**
     * What came back. An operation comes back once: what is not returned is lost at the supplier,
     * the operation completes, and an inspection decides whether the next step may begin. An order's
     * output may come back in parts, each received into quarantine under a final inspection.
     */
    public function receive(SCO $sco, float $qty, ?User $actor, ?string $supplierReference = null, ?string $lotNumber = null): SubcontractReceipt
    {
        if (! in_array($sco->status, [SCO::SENT, SCO::PART_RECEIVED], true)) {
            throw new DomainException("{$sco->number} is ".strtolower(str_replace('_', ' ', $sco->status)).'; nothing is expected back.');
        }
        if ($qty <= 0 || $qty > $sco->outstanding() + 0.0001) {
            throw new DomainException(sprintf('Receive more than zero and no more than the %s still out.', $this->num($sco->outstanding())));
        }
        $order = $sco->order()->firstOrFail();
        $op = $sco->operation()->first();

        return DB::transaction(function () use ($sco, $order, $op, $qty, $actor, $supplierReference, $lotNumber) {
            if ($op) {
                $lost = round($sco->outstanding() - $qty, 6);
                // Returned work is inspected before the routing moves on: the operation becomes a gate.
                $op->update(['is_inspection_point' => true]);
                $this->execution->completeOperation($order, $op->refresh(), ['completed' => $qty, 'scrapped' => $lost,
                    'machine_minutes' => 0, 'labour_minutes' => 0], $actor);
                $inspection = $this->quality->openForSubcontractReceipt($order, $op->refresh(), $qty, (int) $sco->supplier_id, $actor);
                $status = SCO::RECEIVED;
                $sco->qty_rejected = $lost;
            } else {
                $this->execution->receiveOutput($order, $qty, $actor, [
                    'inspect' => true, 'supplier_id' => (int) $sco->supplier_id, 'lot_number' => $lotNumber,
                    'idempotency_key' => "sco:{$sco->id}:receive:".(SubcontractReceipt::query()->where('subcontract_order_id', $sco->id)->count() + 1),
                ]);
                $inspection = Inspection::query()->where('production_order_id', $order->id)->where('stage', InspectionPlan::FINAL)->latest('id')->first();
                $status = $sco->outstanding() - $qty <= 0.0001 ? SCO::RECEIVED : SCO::PART_RECEIVED;
            }

            $receipt = SubcontractReceipt::create(['company_id' => $sco->company_id, 'subcontract_order_id' => $sco->id, 'qty' => $qty,
                'supplier_reference' => $supplierReference, 'inspection_id' => $inspection?->id, 'received_by' => $actor?->id, 'received_at' => now()]);
            $sco->qty_received = round((float) $sco->qty_received + $qty, 6);
            $sco->status = $status;
            $sco->save();

            $this->refreshCost($order);
            $this->orders()->event($order, 'SUBCONTRACT_RECEIVED', $order->status, $order->status, $actor, null,
                ['subcontract' => $sco->number, 'qty' => $qty, 'inspection' => $inspection?->number]);

            return $receipt;
        });
    }

    /** Unused material comes back from the supplier into the order's warehouse. */
    public function returnMaterial(SCO $sco, SubcontractMaterial $m, float $qty, ?User $actor): SubcontractMaterial
    {
        if ((int) $m->subcontract_order_id !== (int) $sco->id) {
            throw new DomainException('That material was sent with another subcontract order.');
        }
        $atSupplier = round((float) $m->qty_sent - (float) $m->qty_returned, 6);
        if ($qty <= 0 || $qty > $atSupplier + 0.0001) {
            throw new DomainException(sprintf('Only %s is at the supplier to return.', $this->num($atSupplier)));
        }

        return DB::transaction(function () use ($sco, $m, $qty, $actor) {
            $n = (int) round((float) $m->qty_returned * 1000000);
            $this->execution->returnMaterial($sco->order()->firstOrFail(), ProductionOrderComponent::query()->findOrFail($m->component_id), $qty, $actor, [
                'idempotency_key' => "sco:{$sco->id}:return:{$m->component_id}:{$n}",
            ]);
            $m->update(['qty_returned' => round((float) $m->qty_returned + $qty, 6)]);

            return $m->refresh();
        });
    }

    /** Finished with the supplier. A short delivery is closed with a reason; it stays on record. */
    public function close(SCO $sco, ?User $actor, ?string $reason = null): SCO
    {
        if (! in_array($sco->status, [SCO::RECEIVED, SCO::PART_RECEIVED], true)) {
            throw new DomainException('Close it once something has come back; cancel it if nothing was sent.');
        }
        if ($sco->status === SCO::PART_RECEIVED && blank($reason)) {
            throw new DomainException(sprintf('%s is still out. Say why the order is closed short.', $this->num($sco->outstanding())));
        }
        $sco->update(['status' => SCO::CLOSED, 'closed_at' => now(), 'notes' => trim(($sco->notes ? $sco->notes."\n" : '').($reason ?? '')) ?: null]);
        $order = $sco->order()->firstOrFail();
        $this->orders()->event($order, 'SUBCONTRACT_CLOSED', $order->status, $order->status, $actor, $reason, ['subcontract' => $sco->number]);

        return $sco->refresh();
    }

    public function cancel(SCO $sco, ?User $actor, string $reason): SCO
    {
        if ($sco->status !== SCO::DRAFT) {
            throw new DomainException('Only work not yet sent can be cancelled; once it has gone, receive it or close it.');
        }
        if (blank($reason)) {
            throw new DomainException('Say why it is cancelled.');
        }
        $sco->update(['status' => SCO::CANCELLED, 'closed_at' => now(), 'notes' => trim(($sco->notes ? $sco->notes."\n" : '').$reason)]);

        return $sco->refresh();
    }

    /** The order's actual subcontract cost: what was received, at the agreed price. */
    public function refreshCost(PO $order): void
    {
        $cost = SCO::query()->where('production_order_id', $order->id)->where('status', '!=', SCO::CANCELLED)->get()->sum(fn (SCO $s) => $s->cost());
        PO::query()->whereKey($order->id)->update(['act_subcontract_cost' => round($cost, 2)]);
    }

    private function description(SCO $sco, PO $order): string
    {
        $op = $sco->operation()->first();
        $order->loadMissing('item.stockItem');

        return trim(($op ? "{$op->sequence} {$op->name}" : 'Make').' — '.($order->item?->stockItem?->code ?? ''), ' —');
    }

    private function orders(): ProductionOrders
    {
        return app(ProductionOrders::class);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
