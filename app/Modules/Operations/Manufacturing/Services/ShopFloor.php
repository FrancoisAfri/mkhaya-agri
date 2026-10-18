<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\FloorEscalation;
use App\Modules\Operations\Manufacturing\Models\OperationTimeLog;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The shop floor (MFG-007): what an operator sees and does at a workstation.
 *
 * Start, pause and complete go through ProductionExecution, so the routing engine, quality holds
 * and the order lifecycle apply exactly as they do anywhere else. What this adds is the record of
 * the work itself: a time log for every stretch of work, so labour and machine time are measured;
 * downtime against a reason code; and escalations that climb when nobody answers.
 *
 * Nothing here touches a BOM, a routing, a rate or a setting -- recording production activity
 * grants no power over them.
 */
class ShopFloor
{
    public function __construct(
        private readonly ProductionExecution $execution,
        private readonly ProductionOrders $orders,
    ) {}

    // ── The queue ────────────────────────────────────────────────────────────────────────────

    /**
     * Jobs a work centre (or an operator) can work on now, most urgent first: running and paused
     * jobs, then ready ones, then those still waiting for an earlier operation.
     *
     * @return Collection<int, Op>
     */
    public function queue(?int $workCentreId = null, ?int $userId = null, bool $includeWaiting = true): Collection
    {
        $statuses = [Op::IN_PROGRESS, Op::PAUSED, Op::READY, ...($includeWaiting ? [Op::PENDING] : [])];

        return Op::query()
            ->select('mfg_production_order_operations.*')
            ->join('mfg_production_orders as o', 'o.id', '=', 'mfg_production_order_operations.order_id')
            ->whereIn('o.status', [PO::RELEASED, PO::IN_PRODUCTION])
            ->whereIn('mfg_production_order_operations.status', $statuses)
            ->when($workCentreId, fn ($q) => $q->where('mfg_production_order_operations.work_centre_id', $workCentreId))
            ->when($userId, fn ($q) => $q->where('mfg_production_order_operations.assigned_to', $userId))
            ->with(['order.item.stockItem:id,code,name', 'workCentre:id,code,name', 'assignee.person'])
            ->orderByRaw("CASE mfg_production_order_operations.status WHEN 'IN_PROGRESS' THEN 0 WHEN 'PAUSED' THEN 1 WHEN 'READY' THEN 2 ELSE 3 END")
            ->orderBy('o.priority')->orderBy('o.due_date')->orderBy('mfg_production_order_operations.sequence')
            ->limit(200)
            ->get();
    }

    /** A supervisor gives a job to an operator. */
    public function assign(Op $op, ?User $operator, ?User $actor): Op
    {
        if ($op->status === Op::COMPLETED) {
            throw new DomainException("Operation {$op->sequence} is already complete.");
        }
        // Only to somebody holding every mandatory qualification the operation needs (MFG-016).
        app(Workforce::class)->assertQualified($operator, $op);

        $op->update(['assigned_to' => $operator?->id, 'assigned_at' => $operator ? now() : null]);
        $this->orders->event($op->order, 'ASSIGNED', $op->order->status, $op->order->status, $actor, null,
            ['operation' => $op->sequence, 'operator_id' => $operator?->id]);

        return $op;
    }

    // ── Doing the work ───────────────────────────────────────────────────────────────────────

    /** Start or resume: opens a time log and closes any stoppage the pause opened. */
    public function start(Op $op, ?User $actor): Op
    {
        $this->assertMayWork($op, $actor);
        // A machine on a maintenance or safety hold may not be used until it is returned to service (MFG-013).
        app(Maintenance::class)->assertUsable($op->workCentre()->first());
        app(Workforce::class)->assertQualified($actor, $op);

        return DB::transaction(function () use ($op, $actor) {
            $order = $op->order()->firstOrFail();
            $op = $this->execution->startOperation($order, $op, $actor);

            if (! OperationTimeLog::query()->where('order_operation_id', $op->id)->whereNull('ended_at')->exists()) {
                OperationTimeLog::create(['company_id' => $op->company_id, 'order_operation_id' => $op->id,
                    'user_id' => $actor?->id, 'started_at' => $this->clock()]);
            }

            foreach (DowntimeEvent::query()->where('order_operation_id', $op->id)->whereNull('ended_at')->get() as $stop) {
                $this->endDowntime($stop, $actor);
            }

            return $op->refresh();
        });
    }

