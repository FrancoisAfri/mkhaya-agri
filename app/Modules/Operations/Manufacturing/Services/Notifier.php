<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\Inspection;
use App\Modules\Operations\Manufacturing\Models\LossFinding;
use App\Modules\Operations\Manufacturing\Models\NcrAction;
use App\Modules\Operations\Manufacturing\Models\NotificationLog;
use App\Modules\Operations\Manufacturing\Models\NotificationRule;
use App\Modules\Operations\Manufacturing\Models\PlanRecommendation;
use App\Modules\Operations\Manufacturing\Models\PlanRun;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\StandardCost;
use App\Modules\Operations\Manufacturing\Notifications\ManufacturingAlert;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog as R;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manufacturing alerts (MFG-020).
 *
 * Each run looks at the module's records for every kind of event, routes what it finds through the
 * company's rule for that event -- to permission holders, role holders, named people and whoever
 * is responsible for the thing -- confined to people who may see the factory concerned, and logs
 * one line per person. An alert already sent inside the rule's quiet period is counted, not sent
 * again; more than DIGEST_AFTER new alerts of one kind for one person arrive as one summary; an
 * alert still standing past the rule's escalation time goes once more to its escalation
 * permissions. When the condition clears, its log lines are marked resolved.
 */
class Notifier
{
    public const DIGEST_AFTER = 3;

    /** event => [recipient permissions, lead hours, escalate after hours, escalate to permissions, meaning] */
    public const EVENTS = [
        'MATERIAL_SHORTAGE' => [[R::MANAGE_PLANNING, R::ISSUE_MATERIAL], 0, 24, [R::APPROVE_ORDERS], 'Approved or released orders short of material'],
        'SUPPLIER_DELIVERY_LATE' => [[R::MANAGE_PLANNING], 0, 48, [R::APPROVE_PLAN], 'Purchase orders for production material past their expected delivery'],
        'ORDER_DEADLINE' => [[R::MANAGE_ORDERS], 48, 24, [R::APPROVE_ORDERS], 'Open orders due within the lead hours, or overdue'],
        'OPERATION_STALLED' => [[R::SUPERVISE_FLOOR], 4, 4, [R::MANAGE_ORDERS], 'Operations running or paused with no floor activity for the lead hours'],
        'QUALITY_FAILURE' => [[R::DISPOSE, R::MANAGE_NCR], 0, 24, [R::APPROVE_ORDERS], 'Failed inspections waiting for a decision'],
        'QUARANTINED_STOCK' => [[R::DISPOSE], 24, 48, [R::MANAGE_QUALITY_PLANS], 'Stock held in quarantine longer than the lead hours'],
        'ABNORMAL_VARIANCE' => [[R::INVESTIGATE_LOSS, R::MANAGE_COSTS], 0, 72, [R::APPROVE_LOSS], 'New scrap, material and cost-overrun loss findings'],
        'MACHINE_BREAKDOWN' => [[R::MANAGE_MAINTENANCE, R::SUPERVISE_FLOOR], 0, 2, [R::RELEASE_MACHINE], 'Breakdowns still open'],
        'MAINTENANCE_OVERDUE' => [[R::MANAGE_MAINTENANCE], 0, 72, [R::RELEASE_MACHINE], 'Maintenance work orders on production machines past their date'],
        'QUALIFICATION_EXPIRING' => [[R::MANAGE_LABOUR], 720, null, null, 'Rostered operators whose qualifications expire within the lead hours, or are missing'],
        'APPROVAL_PENDING' => [[R::APPROVE_ORDERS, R::APPROVE_PLAN, R::APPROVE_COSTS, R::APPROVE_LOSS], 8, 48, null, 'Orders, plans, standard costs and loss findings waiting for approval'],
        'CORRECTIVE_ACTION_DUE' => [[R::MANAGE_NCR, R::INVESTIGATE_LOSS], 48, 24, [R::APPROVE_LOSS], 'Corrective actions and loss investigations due within the lead hours, or overdue'],
    ];

