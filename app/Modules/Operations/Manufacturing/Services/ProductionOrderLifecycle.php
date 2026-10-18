<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Contracts\FinancialEvent;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockMovement;
use App\Modules\Finance\Accounting\Models\FinStockPosition;
use App\Modules\Finance\Accounting\Services\InventoryService;
use App\Modules\Finance\Accounting\Services\Ledger;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\ApprovalLimit;
use App\Modules\Operations\Manufacturing\Models\Bom;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation;
use App\Modules\Operations\Manufacturing\Models\Routing;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The production order lifecycle (MFG-006):
 *
 *   Draft -> Planned -> Approved -> Released -> In Production -> Quality Review -> Completed -> Closed
 *
 * with hold, cancellation and rejection, and controlled exceptions. Each transition is allowed from
 * named states only, writes an event with who and why, and runs its checks inside the transaction
 * that changes the status -- so a check cannot pass, then be invalidated, then the status change go
 * through anyway.
 *
 * An exception is never a quiet bypass. Where policy blocks a transition (a shortage at approval, a
 * capacity conflict, an operation completed out of order) a person holding mfg.order.override may
 * proceed only by giving a reason, and the event is marked as an exception.
 */
class ProductionOrderLifecycle
{
    public function __construct(
        private readonly ProductionOrders $orders,
        private readonly RoutingCalculator $calculator,
        private readonly FactoryCalendar $calendar,
        private readonly StockLedger $stock,
        private readonly InventoryService $inventory,
        private readonly Ledger $ledger,
    ) {}

    /** Draft -> Planned: schedule it through the factory calendar and refresh the estimate. */
    public function plan(PO $order, ?User $actor): PO
    {
        return $this->transition($order, [PO::DRAFT], PO::PLANNED, $actor, 'PLANNED', function (PO $o) {
            $unpriced = $this->orders->estimate($o);
            $schedule = $this->schedule($o);

            return ['detail' => array_filter(['unpriced' => $unpriced, 'schedule' => $schedule])];
        });
    }

    /** Planned -> Draft, to change it. */
    public function unplan(PO $order, ?User $actor): PO
    {
        return $this->transition($order, [PO::PLANNED], PO::DRAFT, $actor, 'UNPLANNED');
    }

    /**
     * Planned -> Approved, after verifying material, BOM, routing and capacity "according to
     * configured policy", and that the approver may sign an order of this value.
     *
     * @return PO
     */
    public function approve(PO $order, ?User $actor, ?string $overrideReason = null): PO
    {
        return $this->transition($order, [PO::PLANNED], PO::APPROVED, $actor, 'APPROVED', function (PO $o) use ($actor, $overrideReason) {
            $report = $this->approvalChecks($o);
            $policy = FactoryPolicy::for($o->factory_id, $o->company_id);

            $blocking = [];
            if ($report['bom_problem']) {
                $blocking[] = $report['bom_problem'];   // never overridable: the recipe itself is wrong
            }
            if ($report['routing_problem']) {
                $blocking[] = $report['routing_problem'];
            }

            $overridable = [];
            if ($report['shortages'] && $policy->require_material_for_approval) {
                $overridable[] = 'material is short: '.collect($report['shortages'])->pluck('item')->implode(', ');
            }
            if ($report['capacity_conflicts'] && $policy->block_on_capacity_conflict) {
                $overridable[] = 'capacity is over-committed at '.collect($report['capacity_conflicts'])->pluck('work_centre')->implode(', ');
            }

            if ($blocking) {
                throw new DomainException(implode(' ', $blocking));
            }

            $exception = false;
            if ($overridable) {
                $this->requireOverride($actor, $overrideReason, 'Cannot approve: '.implode('; ', $overridable).'.');
                $exception = true;
            }

            $this->assertApprovalLimit($o, $actor);

            $o->fill(['approved_by' => $actor?->id, 'approved_at' => now()]);

            return ['detail' => $report, 'exception' => $exception, 'reason' => $overrideReason];
        });
    }

