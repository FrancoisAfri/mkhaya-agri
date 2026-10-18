<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\WarehouseManagement\Notifications\WarehouseAlert;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse notifications (WMS-190..192): who is told, how often, and whether it reached them.
 *
 * Every alert is an occurrence with a stable key (the order, lot, task or message it is about).
 * The same unresolved key is not sent again to the same person inside the rule's quiet period --
 * it is counted -- and is reminded after it. When the key stops occurring, its log rows are marked
 * resolved; nothing here changes the order, lot or task itself. An alert still unresolved after the
 * rule's escalation time goes once to the escalation recipients. Each channel's delivery is
 * recorded; a failed channel is retried with back-off.
 *
 * Detectors run on a schedule; places that already know something happened (an order turning at
 * risk, a lot crossing an expiry point, a receipt with discrepancies) raise it at once.
 */
class WarehouseNotifier
{
    public const MAX_ATTEMPTS = 5;

    /** event => [default recipient permissions, default thresholds, escalation hours, escalate to] */
    public const EVENTS = [
        'LATE_DELIVERY' => [[RoleCatalog::RECEIVE], ['days_late' => 1], 72, [RoleCatalog::SUPERVISE]],
        'LOW_STOCK' => [[RoleCatalog::PLAN], ['days_short' => 0], null, null],
        'EXPIRY' => [[RoleCatalog::SUPERVISE], [], null, null],
        'RECALL' => [[RoleCatalog::RECALLS], [], 24, [RoleCatalog::SUPERVISE]],
        'TASK_FAILED' => [[RoleCatalog::SUPERVISE], [], 8, [RoleCatalog::MANAGE_WAREHOUSES]],
        'DISCREPANCY' => [[RoleCatalog::APPROVE_RECEIVING_EXCEPTIONS], ['count_value' => 0], 72, [RoleCatalog::SUPERVISE]],
        'APPROVAL' => [[], [], null, null],
        'ORDER_AT_RISK' => [[RoleCatalog::SUPERVISE], [], 2, [RoleCatalog::MANAGE_ORDERS]],
        'INVESTIGATION' => [[RoleCatalog::INVESTIGATE], ['open_days' => 14], 168, [RoleCatalog::MANAGE_PROTECTION]],
        'INTEGRATION' => [[RoleCatalog::INTEGRATIONS], [], 24, [RoleCatalog::SUPERVISE]],
        'OVERRIDE' => [[RoleCatalog::MANAGE_WAREHOUSES], [], 24, [RoleCatalog::MANAGE_WAREHOUSES]],
    ];

    public function rule(int $companyId, string $event, ?int $warehouseId = null): object
    {
        $row = DB::table('wms_notification_rules')->where('company_id', $companyId)->where('event_type', $event)
            ->where(fn ($q) => $q->whereNull('warehouse_id')->when($warehouseId, fn ($w) => $w->orWhere('warehouse_id', $warehouseId)))
            ->orderByRaw('warehouse_id IS NULL')->first();
        [$perms, $thresholds, $escalate, $escalateTo] = self::EVENTS[$event];

        return (object) [
            'id' => $row->id ?? null, 'is_active' => $row ? (bool) $row->is_active : true,
            'recipient_permissions' => $row ? json_decode($row->recipient_permissions, true) : $perms,
            'recipient_user_ids' => $row && $row->recipient_user_ids ? json_decode($row->recipient_user_ids, true) : [],
            'notify_responsible' => $row ? (bool) $row->notify_responsible : true,
            'channels' => $row ? json_decode($row->channels, true) : ['database'],
            'thresholds' => array_merge($thresholds, $row && $row->thresholds ? json_decode($row->thresholds, true) : []),
            'suppress_hours' => $row ? (int) $row->suppress_hours : 24,
            'escalate_after_hours' => $row ? ($row->escalate_after_hours !== null ? (int) $row->escalate_after_hours : null) : $escalate,
            'escalate_to_permissions' => $row && $row->escalate_to_permissions ? json_decode($row->escalate_to_permissions, true) : ($escalateTo ?? []),
        ];
    }

    /** Raise occurrences now (no sweep: other open alerts of the event are left alone). */
    public function raise(int $companyId, string $event, array $occurrences): int
    {
        return $this->process($companyId, $event, $occurrences, false);
    }