    private int $companyId;

    private array $permissionHolders = [];

    public function __construct(private readonly FactoryAccess $access) {}

    public function rule(int $companyId, string $event): NotificationRule
    {
        [$perms, $lead, $escalate, $to] = self::EVENTS[$event];

        return NotificationRule::query()->whereNull('factory_id')->firstOrCreate(['company_id' => $companyId, 'event_type' => $event, 'factory_id' => null], [
            'is_active' => true, 'recipient_permissions' => $perms, 'notify_responsible' => true, 'channels' => ['database'],
            'lead_hours' => $lead, 'suppress_hours' => 24, 'escalate_after_hours' => $escalate, 'escalate_to_permissions' => $to,
        ]);
    }

    /** @return array{sent: int, suppressed: int, escalated: int, resolved: int} */
    public function run(int $companyId): array
    {
        $this->companyId = $companyId;
        $this->permissionHolders = [];
        $stats = ['sent' => 0, 'suppressed' => 0, 'escalated' => 0, 'resolved' => 0];
        $outbox = [];   // recipient => event => list of [log, rule]
        $standing = []; // every dedupe key still true this run

        foreach (array_keys(self::EVENTS) as $event) {
            $this->rule($companyId, $event);   // make sure the default exists
            $rules = NotificationRule::query()->where('event_type', $event)->where('is_active', true)->get();
            if ($rules->isEmpty()) {
                continue;
            }
            foreach ($this->candidates($event, $rules->firstWhere('factory_id', null) ?? $rules->first()) as $c) {
                $key = "{$event}:{$c['key']}";
                $standing[$key] = true;
                // A factory's own rule takes precedence over the all-factories rule.
                $rule = ($c['factory_id'] ?? null) ? ($rules->firstWhere('factory_id', $c['factory_id']) ?? $rules->firstWhere('factory_id', null)) : $rules->firstWhere('factory_id', null);
                if (! $rule) {
                    continue;
                }
                foreach ($this->recipients($rule, $c) as $userId => $reason) {
                    $log = NotificationLog::query()->where('dedupe_key', $key)->where('recipient_id', $userId)->where('level', 0)
                        ->whereNull('resolved_at')->latest('id')->first();
                    if ($log && $log->last_seen_at->gt(now()->subHours($rule->suppress_hours))) {
                        $log->update(['occurrences' => $log->occurrences + 1, 'last_seen_at' => now(), 'title' => $c['title'], 'message' => $c['message']]);
                        $stats['suppressed']++;
                        $this->maybeEscalate($log, $rule, $c, $stats);

                        continue;
                    }
                    $log = NotificationLog::create(['company_id' => $companyId, 'rule_id' => $rule->id, 'event_type' => $event, 'dedupe_key' => $key,
                        'subject_type' => $c['subject_type'] ?? null, 'subject_id' => $c['subject_id'] ?? null, 'reference' => $c['reference'] ?? null,
                        'factory_id' => $c['factory_id'] ?? null, 'recipient_id' => $userId, 'level' => 0, 'reason' => $reason,
                        'title' => $c['title'], 'message' => $c['message'], 'url' => $c['url'] ?? null, 'channels' => $rule->channels,
                        'first_seen_at' => $log?->first_seen_at ?? now(), 'last_seen_at' => now()]);
                    $outbox[$userId][$event][] = [$log, $rule];
                    // A reminder after the quiet period still counts from when it was first seen.
                    $this->maybeEscalate($log, $rule, $c, $stats);
                }
            }
        }

        $stats['sent'] += $this->deliver($outbox);
        $stats['resolved'] = NotificationLog::query()->whereNull('resolved_at')
            ->get(['id', 'dedupe_key'])->reject(fn ($l) => isset($standing[$l->dedupe_key]))
            ->each(fn ($l) => NotificationLog::query()->whereKey($l->id)->update(['resolved_at' => now()]))->count();

        return $stats;
    }

    // ── What is happening ────────────────────────────────────────────────────────────────────