    /** Planned -> Rejected, with a reason. Reopen sends it back to Draft. */
    public function reject(PO $order, ?User $actor, string $reason): PO
    {
        $this->requireReason($reason);

        return $this->transition($order, [PO::PLANNED], PO::REJECTED, $actor, 'REJECTED', fn () => ['reason' => $reason]);
    }

    public function reopen(PO $order, ?User $actor): PO
    {
        return $this->transition($order, [PO::REJECTED], PO::DRAFT, $actor, 'REOPENED');
    }

    /**
     * Approved -> Released: reserve the material and open the first operations.
     *
     * Reserves what is free up to what the order needs. A shortage the approver overrode is still
     * short here; it is recorded on the event rather than failing the release.
     */
    public function release(PO $order, ?User $actor): PO
    {
        return $this->transition($order, [PO::APPROVED], PO::RELEASED, $actor, 'RELEASED', function (PO $o) use ($actor) {
            $warehouse = $o->componentWarehouse()->first() ?? $o->warehouse()->first();
            $short = [];

            foreach ($o->components()->with('stockItem')->get() as $c) {
                if (! $c->isConsumed() || $c->outstanding() <= 0) {
                    continue;
                }

                $item = FinStockItem::withoutGlobalScopes()->findOrFail($c->stock_item_id);
                $free = $this->stock->available($item, $warehouse->id, null, null, null, [PO::SOURCE_TYPE, $o->id]);
                $hold = min($free, $c->outstanding());

                if ($hold > 0) {
                    $this->stock->reserve($item, $warehouse, $hold, PO::SOURCE_TYPE, $o->id);
                }
                if ($hold + 0.0001 < $c->outstanding()) {
                    $short[] = ['item' => $item->code, 'needed' => $c->outstanding(), 'reserved' => $hold];
                }
            }

            $this->openReadyOperations($o);

            $o->fill(['released_by' => $actor?->id, 'released_at' => now()]);

            return ['detail' => array_filter(['short_at_release' => $short])];
        });
    }

    /** Released -> In Production: who started it, and when. */
    public function start(PO $order, ?User $actor): PO
    {
        return $this->transition($order, [PO::RELEASED], PO::IN_PRODUCTION, $actor, 'STARTED', function (PO $o) use ($actor) {
            $o->fill(['started_by' => $actor?->id, 'started_at' => now()]);

            return [];
        });
    }

    /** In Production -> Quality Review, once every mandatory operation is cleared and output exists. */
    public function sendToQuality(PO $order, ?User $actor): PO
    {
        return $this->transition($order, [PO::IN_PRODUCTION], PO::QUALITY_REVIEW, $actor, 'SENT_TO_QUALITY', function (PO $o) {
            $open = $o->operations()->get()->filter(fn (ProductionOrderOperation $op) => $op->is_mandatory && ! $op->isCleared());

            if ($open->isNotEmpty()) {
                throw new DomainException('Operations still open: '.$open->map(fn ($op) => "{$op->sequence} {$op->name}")->implode(', ').'.');
            }

            if ((float) $o->qty_completed <= 0) {
                throw new DomainException('Nothing has been produced yet, so there is nothing to review.');
            }

            return [];
        });
    }

    /** Quality Review -> In Production, for rework. */
    public function returnToProduction(PO $order, ?User $actor, string $reason): PO
    {
        $this->requireReason($reason);

        return $this->transition($order, [PO::QUALITY_REVIEW], PO::IN_PRODUCTION, $actor, 'RETURNED_FOR_REWORK', fn () => ['reason' => $reason]);
    }