    /** The scheduled pass: detect, notify, resolve what went away, escalate, retry. */
    public function run(int $companyId): array
    {
        $done = [];
        foreach (array_keys(self::EVENTS) as $event) {
            $done[$event] = $this->process($companyId, $event, $this->detect($companyId, $event), true);
        }
        $done['escalated'] = $this->escalate($companyId);
        $done['retried'] = $this->retry($companyId);

        return $done;
    }

    /**
     * @param  list<array{key: string, warehouse_id: ?int, title: string, message: string, url: ?string, responsible?: list<int>, recipients?: array<int,string>}>  $occurrences
     */
    private function process(int $companyId, string $event, array $occurrences, bool $sweep): int
    {
        $sent = 0;
        foreach ($occurrences as $o) {
            $rule = $this->rule($companyId, $event, $o['warehouse_id'] ?? null);
            if (! $rule->is_active) {
                continue;
            }
            foreach ($this->recipients($companyId, $rule, $o) as $userId => $reason) {
                $sent += $this->notice($companyId, $event, $rule, $o, (int) $userId, $reason, 0) ? 1 : 0;
            }
        }
        if ($sweep) {
            DB::table('wms_notification_log')->where('company_id', $companyId)->where('event_type', $event)->whereNull('resolved_at')
                ->whereNotIn('dedupe_key', array_column($occurrences, 'key'))->update(['resolved_at' => now(), 'updated_at' => now()]);
        }

        return $sent;
    }

    /** @return array<int,string> user id => why they are told */
    private function recipients(int $companyId, object $rule, array $o): array
    {
        if (isset($o['recipients'])) {
            return $o['recipients'];
        }
        $out = [];
        foreach ($rule->recipient_permissions as $perm) {
            foreach ($this->holders($companyId, $perm) as $id) {
                $out[$id] ??= 'PERMISSION';
            }
        }
        foreach ($rule->recipient_user_ids as $id) {
            $out[(int) $id] = 'NAMED';
        }
        if ($rule->notify_responsible) {
            foreach ($o['responsible'] ?? [] as $id) {
                if ($id) {
                    $out[(int) $id] = 'RESPONSIBLE';
                }
            }
        }

        // Only active people of this company.
        $live = User::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('status', 1)->whereIn('id', array_keys($out))->pluck('id')->all();

        return array_intersect_key($out, array_flip($live));
    }

    /** @return list<int> */
    public function holders(int $companyId, string $permission): array
    {
        try {
            return User::query()->withoutGlobalScopes()->where('company_id', $companyId)->where('status', 1)->permission($permission)->pluck('users.id')->all();
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist) {
            return [];
        }
    }

