<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One queue for the floor (WMS-130): receiving, put-away, replenishment, picking, packing, counting
 * and dispatch, read from the records that own each kind of work. Every task gets the same shape --
 * priority, who has it, when it is due, what it waits for -- and assignments are kept as history.
 */
class TaskBoard
{
    public const TYPES = ['RECEIVE', 'PUTAWAY', 'REPLENISH', 'PICK', 'PACK', 'COUNT', 'DISPATCH'];

    public const PRIORITIES = ['URGENT', 'HIGH', 'MEDIUM', 'LOW'];

    /** @return list<array<string,mixed>> */
    public function queue(FinWarehouse $warehouse, ?string $type = null, ?int $userId = null): array
    {
        $tasks = [];
        $now = now();
        $wid = $warehouse->id;
        $want = fn (string $t) => $type === null || $type === $t;

        if ($want('RECEIVE')) {
            foreach (DB::table('purchase_orders')->where('warehouse_id', $wid)->where('status', '!=', 'cancelled')->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('received_status')->orWhere('received_status', '!=', 'received'))
                ->whereNotNull('expected_delivery')->whereDate('expected_delivery', '<=', $now->copy()->addDay()->toDateString())->get(['id', 'po_number', 'expected_delivery']) as $po) {
                $tasks[] = $this->task('RECEIVE', (string) $po->id, $po->po_number, "Receive {$po->po_number}", null, null, null, 50,
                    Carbon::parse($po->expected_delivery)->endOfDay(), 'OPEN', null, []);
            }
        }
        if ($want('PUTAWAY')) {
            foreach (DB::table('wms_putaway_tasks as t')->join('fin_stock_items as i', 'i.id', '=', 't.item_id')->leftJoin('fin_stock_bins as f', 'f.id', '=', 't.from_bin_id')
                ->leftJoin('fin_stock_bins as s', 's.id', '=', 't.suggested_bin_id')->where('t.warehouse_id', $wid)->whereIn('t.status', ['OPEN', 'IN_PROGRESS'])
                ->get(['t.uuid', 't.number', 't.qty', 't.priority', 't.status', 't.assigned_to', 't.created_at', 'i.code', 'f.code as from_bin', 's.code as to_bin']) as $t) {
                $tasks[] = $this->task('PUTAWAY', $t->uuid, $t->number, "{$t->code} {$t->from_bin} → ".($t->to_bin ?? '?'), $t->code, (float) $t->qty, $t->assigned_to,
                    (int) $t->priority, Carbon::parse($t->created_at)->addHours((int) ($warehouse->policies['putaway_hours'] ?? 4)), $t->status, null, []);
            }
        }
        if ($want('REPLENISH')) {
            foreach (DB::table('wms_replenishment_tasks as t')->join('fin_stock_items as i', 'i.id', '=', 't.item_id')->leftJoin('fin_stock_bins as f', 'f.id', '=', 't.from_bin_id')
                ->leftJoin('fin_stock_bins as b', 'b.id', '=', 't.to_bin_id')->where('t.warehouse_id', $wid)->whereIn('t.status', ['OPEN', 'IN_PROGRESS'])
                ->get(['t.uuid', 't.number', 't.qty', 't.priority', 't.status', 't.created_at', 'i.code', 'f.code as from_bin', 'b.code as to_bin']) as $t) {
                $tasks[] = $this->task('REPLENISH', $t->uuid, $t->number, "{$t->code} {$t->from_bin} → {$t->to_bin}", $t->code, (float) $t->qty, null,
                    (int) $t->priority, Carbon::parse($t->created_at)->addHours(2), $t->status, null, []);
            }
        }
        if ($want('PICK')) {
            $openRepl = DB::table('wms_replenishment_tasks')->where('warehouse_id', $wid)->whereIn('status', ['OPEN', 'IN_PROGRESS'])->pluck('number', 'to_bin_id');
            foreach (DB::table('wms_pick_waves as w')->where('w.warehouse_id', $wid)->whereIn('w.status', ['OPEN', 'IN_PROGRESS'])->get(['w.id', 'w.uuid', 'w.number', 'w.strategy', 'w.status', 'w.assigned_to']) as $w) {
                $lines = DB::table('wms_pick_tasks')->where('wave_id', $w->id)->whereIn('status', ['OPEN', 'IN_PROGRESS'])->get(['bin_id', 'qty']);
                $due = DB::table('wms_orders')->whereIn('id', DB::table('wms_pick_tasks')->where('wave_id', $w->id)->select('order_id'))->min('sla_due_at');
                $waits = $lines->pluck('bin_id')->unique()->map(fn ($b) => $openRepl[$b] ?? null)->filter()->values()->map(fn ($n) => "Replenishment {$n}")->all();
                $tasks[] = $this->task('PICK', $w->uuid, $w->number, "{$lines->count()} pick(s), {$w->strategy}", null, (float) $lines->sum('qty'), $w->assigned_to,
                    50, $due ? Carbon::parse($due) : null, $w->status, null, $waits);
            }
        }
        if ($want('PACK') || $want('DISPATCH')) {
            foreach (FulfilmentOrder::query()->where('warehouse_id', $wid)->whereIn('status', ['PICKING', 'PICKED', 'PACKED'])->get() as $o) {
                $kind = $o->status === 'PACKED' ? 'DISPATCH' : 'PACK';
                if (! $want($kind)) {
                    continue;
                }
                $waits = [];
                if ($o->status === 'PICKING') {
                    $waits[] = 'Picking still open';
                }
                if ($kind === 'DISPATCH' && ($why = app(WarehouseQuality::class)->dispatchRefusal($o))) {
                    $waits[] = $why;
                }
                $tasks[] = $this->task($kind, $o->uuid, $o->number, ($kind === 'PACK' ? 'Pack ' : 'Dispatch ').$o->number.' · '.($o->customer_name ?? ''), null, null, null,
                    (int) ($o->priority ?? 50), $o->sla_due_at, 'OPEN', null, $waits);
            }
        }
        if ($want('COUNT')) {
            foreach (DB::table('wms_counts')->where('warehouse_id', $wid)->whereIn('status', ['OPEN', 'COUNTING'])->get(['id', 'uuid', 'number', 'reason', 'deadline_at', 'status']) as $c) {
                $waiting = DB::table('wms_count_lines')->where('count_id', $c->id)->whereIn('status', ['OPEN', 'RECOUNT'])->count();
                $tasks[] = $this->task('COUNT', $c->uuid, $c->number, ($c->reason ?? 'Count')." · {$waiting} line(s)", null, (float) $waiting, null, 50,
                    $c->deadline_at ? Carbon::parse($c->deadline_at) : null, $c->status === 'COUNTING' ? 'IN_PROGRESS' : 'OPEN', null, []);
            }
        }