    /** @return iterable<array{key: string, title: string, message: string, url?: string, factory_id?: ?int, responsible?: list<int>, requires?: ?string}> */
    private function candidates(string $event, NotificationRule $rule): iterable
    {
        $lead = (int) $rule->lead_hours;
        $lifecycle = app(ProductionOrderLifecycle::class);

        switch ($event) {
            case 'MATERIAL_SHORTAGE':
                foreach (PO::query()->whereIn('status', [PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION])->get() as $o) {
                    if ($short = $lifecycle->shortages($o)) {
                        yield $this->order($o, 'shortage', "{$o->number} is short of material",
                            'Short: '.collect($short)->map(fn ($s) => "{$s['item']} ({$this->num($s['short'])})")->implode(', ').'.');
                    }
                }
                break;

            case 'SUPPLIER_DELIVERY_LATE':
                if (! Schema::hasColumn('purchase_order_items', 'stock_item_id')) {
                    break;
                }
                $used = DB::table('mfg_bom_lines')->distinct()->pluck('component_stock_item_id')->all();
                $late = DB::table('purchase_orders as p')->join('purchase_order_items as i', 'i.purchase_order_id', '=', 'p.id')
                    ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
                    ->where('p.company_id', $this->companyId)->whereNull('p.deleted_at')->whereIn('i.stock_item_id', $used)
                    ->whereDate('p.expected_delivery', '<', today())->whereNotIn('p.status', ['cancelled', 'closed', 'draft', 'rejected'])
                    ->where(fn ($q) => $q->whereNull('p.received_status')->orWhereNotIn('p.received_status', ['received', 'complete', 'completed', 'full']))
                    ->select('p.id', 'p.uuid', 'p.po_number', 'p.expected_delivery', 's.company_name')->distinct()->get();
                foreach ($late as $p) {
                    yield ['key' => "PO:{$p->id}", 'subject_type' => 'PURCHASE_ORDER', 'subject_id' => $p->id, 'reference' => $p->po_number,
                        'title' => "{$p->po_number} from {$p->company_name} is late",
                        'message' => "Expected {$p->expected_delivery}; it carries material production needs.", 'url' => "/purchase-orders/{$p->uuid}"];
                }
                break;

            case 'ORDER_DEADLINE':
                foreach (PO::query()->whereIn('status', [PO::PLANNED, PO::APPROVED, PO::RELEASED, PO::IN_PRODUCTION, PO::QUALITY_REVIEW, PO::ON_HOLD])
                    ->whereDate('due_date', '<=', now()->addHours($lead)->toDateString())->get() as $o) {
                    $overdue = $o->due_date->lt(today());
                    yield $this->order($o, 'deadline', $overdue ? "{$o->number} is overdue" : "{$o->number} is due {$o->due_date->toDateString()}",
                        sprintf('%s of %s made; status %s.', $this->num((float) $o->qty_completed), $this->num((float) $o->planned_qty), strtolower(str_replace('_', ' ', $o->status))));
                }
                break;

            case 'OPERATION_STALLED':
                $ops = Op::query()->whereIn('status', [Op::IN_PROGRESS, Op::PAUSED])->with('order')->get();
                foreach ($ops as $op) {
                    $last = DB::table('mfg_operation_time_logs')->where('order_operation_id', $op->id)->selectRaw('MAX(COALESCE(ended_at, started_at)) AS t')->value('t');
                    $since = Carbon::parse($last ?? $op->started_at ?? $op->updated_at);
                    $late = $op->sched_end && $op->sched_end->lt(today());
                    if ($since->gt(now()->subHours($lead)) && ! $late) {
                        continue;
                    }
                    yield ['key' => "OP:{$op->id}", 'subject_type' => 'OPERATION', 'subject_id' => $op->id, 'reference' => $op->order?->number,
                        'factory_id' => $op->order?->factory_id, 'responsible' => array_filter([$op->assigned_to, $op->order?->supervisor_id]),
                        'title' => "{$op->order?->number} · {$op->sequence} {$op->name} has stalled",
                        'message' => $late ? "Scheduled to finish {$op->sched_end->toDateString()}; still ".strtolower(str_replace('_', ' ', $op->status)).'.'
                            : 'No floor activity since '.$since->format('Y-m-d H:i').'.',
                        'url' => $op->order ? "/manufacturing/production/orders/{$op->order->uuid}" : null];
                }
                break;

            case 'QUALITY_FAILURE':
                foreach (Inspection::query()->where('result', Inspection::FAILED)->where('status', '!=', Inspection::CLOSED)->with('order')->get() as $i) {
                    yield ['key' => "QI:{$i->id}", 'subject_type' => 'INSPECTION', 'subject_id' => $i->id, 'reference' => $i->number,
                        'factory_id' => $i->order?->factory_id, 'responsible' => array_filter([$i->order?->supervisor_id]),
                        'title' => "Inspection {$i->number} failed", 'message' => 'Waiting for a decision on '.$this->num((float) $i->qty).' held.',
                        'url' => "/manufacturing/quality/inspections/{$i->uuid}"];
                }
                break;

            case 'QUARANTINED_STOCK':
                $held = DB::table('fin_stock_positions as p')->join('fin_stock_items as i', 'i.id', '=', 'p.item_id')
                    ->leftJoin('fin_stock_lots as l', 'l.id', '=', 'p.lot_id')->where('p.company_id', $this->companyId)
                    ->where('p.status', 'QUARANTINE')->where('p.qty', '>', 0)->where('p.updated_at', '<', now()->subHours($lead))
                    ->get(['p.id', 'p.qty', 'p.updated_at', 'i.code', 'l.lot_number']);
                foreach ($held as $h) {
                    $what = trim("{$h->code} ".($h->lot_number ? "lot {$h->lot_number}" : ''));
                    yield ['key' => "POS:{$h->id}", 'subject_type' => 'STOCK_POSITION', 'subject_id' => $h->id, 'reference' => $what,
                        'title' => "{$what} is still in quarantine", 'message' => $this->num((float) $h->qty).' held since '.Carbon::parse($h->updated_at)->format('Y-m-d H:i').'.',
                        'url' => '/manufacturing/quality/inspections'];
                }
                break;

            case 'ABNORMAL_VARIANCE':
                foreach (LossFinding::query()->where('status', LossFinding::DETECTED)
                    ->whereIn('category', ['HIGH_SCRAP', 'EXCESS_MATERIAL', 'OVER_STANDARD', 'REPEATED_REWORK'])->get() as $f) {
                    yield ['key' => "LP:{$f->id}", 'subject_type' => 'LOSS_FINDING', 'subject_id' => $f->id, 'reference' => $f->number,
                        'factory_id' => $f->factory_id, 'responsible' => array_filter([$f->owner_id]),
                        'title' => "{$f->number}: {$f->measure} on {$f->subject_label}",
                        'message' => sprintf('%s against %s; estimated %s.', $this->num($f->actual_value), $this->num((float) $f->baseline_value), number_format($f->estimated_impact, 2)),
                        'url' => "/manufacturing/loss/findings/{$f->uuid}"];
                }
                break;

            case 'MACHINE_BREAKDOWN':
                foreach (DowntimeEvent::query()->whereNull('ended_at')->with(['reason', 'workCentre'])->get()
                    ->filter(fn ($e) => $e->reason?->category === 'BREAKDOWN') as $e) {
                    yield ['key' => "DT:{$e->id}", 'subject_type' => 'DOWNTIME', 'subject_id' => $e->id, 'reference' => $e->workCentre?->code,
                        'factory_id' => $e->factory_id, 'title' => "{$e->workCentre?->code} is broken down",
                        'message' => 'Down since '.$e->started_at->format('Y-m-d H:i').($e->notes ? ": {$e->notes}" : '.'), 'url' => '/manufacturing/floor/downtime'];
                }
                break;

            case 'MAINTENANCE_OVERDUE':
                foreach (app(Maintenance::class)->overdue() as $w) {
                    yield ['key' => "WO:{$w->id}", 'subject_type' => 'WORK_ORDER', 'subject_id' => $w->id, 'reference' => $w->work_order_number ?? (string) $w->id,
                        'title' => 'Maintenance overdue on '.($w->asset?->name ?? 'a machine'),
                        'message' => ($w->work_order_number ?? 'Work order').' was due '.Carbon::parse($w->scheduled_for)->toDateString().'.',
                        'url' => '/manufacturing/maintenance/machines'];
                }
                break;

            case 'QUALIFICATION_EXPIRING':
                foreach (app(Workforce::class)->trainingNeeds(max(1, (int) ceil($lead / 24))) as $n) {
                    yield ['key' => 'Q:'.$n['user_id'].':'.md5($n['requirement']), 'subject_type' => 'USER', 'subject_id' => $n['user_id'],
                        'reference' => $n['requirement'], 'responsible' => [$n['user_id']],
                        'title' => "{$n['requirement']}: ".strtolower($n['status'])." for {$n['person']}",
                        'message' => $n['status'] === 'EXPIRING' ? "Expires {$n['expires_on']}; needed at {$n['work_centre']}." : "Needed at {$n['work_centre']}.",
                        'url' => '/manufacturing/workforce/qualifications'];
                }
                break;

            case 'APPROVAL_PENDING':
                $before = now()->subHours($lead);
                foreach (PO::query()->where('status', PO::PLANNED)->where('updated_at', '<', $before)->get() as $o) {
                    yield $this->order($o, 'approval', "{$o->number} is waiting for approval", 'Planned and waiting since '.$o->updated_at->format('Y-m-d H:i').'.') + ['requires' => R::APPROVE_ORDERS];
                }
                foreach (PlanRun::query()->where('status', 'OPEN')->where('created_at', '<', $before)->get() as $run) {
                    $n = PlanRecommendation::query()->where('run_id', $run->id)->where('status', PlanRecommendation::PROPOSED)->count();
                    if ($n > 0) {
                        yield ['key' => "MRP:{$run->id}", 'subject_type' => 'PLAN_RUN', 'subject_id' => $run->id, 'reference' => $run->number, 'requires' => R::APPROVE_PLAN,
                            'title' => "Plan {$run->number} has {$n} recommendations waiting", 'message' => 'Review and approve or reject them.', 'url' => '/manufacturing/planning/mrp'];
                    }
                }
                foreach (StandardCost::query()->where('status', StandardCost::DRAFT)->where('created_at', '<', $before)->get() as $s) {
                    yield ['key' => "STD:{$s->id}", 'subject_type' => 'STANDARD_COST', 'subject_id' => $s->id, 'reference' => "v{$s->version}", 'requires' => R::APPROVE_COSTS,
                        'title' => "Standard cost v{$s->version} is waiting for approval", 'message' => 'Unit cost '.number_format((float) $s->unit_cost, 4).'.',
                        'url' => "/manufacturing/costing/standards/{$s->uuid}"];
                }
                foreach (LossFinding::query()->where('requires_approval', true)->whereNull('approved_at')->whereIn('status', [LossFinding::VERIFIED, LossFinding::ACTIONED])->get() as $f) {
                    yield ['key' => "LPA:{$f->id}", 'subject_type' => 'LOSS_FINDING', 'subject_id' => $f->id, 'reference' => $f->number, 'requires' => R::APPROVE_LOSS,
                        'factory_id' => $f->factory_id, 'title' => "{$f->number} needs management approval", 'message' => "{$f->subject_label}: {$f->measure}.",
                        'url' => "/manufacturing/loss/findings/{$f->uuid}"];
                }
                break;

            case 'CORRECTIVE_ACTION_DUE':
                $by = now()->addHours($lead)->toDateString();
                foreach (NcrAction::query()->whereNull('completed_at')->whereNotNull('due_date')->whereDate('due_date', '<=', $by)->with('ncr')->get() as $a) {
                    yield ['key' => "NCA:{$a->id}", 'subject_type' => 'NCR_ACTION', 'subject_id' => $a->id, 'reference' => $a->ncr?->number,
                        'responsible' => array_filter([$a->owner_id, $a->ncr?->owner_id]),
                        'title' => "{$a->ncr?->number}: ".strtolower($a->type).' action due '.Carbon::parse($a->due_date)->toDateString(),
                        'message' => (string) $a->description, 'url' => $a->ncr ? "/manufacturing/quality/nonconformances/{$a->ncr->uuid}" : null];
                }
                foreach (LossFinding::query()->whereIn('status', LossFinding::OPEN)->whereNotNull('due_date')->whereDate('due_date', '<=', $by)->get() as $f) {
                    yield ['key' => "LPD:{$f->id}", 'subject_type' => 'LOSS_FINDING', 'subject_id' => $f->id, 'reference' => $f->number,
                        'factory_id' => $f->factory_id, 'responsible' => array_filter([$f->owner_id]),
                        'title' => "{$f->number} is due {$f->due_date->toDateString()}", 'message' => $f->corrective_action ?? $f->recommended_action,
                        'url' => "/manufacturing/loss/findings/{$f->uuid}"];
                }
                break;
        }
    }

