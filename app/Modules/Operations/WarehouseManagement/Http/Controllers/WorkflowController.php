<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\HR\Support\PicksTenantPeople;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\Revaluation;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Services\Valuation;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseApprovals;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseNotifier;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/** WMS-190..192 on screen: alert rules and their log; approvals, their policies and delegations. */
class WorkflowController extends Controller
{
    use PicksTenantPeople, RefusesPolitely;

    public function notifications(Request $request, WarehouseNotifier $notifier): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $cid = (int) app(CurrentCompany::class)->id();
        $all = $request->user()->can(RoleCatalog::SUPERVISE);

        return Inertia::render('Modules/Warehouse/Workflow/Notifications', [
            'rules' => collect(array_keys(WarehouseNotifier::EVENTS))->map(fn ($e) => ['event' => $e] + (array) $notifier->rule($cid, $e))->values(),
            'log' => DB::table('wms_notification_log as l')->join('users as u', 'u.id', '=', 'l.recipient_id')->where('l.company_id', $cid)
                ->when(! $all, fn ($q) => $q->where('l.recipient_id', $request->user()->id))->orderByDesc('l.id')->limit(150)
                ->get(['l.*', 'u.email'])->map(fn ($r) => ['id' => $r->id, 'event' => $r->event_type, 'title' => $r->title, 'recipient' => $r->email, 'reason' => $r->reason,
                    'level' => (int) $r->level, 'delivery' => json_decode($r->delivery, true), 'occurrences' => (int) $r->occurrences, 'error' => $r->last_error,
                    'sent_at' => $r->sent_at ? Carbon::parse($r->sent_at)->toIso8601String() : null, 'resolved_at' => $r->resolved_at ? Carbon::parse($r->resolved_at)->toIso8601String() : null]),
            'permissions' => RoleCatalog::all(),
            'people' => $this->tenantPeopleOptions($request),
            'can' => ['configure' => $request->user()->can(RoleCatalog::MANAGE_WAREHOUSES)],
        ]);
    }

    public function saveRule(Request $request, string $event): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_WAREHOUSES), 403);
        abort_unless(isset(WarehouseNotifier::EVENTS[$event]), 404);
        $d = $request->validate(['is_active' => ['boolean'], 'recipient_permissions' => ['array'], 'recipient_permissions.*' => [Rule::in(RoleCatalog::all())],
            'recipient_user_ids' => ['array'], 'recipient_user_ids.*' => ['integer'], 'notify_responsible' => ['boolean'],
            'channels' => ['required', 'array', 'min:1'], 'channels.*' => [Rule::in(['database', 'mail'])], 'thresholds' => ['nullable', 'array'], 'thresholds.*' => ['numeric', 'min:0'],
            'suppress_hours' => ['required', 'integer', 'between:1,720'], 'escalate_after_hours' => ['nullable', 'integer', 'between:1,2160'],
            'escalate_to_permissions' => ['array'], 'escalate_to_permissions.*' => [Rule::in(RoleCatalog::all())], 'warehouse_id' => ['nullable', 'integer']]);
        $cid = (int) app(CurrentCompany::class)->id();
        if (! empty($d['warehouse_id'])) {
            FinWarehouse::query()->findOrFail($d['warehouse_id']);
        }
        $people = array_values(array_intersect(array_map('intval', $d['recipient_user_ids'] ?? []), array_column($this->tenantPeopleOptions($request), 'id')));
        $row = ['is_active' => $d['is_active'] ?? true, 'recipient_permissions' => json_encode(array_values($d['recipient_permissions'] ?? [])),
            'recipient_user_ids' => json_encode($people), 'notify_responsible' => $d['notify_responsible'] ?? true, 'channels' => json_encode(array_values($d['channels'])),
            'thresholds' => json_encode(array_intersect_key($d['thresholds'] ?? [], WarehouseNotifier::EVENTS[$event][1])), 'suppress_hours' => $d['suppress_hours'],
            'escalate_after_hours' => $d['escalate_after_hours'] ?? null, 'escalate_to_permissions' => json_encode(array_values($d['escalate_to_permissions'] ?? [])),
            'updated_by' => $request->user()->id, 'updated_at' => now()];
        $q = DB::table('wms_notification_rules')->where('company_id', $cid)->where('event_type', $event)
            ->when($d['warehouse_id'] ?? null, fn ($w, $v) => $w->where('warehouse_id', $v), fn ($w) => $w->whereNull('warehouse_id'));
        $before = (array) app(WarehouseNotifier::class)->rule($cid, $event, $d['warehouse_id'] ?? null);
        $q->exists() ? $q->update($row) : DB::table('wms_notification_rules')->insert($row + ['company_id' => $cid, 'event_type' => $event,
            'warehouse_id' => $d['warehouse_id'] ?? null, 'created_at' => now()]);
        app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit::class)->record($cid, 'alert_rule.changed', 'ALERT_RULE', $event, "Alert rule {$event} changed", $before,
            (array) app(WarehouseNotifier::class)->rule($cid, $event, $d['warehouse_id'] ?? null));

        return back()->with('success', 'Alert rule saved.');
    }

    public function approvals(Request $request, WarehouseApprovals $approvals): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        $cid = (int) app(CurrentCompany::class)->id();
        $user = $request->user();
        $manage = $user->can(RoleCatalog::MANAGE_WAREHOUSES);

        return Inertia::render('Modules/Warehouse/Workflow/Approvals', [
            'inbox' => $approvals->inbox($user, $cid),
            'policies' => collect(WarehouseApprovals::SUBJECTS)->map(fn ($s, $type) => ['type' => $type, 'label' => $s[0], 'default_permission' => $s[1],
                'policy' => DB::table('wms_approval_policies')->where('company_id', $cid)->where('subject_type', $type)->first()])
                ->map(fn ($p) => array_merge($p, ['policy' => $p['policy'] ? ['levels' => json_decode($p['policy']->levels, true), 'due_hours' => (int) $p['policy']->due_hours,
                    'escalate_to_permissions' => json_decode((string) $p['policy']->escalate_to_permissions, true) ?? [], 'is_active' => (bool) $p['policy']->is_active] : null]))->values(),
            'delegations' => DB::table('wms_delegations as d')->join('users as f', 'f.id', '=', 'd.from_user_id')->join('users as t', 't.id', '=', 'd.to_user_id')
                ->where('d.company_id', $cid)->when(! $manage, fn ($q) => $q->where(fn ($w) => $w->where('d.from_user_id', $user->id)->orWhere('d.to_user_id', $user->id)))
                ->whereNull('d.revoked_at')->where('d.ends_at', '>', now())->orderBy('d.starts_at')->get(['d.*', 'f.email as from_email', 't.email as to_email'])
                ->map(fn ($d) => ['id' => $d->id, 'from' => $d->from_email, 'to' => $d->to_email, 'types' => json_decode((string) $d->subject_types, true),
                    'starts_at' => Carbon::parse($d->starts_at)->toIso8601String(), 'ends_at' => Carbon::parse($d->ends_at)->toIso8601String(), 'reason' => $d->reason,
                    'mine' => (int) $d->from_user_id === (int) $user->id]),
            'subjects' => collect(WarehouseApprovals::SUBJECTS)->map(fn ($s, $k) => ['value' => $k, 'title' => $s[0]])->values(),
            'permissions' => RoleCatalog::all(),
            'people' => $this->tenantPeopleOptions($request),
            'me' => $user->id,
            'can' => ['manage' => $manage],
        ]);
    }

    /** Decide from the inbox: the request's own service applies the decision, so its rules still hold. */
    public function decide(Request $request, string $step, WarehouseApprovals $approvals): RedirectResponse
    {
        $s = DB::table('wms_approval_steps')->where('company_id', app(CurrentCompany::class)->id())->where('id', (int) $step)->firstOrFail();
        $d = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => ['nullable', 'string', 'max:1000']]);
        abort_unless($approvals->mayDecide($request->user(), $s->subject_type, (int) $s->subject_id, $s->requested_by ? (int) $s->requested_by : null), 403);
        $approve = $d['decision'] === 'approve';

        return $this->attempt(fn () => match ($s->subject_type) {
            'ADJUSTMENT' => $approve ? app(StockAdjustments::class)->approve(StockAdjustment::query()->findOrFail($s->subject_id), $request->user(), $d['note'] ?? null)
                : app(StockAdjustments::class)->reject(StockAdjustment::query()->findOrFail($s->subject_id), $request->user(), (string) ($d['note'] ?? '')),
            'REVALUATION' => app(Valuation::class)->decideRevaluation(Revaluation::query()->findOrFail($s->subject_id), $approve, $d['note'] ?? null, $request->user()),
        }, $approve ? 'Approval recorded.' : 'Rejected.');
    }

    public function savePolicy(Request $request, string $type, WarehouseApprovals $approvals): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_WAREHOUSES), 403);
        abort_unless(isset(WarehouseApprovals::SUBJECTS[$type]), 404);
        $d = $request->validate(['levels' => ['required', 'array', 'min:1', 'max:5'], 'levels.*.name' => ['required', 'string', 'max:80'],
            'levels.*.permission' => ['required', Rule::in(RoleCatalog::all())], 'levels.*.min_value' => ['required', 'numeric', 'min:0'],
            'due_hours' => ['required', 'integer', 'between:1,720'], 'escalate_to_permissions' => ['array'], 'escalate_to_permissions.*' => [Rule::in(RoleCatalog::all())],
            'is_active' => ['boolean']]);

        return $this->attempt(fn () => $approvals->savePolicy((int) app(CurrentCompany::class)->id(), $type, $d['levels'], $d['due_hours'],
            $d['escalate_to_permissions'] ?? [], $d['is_active'] ?? true, $request->user()), 'Approval policy saved. It applies to requests made from now on.');
    }

    public function delegate(Request $request, WarehouseApprovals $approvals): RedirectResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $d = $request->validate(['from_user_id' => ['nullable', 'integer'], 'to_user_id' => ['required', 'integer'], 'types' => ['nullable', 'array'],
            'types.*' => [Rule::in(array_keys(WarehouseApprovals::SUBJECTS))], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:300']]);

        return $this->attempt(fn () => $approvals->delegate((int) app(CurrentCompany::class)->id(), (int) ($d['from_user_id'] ?? $request->user()->id), (int) $d['to_user_id'],
            $d['types'] ?? null, Carbon::parse($d['starts_at']), Carbon::parse($d['ends_at']), $d['reason'], $request->user()), 'Delegation recorded.');
    }

    public function revoke(Request $request, string $delegation): RedirectResponse
    {
        $row = DB::table('wms_delegations')->where('company_id', app(CurrentCompany::class)->id())->where('id', (int) $delegation)->firstOrFail();
        abort_unless((int) $row->from_user_id === (int) $request->user()->id || $request->user()->can(RoleCatalog::MANAGE_WAREHOUSES), 403);
        DB::table('wms_delegations')->where('id', $row->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit::class)->record((int) $row->company_id, 'delegation.ended', 'DELEGATION', (string) $row->id, 'Delegation ended early');

        return back()->with('success', 'Delegation ended.');
    }
}