    /**
     * Pause. With a reason code the pause is a stoppage, recorded as downtime at the work centre
     * until the job is resumed; without one it is just a break in the job.
     */
    public function pause(Op $op, ?User $actor, ?DowntimeReason $reason = null, ?string $notes = null): Op
    {
        $this->assertMayWork($op, $actor);

        return DB::transaction(function () use ($op, $actor, $reason, $notes) {
            $op = $this->execution->pauseOperation($op->order()->firstOrFail(), $op);
            $this->closeLogs($op, 'PAUSE');

            if ($reason && $op->work_centre_id) {
                $this->startDowntime($op->workCentre()->firstOrFail(), $reason, $actor, $notes, $op);
            }

            return $op->refresh();
        });
    }

    /**
     * Complete with quantities. Machine and labour minutes default to what the time logs measured,
     * so an operator who types nothing still produces a measured figure.
     */
    public function complete(Op $op, array $result, ?User $actor, ?string $overrideReason = null): Op
    {
        $this->assertMayWork($op, $actor);

        return DB::transaction(function () use ($op, $result, $actor, $overrideReason) {
            $this->closeLogs($op, 'COMPLETE');
            $logged = (float) OperationTimeLog::query()->where('order_operation_id', $op->id)->sum('minutes');

            $result['machine_minutes'] = isset($result['machine_minutes']) && $result['machine_minutes'] !== null ? $result['machine_minutes'] : $logged;
            $result['labour_minutes'] = isset($result['labour_minutes']) && $result['labour_minutes'] !== null ? $result['labour_minutes'] : $logged;

            return $this->execution->completeOperation($op->order()->firstOrFail(), $op, $result, $actor, $overrideReason);
        });
    }

    /** A production note against the job, kept in the order's history. */
    public function note(Op $op, string $text, ?User $actor): void
    {
        if (blank($text)) {
            throw new DomainException('Write the note.');
        }

        $order = $op->order()->firstOrFail();
        $this->orders->event($order, 'NOTE', $order->status, $order->status, $actor, $text, ['operation' => $op->sequence]);
    }

    // ── Downtime ─────────────────────────────────────────────────────────────────────────────

    public function startDowntime(WorkCentre $centre, DowntimeReason $reason, ?User $actor, ?string $notes = null, ?Op $op = null, ?int $assetId = null): DowntimeEvent
    {
        if (! $reason->is_active) {
            throw new DomainException('That reason code is no longer in use.');
        }
        if ($assetId !== null && ! $centre->machines()->whereKey($assetId)->exists()) {
            throw new DomainException('That machine is not at this work centre.');
        }

        return DowntimeEvent::create([
            'company_id' => $centre->company_id, 'factory_id' => $centre->factory_id, 'work_centre_id' => $centre->id,
            'asset_id' => $assetId, 'order_operation_id' => $op?->id, 'reason_id' => $reason->id,
            'started_at' => $this->clock(), 'notes' => $notes, 'reported_by' => $actor?->id,
        ]);
    }

    public function endDowntime(DowntimeEvent $event, ?User $actor, ?string $notes = null): DowntimeEvent
    {
        if (! $event->isOpen()) {
            throw new DomainException('That stoppage has already ended.');
        }

        $event->update([
            'ended_at' => $this->clock(), 'minutes' => round($event->started_at->diffInSeconds($this->clock()) / 60, 2),
            'ended_by' => $actor?->id, 'notes' => trim(($event->notes ?? '').($notes ? "\n".$notes : '')) ?: null,
        ]);

        return $event;
    }

    // ── Escalation ───────────────────────────────────────────────────────────────────────────

    /** Ask for help. Goes to the order's supervisor where there is one. */
    public function escalate(string $kind, string $message, ?User $actor, ?Op $op = null, ?WorkCentre $centre = null): FloorEscalation
    {
        if (! in_array($kind, FloorEscalation::KINDS, true) || blank($message)) {
            throw new DomainException('Say what kind of help is needed and what the problem is.');
        }

        $order = $op?->order()->first();
        $centre ??= $op?->workCentre()->first();
        $factoryId = $order?->factory_id ?? $centre?->factory_id;

        if (! $factoryId) {
            throw new DomainException('An escalation needs a job or a work centre, so it reaches the right factory.');
        }

        return FloorEscalation::create([
            'company_id' => $order?->company_id ?? $centre->company_id, 'factory_id' => $factoryId,
            'order_id' => $order?->id, 'order_operation_id' => $op?->id, 'work_centre_id' => $centre?->id,
            'kind' => $kind, 'message' => $message, 'status' => FloorEscalation::OPEN, 'level' => 1,
            'raised_by' => $actor?->id, 'raised_at' => now(), 'assigned_to' => $order?->supervisor_id,
        ]);
    }