    private function notice(int $companyId, string $event, object $rule, array $o, int $userId, string $reason, int $level): bool
    {
        $open = DB::table('wms_notification_log')->where('company_id', $companyId)->where('dedupe_key', $o['key'])->where('recipient_id', $userId)
            ->where('level', $level)->whereNull('resolved_at')->orderByDesc('id')->first();
        if ($open && $open->sent_at && Carbon::parse($open->sent_at)->gt(now()->subHours(max(1, $rule->suppress_hours)))) {
            DB::table('wms_notification_log')->where('id', $open->id)->update(['occurrences' => $open->occurrences + 1, 'last_seen_at' => now(), 'updated_at' => now()]);

            return false;
        }
        $id = $open?->id;
        if ($open) {
            DB::table('wms_notification_log')->where('id', $id)->update(['occurrences' => $open->occurrences + 1, 'last_seen_at' => now(), 'title' => $o['title'],
                'message' => $o['message'], 'delivery' => json_encode(array_fill_keys($rule->channels, 'PENDING')), 'updated_at' => now()]);
        } else {
            $id = DB::table('wms_notification_log')->insertGetId(['company_id' => $companyId, 'rule_id' => $rule->id, 'event_type' => $event, 'dedupe_key' => $o['key'],
                'warehouse_id' => $o['warehouse_id'] ?? null, 'recipient_id' => $userId, 'level' => $level, 'reason' => $reason, 'title' => mb_substr($o['title'], 0, 200),
                'message' => $o['message'], 'url' => $o['url'] ?? null, 'delivery' => json_encode(array_fill_keys($rule->channels, 'PENDING')),
                'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->deliver(DB::table('wms_notification_log')->where('id', $id)->first());

        return true;
    }

    /** Send each channel still pending or failed, and record the outcome per channel. */
    private function deliver(object $row): void
    {
        $user = User::query()->withoutGlobalScopes()->find($row->recipient_id);
        $delivery = json_decode($row->delivery, true);
        $error = null;
        foreach ($delivery as $channel => $state) {
            if ($state === 'SENT') {
                continue;
            }
            try {
                $user?->notifyNow(new WarehouseAlert($row->event_type, $row->title, $row->message, $row->url, [$channel], $row->level > 0));
                $delivery[$channel] = 'SENT';
            } catch (\Throwable $e) {
                $delivery[$channel] = 'FAILED';
                $error = mb_substr($e->getMessage(), 0, 500);
            }
        }
        $failed = in_array('FAILED', $delivery, true);
        $attempts = $row->attempts + 1;
        DB::table('wms_notification_log')->where('id', $row->id)->update(['delivery' => json_encode($delivery), 'attempts' => $attempts, 'last_error' => $error,
            'sent_at' => in_array('SENT', $delivery, true) ? now() : $row->sent_at,
            'next_attempt_at' => $failed && $attempts < self::MAX_ATTEMPTS ? now()->addMinutes(5 * 2 ** ($attempts - 1)) : null, 'updated_at' => now()]);
    }

    public function retry(int $companyId): int
    {
        $rows = DB::table('wms_notification_log')->where('company_id', $companyId)->whereNull('resolved_at')->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())->where('attempts', '<', self::MAX_ATTEMPTS)->limit(200)->get();
        foreach ($rows as $row) {
            $this->deliver($row);
        }

        return $rows->count();
    }

    /** Alerts still unresolved past their rule's escalation time go once to the escalation recipients. */
    public function escalate(int $companyId): int
    {
        $n = 0;
        $open = DB::table('wms_notification_log')->where('company_id', $companyId)->whereNull('resolved_at')->where('level', 0)
            ->select('event_type', 'dedupe_key', 'warehouse_id', DB::raw('MIN(first_seen_at) AS since'), DB::raw('MAX(title) AS title'), DB::raw('MAX(message) AS message'), DB::raw('MAX(url) AS url'))
            ->groupBy('event_type', 'dedupe_key', 'warehouse_id')->get();
        foreach ($open as $o) {
            $rule = $this->rule($companyId, $o->event_type, $o->warehouse_id ? (int) $o->warehouse_id : null);
            if (! $rule->is_active || $rule->escalate_after_hours === null || Carbon::parse($o->since)->gt(now()->subHours($rule->escalate_after_hours))) {
                continue;
            }
            $occ = ['key' => $o->dedupe_key, 'warehouse_id' => $o->warehouse_id, 'title' => $o->title, 'url' => $o->url,
                'message' => $o->message.' Unresolved for '.Carbon::parse($o->since)->diffForHumans(now(), true).'.'];
            foreach ($rule->escalate_to_permissions as $perm) {
                foreach ($this->holders($companyId, $perm) as $uid) {
                    $exists = DB::table('wms_notification_log')->where('company_id', $companyId)->where('dedupe_key', $o->dedupe_key)->where('recipient_id', $uid)->where('level', 1)->exists();
                    $n += ! $exists && $this->notice($companyId, $o->event_type, $rule, $occ, $uid, 'ESCALATION', 1) ? 1 : 0;
                }
            }
        }

        return $n;
    }

    /** @return list<array> the event's open occurrences now */
    public function detect(int $companyId, string $event): array
    {
        $t = fn (string $table) => DB::table($table)->where("{$table}.company_id", $companyId);
        $rule = $this->rule($companyId, $event);

        return match ($event) {
            'LATE_DELIVERY' => DB::table('purchase_orders as o')->leftJoin('suppliers as s', 's.id', '=', 'o.supplier_id')->where('o.company_id', $companyId)->whereNull('o.deleted_at')
                ->whereIn('o.status', Receiving::RECEIVABLE)->whereDate('o.expected_delivery', '<=', now()->subDays((int) ($rule->thresholds['days_late'] ?? 1))->toDateString())
                ->whereExists(fn ($q) => $q->from('purchase_order_items as i')->whereColumn('i.purchase_order_id', 'o.id')->whereNotNull('i.stock_item_id')->whereRaw('i.quantity > COALESCE(i.received_qty, 0)'))
                ->limit(200)->get(['o.id', 'o.po_number', 'o.expected_delivery', 'o.created_by', 's.company_name',
                    DB::raw('EXISTS (SELECT 1 FROM purchase_order_items x WHERE x.purchase_order_id = o.id AND COALESCE(x.received_qty, 0) > 0) AS partly')])
                ->map(fn ($p) => ['key' => "PO:{$p->id}", 'warehouse_id' => null, 'responsible' => [$p->created_by],
                    'title' => "{$p->po_number} from ".($p->company_name ?? 'a supplier').' is late', 'url' => '/warehouse/inbound/expected?overdue=1',
                    'message' => ($p->partly ? 'Partly received; the rest' : 'Not received;').' was due '.Carbon::parse($p->expected_delivery)->toFormattedDateString().'.'])->all(),
            'LOW_STOCK' => collect(app(PurchasingAdvisor::class)->recommendations())->map(function ($r) use ($rule) {
                $lead = (float) (collect($r['inputs'])->firstWhere('label', 'Lead time (days)')['value'] ?? 0);
                $short = $r['days_of_cover'] === null ? null : $lead - $r['days_of_cover'];

                return $short !== null && $short > (float) ($rule->thresholds['days_short'] ?? 0)
                    ? ['key' => "ITEM:{$r['item_id']}", 'warehouse_id' => null, 'title' => "{$r['code']} will run short before more can arrive",
                        'message' => "{$r['days_of_cover']} days of cover against a {$lead}-day lead time. Suggested order: {$r['suggested_qty']} {$r['uom']}.", 'url' => '/warehouse/planning/buying'] : null;
            })->filter()->values()->all(),
            'EXPIRY' => $t('wms_expiry_alerts')->join('fin_stock_lots as l', 'l.id', '=', 'wms_expiry_alerts.lot_id')->join('fin_stock_items as i', 'i.id', '=', 'l.item_id')
                ->join('fin_warehouses as w', 'w.id', '=', 'wms_expiry_alerts.warehouse_id')
                ->whereExists(fn ($q) => $q->from('fin_stock_positions as p')->whereColumn('p.lot_id', 'wms_expiry_alerts.lot_id')->whereColumn('p.warehouse_id', 'wms_expiry_alerts.warehouse_id')->where('p.qty', '>', 0))
                ->orderByDesc('wms_expiry_alerts.id')->limit(300)->get(['wms_expiry_alerts.*', 'l.lot_number', 'l.expires_on', 'i.code', 'w.code as wh', 'w.manager_user_id'])
                ->unique(fn ($a) => $a->lot_id.':'.$a->warehouse_id)
                ->map(fn ($a) => $this->expiryOccurrence((int) $a->lot_id, (int) $a->warehouse_id, $a->code, $a->lot_number, $a->wh, (int) Carbon::now()->startOfDay()->diffInDays(Carbon::parse($a->expires_on), false),
                    (float) $a->qty, (float) $a->at_risk_qty, (float) $a->exposure, (int) $a->threshold_days, [$a->manager_user_id]))->values()->all(),
            'RECALL' => $t('mfg_recalls')->where('status', '<>', 'CLOSED')->limit(100)->get(['id', 'number', 'severity', 'reason'])
                ->map(fn ($r) => ['key' => "RECALL:{$r->id}", 'warehouse_id' => null, 'title' => "Recall {$r->number} is open".($r->severity ? " ({$r->severity})" : ''),
                    'message' => (string) ($r->reason ?? 'Stock it reaches is held until it is decided.'), 'url' => '/warehouse/recalls'])->all(),
            'TASK_FAILED' => collect()
                ->merge($t('wms_putaway_tasks')->whereIn('status', ['FAILED', 'PARTIAL'])->limit(100)->get(['id', 'number', 'warehouse_id', 'status'])
                    ->map(fn ($x) => ['key' => "PUTAWAY:{$x->id}", 'warehouse_id' => (int) $x->warehouse_id, 'title' => "Put-away {$x->number} ".strtolower($x->status),
                        'message' => 'The stock is not where it should be until somebody finishes or reassigns the task.', 'url' => '/warehouse/floor/putaway?view=exceptions']))
                ->merge($t('wms_replenishment_tasks')->where('status', 'FAILED')->limit(100)->get(['id', 'number', 'warehouse_id'])
                    ->map(fn ($x) => ['key' => "REPLEN:{$x->id}", 'warehouse_id' => (int) $x->warehouse_id, 'title' => "Replenishment {$x->number} failed",
                        'message' => 'A pick face was not topped up.', 'url' => '/warehouse/floor/replenishment']))
                ->merge($t('wms_offline_actions')->where('status', 'CONFLICT')->limit(100)->get(['id', 'kind', 'user_id'])
                    ->map(fn ($x) => ['key' => "OFFLINE:{$x->id}", 'warehouse_id' => null, 'responsible' => [$x->user_id], 'title' => 'An offline '.strtolower(str_replace('_', ' ', $x->kind)).' needs a decision',
                        'message' => 'It was recorded without a signal and no longer fits what happened since.', 'url' => '/warehouse/m/conflicts']))->values()->all(),
            'DISCREPANCY' => collect()
                ->merge($t('goods_received_notes')->where('discrepancy_status', 'OPEN')->limit(100)->get(['id', 'grn_number', 'received_by', 'warehouse_id'])
                    ->map(fn ($g) => ['key' => "GRN:{$g->id}", 'warehouse_id' => $g->warehouse_id ? (int) $g->warehouse_id : null, 'responsible' => [$g->received_by], 'title' => "Receipt {$g->grn_number} has discrepancies",
                        'message' => 'What arrived does not match what was ordered.', 'url' => '/warehouse/inbound/receipts?discrepancy=1']))
                ->merge(DB::table('wms_count_lines as l')->join('wms_counts as c', 'c.id', '=', 'l.count_id')->join('fin_stock_items as i', 'i.id', '=', 'l.item_id')
                    ->where('c.company_id', $companyId)->where('l.status', 'VARIANCE')
                    ->whereRaw('ABS(COALESCE(l.counted_qty, 0) - l.expected_qty) * COALESCE(NULLIF(i.avg_cost, 0), i.standard_cost, 0) >= ?', [(float) ($rule->thresholds['count_value'] ?? 0)])
                    ->limit(100)->get(['l.id', 'c.number', 'c.warehouse_id', 'i.code', 'l.expected_qty', 'l.counted_qty'])
                    ->map(fn ($l) => ['key' => "COUNT:{$l->id}", 'warehouse_id' => (int) $l->warehouse_id, 'title' => "Count {$l->number}: {$l->code} differs",
                        'message' => "Expected {$l->expected_qty}, counted {$l->counted_qty}.", 'url' => '/warehouse/counts']))->values()->all(),
            'APPROVAL' => $t('wms_approval_steps')->where('status', 'PENDING')->limit(200)->get()
                ->filter(fn ($s) => $this->isCurrent($s))
                ->map(fn ($s) => ['key' => "APPROVAL:{$s->subject_type}:{$s->subject_id}:{$s->level}", 'warehouse_id' => null,
                    'recipients' => array_fill_keys(array_diff(app(WarehouseApprovals::class)->deciders($companyId, $s), [(int) $s->requested_by]), 'PERMISSION'),
                    'title' => ucfirst(strtolower($s->subject_type))." {$s->subject_ref} waits for {$s->name}",
                    'message' => Carbon::parse($s->due_at)->isPast() ? 'It is overdue since '.Carbon::parse($s->due_at)->toDayDateTimeString().'.' : 'Due by '.Carbon::parse($s->due_at)->toDayDateTimeString().'.',
                    'url' => '/warehouse/approvals'])->values()->all(),
            'ORDER_AT_RISK' => $t('wms_orders')->whereIn('status', \App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder::LIVE)->whereNotNull('sla_at_risk_at')->limit(200)
                ->get(['id', 'uuid', 'number', 'warehouse_id', 'sla_due_at', 'status'])
                ->map(fn ($o) => $this->orderOccurrence((int) $o->id, $o->uuid, $o->number, (int) $o->warehouse_id, Carbon::parse($o->sla_due_at), $o->status))->all(),
            'INVESTIGATION' => $t('mfg_loss_findings')->whereNotNull('warehouse_id')->whereIn('status', \App\Modules\Operations\Manufacturing\Models\LossFinding::OPEN)
                ->where(fn ($q) => $q->whereDate('due_date', '<', now()->toDateString())->orWhere('detected_at', '<', now()->subDays((int) ($rule->thresholds['open_days'] ?? 14))))
                ->limit(100)->get(['id', 'uuid', 'number', 'warehouse_id', 'category', 'owner_id', 'due_date'])
                ->map(fn ($f) => ['key' => "FINDING:{$f->id}", 'warehouse_id' => (int) $f->warehouse_id, 'responsible' => [$f->owner_id],
                    'title' => "Investigation {$f->number} is unresolved", 'message' => $f->category.($f->due_date ? '; due '.Carbon::parse($f->due_date)->toFormattedDateString() : '').'.',
                    'url' => "/warehouse/protection/{$f->uuid}"])->all(),
            'OVERRIDE' => $t('wms_overrides')->whereNull('reviewed_at')->limit(50)->get()
                ->map(fn ($o) => ['key' => "OVERRIDE:{$o->id}", 'warehouse_id' => null,
                    'recipients' => array_fill_keys(array_diff($this->holders($companyId, RoleCatalog::MANAGE_WAREHOUSES), [(int) $o->user_id]), 'PERMISSION'),
                    'title' => 'An emergency override needs review', 'message' => "Opened ".Carbon::parse($o->starts_at)->toDayDateTimeString()." ({$o->uses} use(s)): {$o->reason}",
                    'url' => '/warehouse/approvals'])->all(),
            'INTEGRATION' => collect()
                ->merge($t('wms_outbox')->whereNotNull('failed_at')->limit(100)->get(['event_id', 'event_type', 'warehouse_id', 'last_error'])
                    ->map(fn ($e) => ['key' => "EVENT:{$e->event_id}", 'warehouse_id' => $e->warehouse_id ? (int) $e->warehouse_id : null,
                        'title' => "A {$e->event_type} event could not be delivered", 'message' => (string) $e->last_error, 'url' => '/warehouse/integrations/events?state=dead']))
                ->merge($t('mfg_integration_events')->where('event_type', 'like', 'WMS_%')->whereIn('status', ['DEAD', 'FAILED'])->limit(100)->get(['id', 'event_type', 'external_id', 'last_error'])
                    ->map(fn ($e) => ['key' => "MSG:{$e->id}", 'warehouse_id' => null, 'title' => "{$e->event_type} {$e->external_id} failed",
                        'message' => (string) $e->last_error, 'url' => '/warehouse/integrations/systems']))->values()->all(),
        };
    }

    public function expiryOccurrence(int $lotId, int $warehouseId, string $item, string $lot, string $warehouse, int $daysLeft, float $qty, float $atRisk, float $exposure, int $threshold, array $responsible): array
    {
        return ['key' => "EXPIRY:{$lotId}:{$warehouseId}:{$threshold}", 'warehouse_id' => $warehouseId, 'responsible' => $responsible,
            'title' => "{$item} lot {$lot} expires in {$daysLeft} days",
            'message' => "{$warehouse}: {$qty} on hand; {$atRisk} unlikely to be used in time (".number_format($exposure, 2).' at risk).', 'url' => '/warehouse/batches/expiry'];
    }

    public function orderOccurrence(int $id, string $uuid, string $number, int $warehouseId, Carbon $due, string $status, array $responsible = []): array
    {
        $tz = DB::table('fin_warehouses')->where('id', $warehouseId)->value('timezone') ?: 'Africa/Johannesburg';

        return ['key' => "ORDER:{$id}", 'warehouse_id' => $warehouseId, 'responsible' => $responsible,
            'title' => "Order {$number} may miss its dispatch deadline", 'message' => 'Due '.$due->timezone($tz)->format('d M H:i').'; it is '.strtolower(str_replace('_', ' ', $status)).'.',
            'url' => "/warehouse/outbound/orders/{$uuid}"];
    }

    private function isCurrent(object $step): bool
    {
        return ! DB::table('wms_approval_steps')->where('subject_type', $step->subject_type)->where('subject_id', $step->subject_id)
            ->where('level', '<', $step->level)->where('status', 'PENDING')->exists();
    }
}