    /**
     * Quality Review -> Completed, with a reconciliation of what was made against what was used.
     *
     * Refused while finished goods from this order are still in quarantine: the quality decision has
     * to be made before the order can be called complete.
     */
    public function complete(PO $order, ?User $actor): PO
    {
        return $this->transition($order, [PO::QUALITY_REVIEW], PO::COMPLETED, $actor, 'COMPLETED', function (PO $o) {
            $quarantined = (float) FinStockPosition::withoutGlobalScopes()
                ->where('item_id', $o->item->stock_item_id)->where('warehouse_id', $o->warehouse_id)
                ->where('status', FinStockPosition::QUARANTINE)
                ->whereIn('lot_id', FinStockMovement::withoutGlobalScopes()
                    ->where('source_type', PO::SOURCE_TYPE)->where('source_id', $o->id)
                    ->where('item_id', $o->item->stock_item_id)->whereNotNull('lot_id')->select('lot_id'))
                ->sum('qty');

            if ($quarantined > 0.0001) {
                throw new DomainException(sprintf('%s of this order\'s output is still in quarantine; release or reject it first.', $this->num($quarantined)));
            }

            $o->fill(['completed_at' => now()]);

            return ['detail' => $this->reconciliation($o)];
        });
    }

    /**
     * Completed -> Closed: release any stock still held, settle the order's work in progress, and
     * lock it. After this the database refuses any edit; corrections are adjustments.
     *
     * Work in progress for an order is the material issued to it, less what came back, less the
     * standard material value of the good output received. Whatever is left is the material usage
     * variance, and it goes to Production Variance -- so WIP for a closed order is always zero.
     */
    public function close(PO $order, ?User $actor): PO
    {
        // Closing also freezes the order's variances against its standard (MFG-011): what is stored
        // is what was measured at closing, however prices move afterwards.
        return DB::transaction(function () use ($order, $actor) {
            $closed = $this->settle($order, $actor);
            app(CostAnalysis::class)->record($closed);

            return $closed;
        });
    }

    private function settle(PO $order, ?User $actor): PO
    {
        return $this->transition($order, [PO::COMPLETED], PO::CLOSED, $actor, 'CLOSED', function (PO $o) use ($actor) {
            // "Closing an order must verify required quality ... operations."
            $undecided = app(QualityControl::class)->undecided($o);
            if ($undecided !== []) {
                throw new DomainException('Inspections not yet decided: '.implode(', ', $undecided).'.');
            }

            // MFG-021: settlement and absorption post into an open period under approved treatments.
            $accounting = app(ManufacturingAccounting::class);
            $accounting->assertAllowed($o->company_id, 'ORDER_CLOSE');
            $accounting->assertPeriodOpen($o->company_id, null, $o);

            $released = $this->stock->releaseFor(PO::SOURCE_TYPE, $o->id, "Order {$o->number} closed");

            $residual = $this->wipBalance($o);
            $journalId = null;

            if (abs($residual) >= 0.01) {
                $wip = $this->inventory->accountIdFor(config('finance.wip_account', '1310'));
                $variance = $this->inventory->accountIdFor(config('finance.production_variance_account', '5600'));
                $amount = round(abs($residual), 2);

                $journalId = $this->ledger->post(new FinancialEvent(
                    sourceModule: 'manufacturing', eventType: 'production.variance',
                    debits: [['account' => $residual > 0 ? $variance : $wip, 'amount' => $amount, 'narration' => "Variance {$o->number}"]],
                    credits: [['account' => $residual > 0 ? $wip : $variance, 'amount' => $amount, 'narration' => "Variance {$o->number}"]],
                    eventDate: now(), companyId: $o->company_id, sourceRef: "production_order:{$o->id}",
                    description: "Production variance — {$o->number}",
                    idempotencyKey: "mfg-close:{$o->id}",
                ))->id;
            }

            $accounting->close($o, $journalId, $residual, $actor);

            $o->fill(['closed_by' => $actor?->id, 'closed_at' => now(), 'close_journal_id' => $journalId,
                'act_scrap_cost' => app(CostAnalysis::class)->actuals($o)['scrap_cost']]);

            return ['detail' => ['wip_settled' => round($residual, 2), 'reservations_released' => $released]];
        });
    }