    public function acknowledge(FloorEscalation $e, ?User $actor): FloorEscalation
    {
        if ($e->status !== FloorEscalation::OPEN) {
            throw new DomainException('Only an open escalation can be acknowledged.');
        }
        $e->update(['status' => FloorEscalation::ACKNOWLEDGED, 'acknowledged_by' => $actor?->id, 'acknowledged_at' => now()]);

        return $e;
    }

    public function resolveEscalation(FloorEscalation $e, string $resolution, ?User $actor): FloorEscalation
    {
        if ($e->status === FloorEscalation::RESOLVED) {
            throw new DomainException('That escalation is already resolved.');
        }
        if (blank($resolution)) {
            throw new DomainException('Say how it was resolved.');
        }
        $e->update([
            'status' => FloorEscalation::RESOLVED, 'resolution' => $resolution, 'resolved_by' => $actor?->id, 'resolved_at' => now(),
            'acknowledged_by' => $e->acknowledged_by ?? $actor?->id, 'acknowledged_at' => $e->acknowledged_at ?? now(),
        ]);

        return $e;
    }

    /**
     * Climb every open escalation nobody has acknowledged within its factory's window, one level at
     * a time. Run on a schedule; returns how many climbed.
     */
    public function climbUnanswered(int $companyId): int
    {
        $climbed = 0;

        // Named company, not the session: a superuser connection sees every tenant's rows.
        foreach (FloorEscalation::withoutGlobalScopes()->where('company_id', $companyId)->where('status', FloorEscalation::OPEN)->get() as $e) {
            $minutes = (int) (FactoryPolicy::for($e->factory_id, $e->company_id)->escalate_after_minutes ?? 30);
            $since = $e->escalated_at ?? $e->raised_at;

            if ($since->copy()->addMinutes($minutes)->isPast()) {
                $e->forceFill(['level' => $e->level + 1, 'escalated_at' => now()])->save();
                $climbed++;
            }
        }

        return $climbed;
    }

    // ── Scanning ─────────────────────────────────────────────────────────────────────────────

    /**
     * What a scanned code refers to. Job cards carry "JOB:<operation uuid>"; an order number, a
     * lot number and an item code are recognised as typed or printed.
     *
     * @return array{type: string, id: string|int, label: string, order_id?: string, item_id?: int}|null
     */
    public function identify(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        if (preg_match('/^JOB:([0-9a-f-]{36})$/i', $code, $m)) {
            $op = Op::query()->with('order:id,uuid,number')->where('uuid', strtolower($m[1]))->first();

            return $op ? ['type' => 'operation', 'id' => $op->uuid, 'order_id' => $op->order?->uuid,
                'label' => "{$op->order?->number} · {$op->sequence} {$op->name}"] : null;
        }

        if ($order = PO::query()->where('number', $code)->first()) {
            return ['type' => 'order', 'id' => $order->uuid, 'label' => $order->number];
        }

        if ($lot = FinStockLot::query()->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class)->where('lot_number', $code)->first()) {
            $code = FinStockItem::withoutGlobalScopes()->whereKey($lot->item_id)->value('code');

            return ['type' => 'lot', 'id' => $lot->id, 'label' => "{$lot->lot_number} · {$code}", 'item_id' => $lot->item_id];
        }

        if ($item = FinStockItem::query()->withoutGlobalScope(\App\Modules\Finance\Accounting\Scopes\EntityScope::class)->where('code', $code)->first()) {
            return ['type' => 'item', 'id' => $item->id, 'label' => "{$item->code} — {$item->name}"];
        }

        return null;
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    /** A job assigned to somebody is theirs; a supervisor may step in. */
    private function assertMayWork(Op $op, ?User $actor): void
    {
        if ($op->assigned_to && $actor && (int) $op->assigned_to !== (int) $actor->id && ! $actor->can(RoleCatalog::SUPERVISE_FLOOR)) {
            throw new DomainException("Operation {$op->sequence} is assigned to somebody else.");
        }
    }

    private function closeLogs(Op $op, string $action): void
    {
        foreach (OperationTimeLog::query()->where('order_operation_id', $op->id)->whereNull('ended_at')->get() as $log) {
            $log->update(['ended_at' => $this->clock(), 'minutes' => round($log->started_at->diffInSeconds($this->clock()) / 60, 2), 'ended_by_action' => $action]);
        }
    }

    /**
     * Now, to the whole second. The columns store whole seconds (Postgres rounds the fraction), so
     * a duration measured against an unrounded clock came out a second long or short.
     */
    private function clock(): \Illuminate\Support\Carbon
    {
        return now()->startOfSecond();
    }
}