    // ── Who hears ────────────────────────────────────────────────────────────────────────────

    /** @return array<int, string> user id => why they are told */
    private function recipients(NotificationRule $rule, array $c): array
    {
        $out = [];
        foreach ($this->holders((array) $rule->recipient_permissions) as $id) {
            $out[$id] = 'PERMISSION';
        }
        foreach ($this->roleHolders((array) ($rule->recipient_roles ?? [])) as $id) {
            $out[$id] ??= 'ROLE';
        }
        foreach ((array) ($rule->recipient_user_ids ?? []) as $id) {
            $out[(int) $id] ??= 'NAMED';
        }
        if ($rule->notify_responsible) {
            foreach ($c['responsible'] ?? [] as $id) {
                $out[(int) $id] = 'RESPONSIBLE';
            }
        }

        return $this->screen($out, $c);
    }

    /** Keep only active people of this company who hold what the alert needs and may see its factory. */
    private function screen(array $out, array $c): array
    {
        if ($out === []) {
            return [];
        }
        $users = User::withoutGlobalScopes()->whereIn('id', array_keys($out))->where('company_id', $this->companyId)
            ->where('status', 1)->whereNull('deleted_at')->get()->keyBy('id');
        $requires = isset($c['requires']) ? $this->holders([$c['requires']]) : null;

        return collect($out)->filter(function ($reason, $id) use ($users, $c, $requires) {
            $u = $users->get($id);
            if (! $u) {
                return false;
            }
            if ($requires !== null && $reason !== 'RESPONSIBLE' && ! in_array($id, $requires, true)) {
                return false;
            }

            return empty($c['factory_id']) || $this->access->canSeeFactory($u, (int) $c['factory_id']);
        })->all();
    }