    /** Hold an order that has not finished. Resume returns it to where it was. */
    public function hold(PO $order, ?User $actor, string $reason): PO
    {
        $this->requireReason($reason);
        $from = $order->status;

        return $this->transition($order, [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW],
            PO::ON_HOLD, $actor, 'HELD', function (PO $o) use ($from, $reason) {
                $o->fill(['status_before_hold' => $from]);

                return ['reason' => $reason];
            });
    }

    public function resume(PO $order, ?User $actor): PO
    {
        $back = $order->status_before_hold ?: PO::PLANNED;

        return $this->transition($order, [PO::ON_HOLD], $back, $actor, 'RESUMED', function (PO $o) {
            $o->fill(['status_before_hold' => null]);

            return [];
        });
    }

    /**
     * Cancel an order before it has consumed anything. Material already issued must come back
     * first -- cancelling over it would leave stock in WIP that no order owns.
     */
    public function cancel(PO $order, ?User $actor, string $reason): PO
    {
        $this->requireReason($reason);

        return $this->transition($order, [PO::DRAFT, PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::ON_HOLD, PO::REJECTED],
            PO::CANCELLED, $actor, 'CANCELLED', function (PO $o) use ($reason) {
                $consumed = $o->components()->get()->filter(fn ($c) => $c->netIssued() > 0.0001);

                if ($consumed->isNotEmpty()) {
                    throw new DomainException('Material has been issued to this order; return it before cancelling.');
                }

                return ['reason' => $reason, 'detail' => ['reservations_released' => $this->stock->releaseFor(PO::SOURCE_TYPE, $o->id, $reason)]];
            });
    }

    // ── Checks ───────────────────────────────────────────────────────────────────────────────

    /**
     * What approval verifies. Returned whole so the screen can show the planner exactly what
     * stands in the way, before anybody presses Approve.
     *
     * @return array{bom_problem: ?string, routing_problem: ?string, shortages: list<array>, capacity_conflicts: list<array>}
     */
    public function approvalChecks(PO $order): array
    {
        $bom = Bom::withoutGlobalScopes()->find($order->bom_id);
        $bomProblem = match (true) {
            $bom === null => 'The order\'s bill of materials no longer exists.',
            $bom->status === Bom::OBSOLETE => "BOM revision {$bom->revision} has been retired since the order was raised; raise it again.",
            ! in_array($bom->status, [Bom::APPROVED, Bom::SUPERSEDED], true) => "BOM revision {$bom->revision} is not approved.",
            default => null,
        };

        $routingProblem = null;
        $policy = FactoryPolicy::for($order->factory_id, $order->company_id);
        if ($order->routing_id) {
            $routing = Routing::withoutGlobalScopes()->find($order->routing_id);
            if (! $routing || $routing->status === Routing::OBSOLETE) {
                $routingProblem = 'The order\'s routing has been retired since it was raised; raise it again.';
            }
        } elseif ($policy->require_routing) {
            $routingProblem = 'This factory requires a routing and the order has none.';
        }

        return [
            'bom_problem' => $bomProblem,
            'routing_problem' => $routingProblem,
            'shortages' => $this->shortages($order),
            'capacity_conflicts' => $this->capacityConflicts($order),
        ];
    }

    /** @return list<array{item: string, needed: float, available: float, short: float}> */
    public function shortages(PO $order): array
    {
        $warehouseId = $order->component_warehouse_id ?? $order->warehouse_id;
        $out = [];

        foreach ($order->components()->with('stockItem')->get() as $c) {
            if (! $c->isConsumed() || ! $c->stockItem) {
                continue;
            }
            $needed = $c->outstanding();
            $free = $this->stock->available($c->stockItem, $warehouseId, null, null, null, [PO::SOURCE_TYPE, $order->id]);
            if ($free + 0.0001 < $needed) {
                $out[] = ['item' => $c->stockItem->code, 'needed' => round($needed, 4), 'available' => round($free, 4), 'short' => round($needed - $free, 4)];
            }
        }

        return $out;
    }