        // Overlay what people have decided: who has it, its priority, its deadline.
        $assigned = DB::table('wms_task_assignments')->where('is_current', true)->get()->keyBy(fn ($a) => $a->task_type.'|'.$a->task_ref);
        $names = DB::table('users')->whereIn('id', collect($tasks)->pluck('assigned_to')->merge($assigned->pluck('user_id'))->filter()->unique())->pluck('email', 'id');
        $out = [];
        foreach ($tasks as $t) {
            if ($a = $assigned[$t['type'].'|'.$t['ref']] ?? null) {
                $t['assigned_to'] = $a->user_id;
                $t['priority'] = $a->priority ?? $t['priority'];
                $t['due_at'] = $a->due_at ? Carbon::parse($a->due_at)->toIso8601String() : $t['due_at'];
            }
            $t['assignee'] = $t['assigned_to'] ? ($names[$t['assigned_to']] ?? null) : null;
            $t['overdue'] = $t['due_at'] !== null && Carbon::parse($t['due_at'])->isPast();
            if ($t['overdue'] && $t['priority'] !== 'URGENT') {
                $t['priority'] = 'URGENT';
            }
            if ($t['waits_for'] !== []) {
                $t['state'] = 'BLOCKED';
            }
            if ($userId === null || (int) $t['assigned_to'] === $userId) {
                $out[] = $t;
            }
        }

        return collect($out)->sortBy([fn ($a, $b) => array_search($a['priority'], self::PRIORITIES) <=> array_search($b['priority'], self::PRIORITIES),
            fn ($a, $b) => strcmp((string) ($a['due_at'] ?? '9999'), (string) ($b['due_at'] ?? '9999'))])->values()->all();
    }

    /** Give a task to somebody (or take it back), with a priority and deadline; the old assignment stays as history. */
    public function assign(string $type, string $ref, ?User $to, ?string $priority, ?string $dueAt, User $by, ?string $note = null): void
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new RuntimeException("Unknown task type {$type}.");
        }
        if ($priority !== null && ! in_array($priority, self::PRIORITIES, true)) {
            throw new RuntimeException("Unknown priority {$priority}.");
        }
        if ($to && $to->company_id !== $by->company_id && $by->company_id !== null) {
            throw new RuntimeException('Tasks are given to people in this company.');
        }
        DB::transaction(function () use ($type, $ref, $to, $priority, $dueAt, $by, $note) {
            $companyId = $by->company_id ?? $to?->company_id;
            DB::table('wms_task_assignments')->where('task_type', $type)->where('task_ref', $ref)->where('is_current', true)->update(['is_current' => false]);
            DB::table('wms_task_assignments')->insert(['company_id' => $companyId, 'task_type' => $type, 'task_ref' => $ref, 'user_id' => $to?->id,
                'priority' => $priority, 'due_at' => $dueAt, 'assigned_by' => $by->id, 'is_current' => true, 'note' => $note, 'created_at' => now()]);
            // Where the task's own record has an owner, keep it the same person.
            match ($type) {
                'PUTAWAY' => DB::table('wms_putaway_tasks')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $ref)->update(['assigned_to' => $to?->id]),
                'PICK' => DB::table('wms_pick_waves')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $ref)->update(['assigned_to' => $to?->id]),
                default => null,
            };
        });
    }

    /** @return list<array<string,mixed>> */
    public function history(string $type, string $ref): array
    {
        return DB::table('wms_task_assignments as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->leftJoin('users as b', 'b.id', '=', 'a.assigned_by')
            ->where('a.task_type', $type)->where('a.task_ref', $ref)->orderBy('a.id')
            ->get(['u.email as to', 'b.email as by', 'a.priority', 'a.due_at', 'a.note', 'a.created_at'])->map(fn ($r) => (array) $r)->all();
    }

    private function task(string $type, string $ref, string $number, string $title, ?string $item, ?float $qty, ?int $assignedTo, int $score, $due, string $state, ?string $note, array $waits): array
    {
        $priority = match (true) {
            $score >= 80 => 'URGENT', $score >= 60 => 'HIGH', $score >= 40 => 'MEDIUM', default => 'LOW',
        };
        if ($due && Carbon::parse($due)->lt(now()->addHour()) && $priority !== 'URGENT') {
            $priority = 'HIGH';
        }

        return ['type' => $type, 'ref' => $ref, 'number' => $number, 'title' => $title, 'item' => $item, 'qty' => $qty, 'assigned_to' => $assignedTo,
            'priority' => $priority, 'due_at' => $due ? Carbon::parse($due)->toIso8601String() : null, 'state' => $state, 'note' => $note, 'waits_for' => $waits];
    }
}