    /** @return list<int> users of this company holding any of the permissions, through a role or directly */
    private function holders(array $permissions): array
    {
        $permissions = array_values(array_filter($permissions));
        if ($permissions === []) {
            return [];
        }
        sort($permissions);
        $k = implode('|', $permissions);

        return $this->permissionHolders[$k] ??= DB::table('users')->where('users.company_id', $this->companyId)->whereNull('users.deleted_at')
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $s->from('model_has_roles as mr')->join('role_has_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
                    ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->whereColumn('mr.model_id', 'users.id')
                    ->where('mr.model_type', User::class)->whereIn('p.name', $permissions))
                ->orWhereExists(fn ($s) => $s->from('model_has_permissions as mp')->join('permissions as p', 'p.id', '=', 'mp.permission_id')
                    ->whereColumn('mp.model_id', 'users.id')->where('mp.model_type', User::class)->whereIn('p.name', $permissions)))
            ->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function roleHolders(array $roles): array
    {
        return $roles === [] ? [] : DB::table('users')->join('model_has_roles as mr', fn ($j) => $j->on('mr.model_id', '=', 'users.id')->where('mr.model_type', User::class))
            ->join('roles as r', 'r.id', '=', 'mr.role_id')->where('users.company_id', $this->companyId)->whereIn('r.name', $roles)
            ->pluck('users.id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    // ── Sending ──────────────────────────────────────────────────────────────────────────────

    /** Each person's new alerts: one by one, or as one summary per kind when there are many. */
    private function deliver(array $outbox): int
    {
        $sent = 0;
        foreach ($outbox as $userId => $events) {
            $user = User::withoutGlobalScopes()->find($userId);
            if (! $user) {
                continue;
            }
            foreach ($events as $event => $items) {
                if (count($items) > self::DIGEST_AFTER) {
                    $rule = $items[0][1];
                    $lines = collect($items)->take(10)->map(fn ($i) => "• {$i[0]->title}")->implode("\n");
                    $more = count($items) > 10 ? "\n…and ".(count($items) - 10).' more.' : '';
                    $user->notify(new ManufacturingAlert($event, count($items).' '.strtolower(self::EVENTS[$event][4]), $lines.$more,
                        '/manufacturing/notifications/log?event='.$event, $rule->channels));
                    NotificationLog::query()->whereIn('id', collect($items)->map(fn ($i) => $i[0]->id))->update(['sent_at' => now(), 'in_digest' => true]);
                    $sent++;

                    continue;
                }
                foreach ($items as [$log, $rule]) {
                    $user->notify(new ManufacturingAlert($event, $log->title, $log->message, $log->url, $rule->channels));
                    $log->update(['sent_at' => now()]);
                    $sent++;
                }
            }
        }

        return $sent;
    }

    private function maybeEscalate(NotificationLog $log, NotificationRule $rule, array $c, array &$stats): void
    {
        if (! $rule->escalate_after_hours || ! $rule->escalate_to_permissions || $log->first_seen_at->gt(now()->subHours($rule->escalate_after_hours))) {
            return;
        }
        if (NotificationLog::query()->where('dedupe_key', $log->dedupe_key)->where('level', 1)->whereNull('resolved_at')->exists()) {
            return;
        }
        $to = $this->screen(array_fill_keys($this->holders((array) $rule->escalate_to_permissions), 'ESCALATION'), array_diff_key($c, ['requires' => 1]));
        foreach (array_keys($to) as $userId) {
            $user = User::withoutGlobalScopes()->find($userId);
            NotificationLog::create(['company_id' => $this->companyId, 'rule_id' => $rule->id, 'event_type' => $log->event_type, 'dedupe_key' => $log->dedupe_key,
                'subject_type' => $log->subject_type, 'subject_id' => $log->subject_id, 'reference' => $log->reference, 'factory_id' => $log->factory_id,
                'recipient_id' => $userId, 'level' => 1, 'reason' => 'ESCALATION', 'title' => $log->title,
                'message' => $log->message.' Unresolved since '.$log->first_seen_at->format('Y-m-d H:i').'.', 'url' => $log->url,
                'channels' => $rule->channels, 'first_seen_at' => $log->first_seen_at, 'last_seen_at' => now(), 'sent_at' => now()]);
            $user?->notify(new ManufacturingAlert($log->event_type, $log->title, $log->message.' Unresolved since '.$log->first_seen_at->format('Y-m-d H:i').'.', $log->url, $rule->channels, 1));
            $stats['escalated']++;
        }
    }

    private function order(PO $o, string $what, string $title, string $message): array
    {
        return ['key' => "{$what}:PO:{$o->id}", 'subject_type' => 'PRODUCTION_ORDER', 'subject_id' => $o->id, 'reference' => $o->number,
            'factory_id' => $o->factory_id, 'responsible' => array_filter([$o->supervisor_id]), 'title' => $title, 'message' => $message,
            'url' => "/manufacturing/production/orders/{$o->uuid}"];
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
}