    /**
     * Work centres this order would push past their available time in its planned window, counting
     * the other orders already committed there.
     *
     * @return list<array{work_centre: string, required_minutes: float, available_minutes: float}>
     */
    public function capacityConflicts(PO $order): array
    {
        if (! $order->planned_start || ! $order->planned_finish) {
            return [];
        }

        $factory = $order->factory()->first();
        $available = $this->calendar->minutesBetween($factory, $order->planned_start, $order->planned_finish);
        $out = [];

        $mine = $order->operations()->whereNotNull('work_centre_id')->where('is_subcontracted', false)->get()->groupBy('work_centre_id');

        foreach ($mine as $wcId => $ops) {
            $committed = (float) ProductionOrderOperation::query()
                ->join('mfg_production_orders as o', 'o.id', '=', 'mfg_production_order_operations.order_id')
                ->where('mfg_production_order_operations.work_centre_id', $wcId)
                ->where('o.id', '!=', $order->id)
                ->whereIn('o.status', [PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])
                ->where('o.planned_start', '<', $order->planned_finish)
                ->where('o.planned_finish', '>', $order->planned_start)
                ->whereNotIn('mfg_production_order_operations.status', [ProductionOrderOperation::COMPLETED, ProductionOrderOperation::SKIPPED])
                ->sum('mfg_production_order_operations.planned_minutes');

            $required = $committed + (float) $ops->sum('planned_minutes');
            $wc = $ops->first()->workCentre;
            $centreAvailable = $available * max(1, (int) ($wc?->concurrent_jobs ?? 1));

            if ($required > $centreAvailable + 0.01) {
                $out[] = ['work_centre' => $wc?->code ?? "#{$wcId}", 'required_minutes' => round($required, 2), 'available_minutes' => round($centreAvailable, 2)];
            }
        }

        return $out;
    }

    /**
     * What was made against what was used (MFG-006: "completed quantities must be reconciled with
     * consumed materials, scrap, rework and remaining work").
     */
    public function reconciliation(PO $order): array
    {
        $planned = (float) $order->planned_qty;
        $good = (float) $order->qty_completed;
        $ratio = $planned > 0 ? $good / $planned : 0;

        return [
            'planned' => $planned,
            'good' => $good,
            'scrapped' => (float) $order->qty_scrapped,
            'reworked' => (float) $order->qty_reworked,
            'remaining' => max(0, round($planned - $good, 6)),
            'yield_percent' => $planned > 0 ? round($good / $planned * 100, 2) : null,
            'materials' => $order->components()->with('stockItem')->get()->filter->isConsumed()->map(fn ($c) => [
                'item' => $c->stockItem?->code,
                'required' => (float) $c->required_qty,
                // What the good output should have used, at the BOM's rate.
                'expected_for_output' => round((float) $c->required_qty * $ratio, 4),
                'consumed' => $c->netIssued(),
                'variance' => round($c->netIssued() - (float) $c->required_qty * $ratio, 4),
            ])->values()->all(),
            'operations_open' => $order->operations()->get()->filter(fn ($op) => $op->is_mandatory && ! $op->isCleared())->count(),
        ];
    }

    /**
     * The order's WIP: material issued to it, less material returned, less finished goods received
     * at standard. A reversal undoes the effect of the movement it reverses. Scrap posts to Scrap &
     * Spoilage, not to WIP, so it is not counted here.
     */
    public function wipBalance(PO $order): float
    {
        $movements = FinStockMovement::withoutGlobalScopes()
            ->where('source_type', PO::SOURCE_TYPE)->where('source_id', $order->id)->get()->keyBy('id');

        $effect = fn (string $type, float $value) => match ($type) {
            'mfg_issue' => $value,
            'mfg_return', 'mfg_receipt' => -$value,
            default => 0.0,
        };

        $balance = 0.0;
        foreach ($movements as $m) {
            $value = abs((float) $m->value);

            if ($m->type === 'reversal') {
                $original = $movements[$m->reversal_of_id] ?? FinStockMovement::withoutGlobalScopes()->find($m->reversal_of_id);
                $balance -= $original ? $effect($original->type, $value) : 0.0;

                continue;
            }

            $balance += $effect($m->type, $value);
        }

        return round($balance, 2);
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    private function transition(PO $order, array $from, string $to, ?User $actor, string $action, ?callable $work = null): PO
    {
        return DB::transaction(function () use ($order, $from, $to, $actor, $action, $work) {
            $o = PO::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($o->status, $from, true)) {
                throw new DomainException(sprintf('Order %s is %s; it cannot be %s from there.',
                    $o->number, str_replace('_', ' ', strtolower($o->status)), strtolower(str_replace('_', ' ', $action))));
            }

            $was = $o->status;
            $result = $work ? ($work($o) ?? []) : [];

            $o->status = $to;
            $o->save();

            $this->orders->event($o, $action, $was, $to, $actor, $result['reason'] ?? null,
                $result['detail'] ?? [], (bool) ($result['exception'] ?? false));

            $order->setRawAttributes($o->getAttributes(), true);

            return $o;
        });
    }

    private function schedule(PO $order): array
    {
        if (! $order->routing_id) {
            return [];
        }

        $routing = Routing::withoutGlobalScopes()->find($order->routing_id);
        $estimate = $this->calculator->estimate($routing, (float) $order->planned_qty, $order->factory()->first(),
            $order->planned_start ?? now());

        $finish = $estimate['estimated_finish'] ? Carbon::parse($estimate['estimated_finish'])->endOfDay() : null;
        $order->fill(['planned_finish' => $finish]);

        return ['lead_minutes' => $estimate['lead_minutes'], 'estimated_finish' => $estimate['estimated_finish'],
            'late' => $finish && $finish->gt(Carbon::parse($order->due_date)->endOfDay())];
    }

    /** The first operations of a released order become ready: those with nothing mandatory before them. */
    private function openReadyOperations(PO $order): void
    {
        foreach ($order->operations()->get() as $op) {
            $blocking = $order->operations()->get()->filter(fn ($p) => $p->sequence < $op->sequence && $p->is_mandatory
                && ($op->parallel_group === null || $p->parallel_group !== $op->parallel_group));

            if ($blocking->isEmpty() && $op->status === ProductionOrderOperation::PENDING) {
                $op->update(['status' => ProductionOrderOperation::READY]);
            }
        }
    }

    /**
     * An approval band covering the order's estimated value, for this factory or company-wide,
     * names the permission the approver must hold. No bands configured: the approve permission is
     * enough.
     */
    private function assertApprovalLimit(PO $order, ?User $actor): void
    {
        $value = $order->estimatedTotal();

        $band = ApprovalLimit::query()
            ->where('document_type', 'PRODUCTION_ORDER')->where('is_active', true)
            ->where(fn ($q) => $q->where('factory_id', $order->factory_id)->orWhereNull('factory_id'))
            ->orderByRaw('factory_id IS NULL')->orderBy('min_amount')
            ->get()
            ->first(fn (ApprovalLimit $l) => $l->covers($value));

        if ($band && $actor && ! $actor->can($band->required_permission)) {
            throw new DomainException(sprintf('An order of %s needs approval by someone holding %s.',
                number_format($value, 2), $band->required_permission));
        }
    }

    private function requireOverride(?User $actor, ?string $reason, string $message): void
    {
        if (! $actor || ! $actor->can(RoleCatalog::OVERRIDE_CONTROLS)) {
            throw new DomainException($message.' An authorised override is required.');
        }
        if (blank($reason)) {
            throw new DomainException($message.' Give a reason for the override; it is recorded on the order.');
        }
    }

    private function requireReason(?string $reason): void
    {
        if (blank($reason)) {
            throw new DomainException('A reason is required; it is recorded on the order.');
        }
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
