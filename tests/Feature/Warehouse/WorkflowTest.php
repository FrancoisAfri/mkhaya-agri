<?php

namespace Tests\Feature\Warehouse;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\WarehouseManagement\Models\ReasonCode;
use App\Modules\Operations\WarehouseManagement\Models\StockAdjustment;
use App\Modules\Operations\WarehouseManagement\Services\ItemCatalogue;
use App\Modules\Operations\WarehouseManagement\Services\LocationService;
use App\Modules\Operations\WarehouseManagement\Services\StockAdjustments;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseApprovals;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseNotifier;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/** WMS-190..192: alerts that reach the right people once, and approvals with levels, delegation and escalation. */
class WorkflowTest extends WarehouseTestCase
{
    private FinWarehouse $wh;

    private FinStockItem $item;

    private FinStockBin $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->wh = $this->warehouse('WF', ['is_wms_managed' => true]);
        $this->asCompany($this->company, function () {
            app(ItemCatalogue::class)->ensureUnits($this->company->id);
            app(StockAdjustments::class)->ensureReasonCodes($this->company->id);
            ReasonCode::query()->where('code', 'DAMAGED')->update(['always_requires_approval' => true]);
            $this->bin = app(LocationService::class)->create($this->wh, ['code' => 'A1']);
            $this->item = app(ItemCatalogue::class)->create(['company_id' => $this->company->id, 'code' => 'DRILL', 'name' => 'Drill', 'uom' => 'ea', 'standard_cost' => 100]);
            app(StockLedger::class)->receive($this->item->fresh(), 10, 100, $this->wh, ['bin_id' => $this->bin->id]);
        });
    }

    private function in(callable $fn): mixed
    {
        return $this->asCompany($this->company, $fn);
    }

    private function alerts(int $userId): int
    {
        return DB::table('notifications')->where('notifiable_id', $userId)->where('data', 'like', '%wms_task_failed%')->count();
    }

    public function test_an_alert_is_sent_once_counted_while_unresolved_reminded_after_the_quiet_period_resolved_and_escalated(): void
    {
        $n = app(WarehouseNotifier::class);
        $occ = ['key' => 'PUTAWAY:999', 'warehouse_id' => $this->wh->id, 'title' => 'Put-away P-1 failed', 'message' => 'Stuck.', 'url' => '/warehouse/floor/putaway'];

        $this->in(fn () => $n->raise($this->company->id, 'TASK_FAILED', [$occ]));
        $this->in(fn () => $n->raise($this->company->id, 'TASK_FAILED', [$occ]));
        $this->assertSame(1, $this->alerts($this->manager->id), 'the same unresolved alert is not repeated');
        $this->assertSame(0, $this->alerts($this->viewer->id), 'only people with the rule permission hear');
        $row = DB::table('wms_notification_log')->where('recipient_id', $this->manager->id)->sole();
        $this->assertSame(2, (int) $row->occurrences);
        $this->assertSame(['database' => 'SENT'], json_decode($row->delivery, true));

        $this->travel(25)->hours();
        $this->in(fn () => $n->raise($this->company->id, 'TASK_FAILED', [$occ]));
        $this->assertSame(2, $this->alerts($this->manager->id), 'reminded after the quiet period');

        // Escalation: unresolved past the rule's time goes once to the escalation permission's holders.
        $boss = $this->person('boss', [RoleCatalog::VIEW, RoleCatalog::MANAGE_WAREHOUSES]);
        $this->in(fn () => $n->escalate($this->company->id));
        $this->in(fn () => $n->escalate($this->company->id));
        $this->assertSame(1, DB::table('wms_notification_log')->where('recipient_id', $boss->id)->where('level', 1)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $boss->id)->where('data', 'like', '%Escalated%')->count());

        // The task is no longer failing: the scheduled pass resolves the alert and leaves the task alone.
        $this->in(fn () => $n->run($this->company->id));
        $this->assertSame(0, DB::table('wms_notification_log')->where('dedupe_key', 'PUTAWAY:999')->whereNull('resolved_at')->count());
    }

    public function test_rules_are_configured_by_authorised_people_and_failed_channels_are_retried(): void
    {
        $this->actingAs($this->viewer)->post(route('warehouse.alerts.rule', 'TASK_FAILED'), ['channels' => ['database'], 'suppress_hours' => 4])->assertForbidden();
        $this->actingAs($this->manager)->post(route('warehouse.alerts.rule', 'TASK_FAILED'), ['recipient_permissions' => [RoleCatalog::VIEW], 'channels' => ['database', 'mail'],
            'suppress_hours' => 4, 'notify_responsible' => false])->assertSessionHasNoErrors();
        $rule = $this->in(fn () => app(WarehouseNotifier::class)->rule($this->company->id, 'TASK_FAILED'));
        $this->assertSame(['database', 'mail'], $rule->channels);

        $this->in(fn () => app(WarehouseNotifier::class)->raise($this->company->id, 'TASK_FAILED', [['key' => 'X:1', 'warehouse_id' => null, 'title' => 'T', 'message' => 'M', 'url' => null]]));
        $this->assertSame(1, $this->alerts($this->viewer->id), 'the rule now reaches viewers');
        $row = DB::table('wms_notification_log')->where('recipient_id', $this->viewer->id)->sole();
        DB::table('wms_notification_log')->where('id', $row->id)->update(['delivery' => json_encode(['database' => 'SENT', 'mail' => 'FAILED']), 'next_attempt_at' => now()->subMinute()]);
        $this->assertSame(1, $this->in(fn () => app(WarehouseNotifier::class)->retry($this->company->id)));
        $this->assertEqualsCanonicalizing(['database' => 'SENT', 'mail' => 'SENT'], json_decode(DB::table('wms_notification_log')->where('id', $row->id)->value('delivery'), true));
        $this->assertSame(1, $this->alerts($this->viewer->id), 'a retry resends only the channel that failed');

        $this->actingAs($this->viewer)->get(route('warehouse.alerts.index'))->assertOk()->assertInertia(fn ($p) => $p->has('rules', 11)->where('can.configure', false));
    }

    public function test_an_adjustment_passes_every_level_its_value_needs_by_different_people_and_a_delegate_can_act(): void
    {
        $clerk = $this->person('clerk', [RoleCatalog::VIEW, RoleCatalog::ADJUST_STOCK]);
        $lead = $this->person('lead', [RoleCatalog::VIEW, RoleCatalog::APPROVE_ADJUSTMENTS]);
        $finance = $this->person('fin', [RoleCatalog::VIEW, RoleCatalog::APPROVE_ADJUSTMENTS, RoleCatalog::APPROVE_ACCOUNTING]);
        $deputy = $this->person('deputy', [RoleCatalog::VIEW]);
        $this->actingAs($this->manager)->post(route('warehouse.approvals.policy', 'ADJUSTMENT'), ['due_hours' => 4, 'escalate_to_permissions' => [RoleCatalog::MANAGE_WAREHOUSES], 'levels' => [
            ['name' => 'Team lead', 'permission' => RoleCatalog::APPROVE_ADJUSTMENTS, 'min_value' => 0],
            ['name' => 'Finance', 'permission' => RoleCatalog::APPROVE_ACCOUNTING, 'min_value' => 250],
        ]])->assertSessionHasNoErrors();

        $reason = $this->in(fn () => ReasonCode::query()->where('code', 'DAMAGED')->firstOrFail());
        $small = $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id, 'bin_id' => $this->bin->id, 'qty' => -1, 'reason_code_id' => $reason->id], $clerk));
        $big = $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id, 'bin_id' => $this->bin->id, 'qty' => -3, 'reason_code_id' => $reason->id], $clerk));
        $this->assertSame(1, DB::table('wms_approval_steps')->where('subject_id', $small->id)->count(), '100 needs one level');
        $this->assertSame(2, DB::table('wms_approval_steps')->where('subject_id', $big->id)->count(), '300 needs two');

        $this->actingAs($clerk)->post(route('warehouse.stock.adjustments.decide', $big->uuid), ['decision' => 'approve'])->assertForbidden();
        $this->actingAs($lead)->post(route('warehouse.stock.adjustments.decide', $big->uuid), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(StockAdjustment::PENDING, $big->fresh()->status, 'the finance level has not decided');
        $this->assertSame(10.0, (float) DB::table('fin_stock_positions')->where('item_id', $this->item->id)->sum('qty'), 'nothing moves before the last level');

        // The team lead cannot also take the second level, even holding its permission later.
        $this->actingAs($finance)->get(route('warehouse.approvals.index'))->assertOk()->assertInertia(fn ($p) => $p->where('inbox', fn ($i) => collect($i)->contains(fn ($x) => $x['ref'] === $big->number && $x['name'] === 'Finance')));
        $this->actingAs($lead)->get(route('warehouse.approvals.index'))->assertOk()->assertInertia(fn ($p) => $p->where('inbox', fn ($i) => ! collect($i)->contains('ref', $big->number)));

        // Finance is away and delegates to the deputy, who approves on their behalf.
        $this->actingAs($finance)->post(route('warehouse.approvals.delegate'), ['to_user_id' => $deputy->id, 'types' => ['ADJUSTMENT'],
            'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString(), 'reason' => 'Leave'])->assertSessionHasNoErrors();
        $step = DB::table('wms_approval_steps')->where('subject_id', $big->id)->where('level', 2)->first();
        $this->actingAs($deputy)->post(route('warehouse.approvals.decide', $step->id), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(StockAdjustment::POSTED, $big->fresh()->status);
        $this->assertSame($finance->id, (int) DB::table('wms_approval_steps')->where('id', $step->id)->value('on_behalf_of'));
        $this->assertSame($deputy->id, (int) DB::table('wms_approval_steps')->where('id', $step->id)->value('decided_by'));

        // Overdue: the single-level request is escalated once and its approvers are reminded.
        $this->travel(5)->hours();
        $this->assertSame(1, $this->in(fn () => app(WarehouseApprovals::class)->escalateOverdue($this->company->id)));
        $this->assertSame(0, $this->in(fn () => app(WarehouseApprovals::class)->escalateOverdue($this->company->id)));
        $this->assertTrue(DB::table('wms_notification_log')->where('recipient_id', $this->manager->id)->where('dedupe_key', 'like', "APPROVAL:ADJUSTMENT:{$small->id}:1:ESCALATED")->exists());
        $this->in(fn () => app(WarehouseNotifier::class)->run($this->company->id));
        $this->assertTrue(DB::table('wms_notification_log')->where('recipient_id', $lead->id)->where('dedupe_key', "APPROVAL:ADJUSTMENT:{$small->id}:1")->exists());
        $this->assertFalse(DB::table('wms_notification_log')->where('recipient_id', $clerk->id)->exists(), 'the requester is not asked to approve their own');

        // Rejecting at the first level ends the request.
        $this->travelBack(); // five hours idle would end the session
        $this->actingAs($lead)->post(route('warehouse.stock.adjustments.decide', $small->uuid), ['decision' => 'reject', 'note' => 'Not damaged'])->assertSessionHasNoErrors();
        $this->assertSame(StockAdjustment::REJECTED, $small->fresh()->status);
        $this->assertSame('REJECTED', DB::table('wms_approval_steps')->where('subject_id', $small->id)->value('status'));
    }

    public function test_without_a_policy_one_approval_still_posts_and_a_delegation_needs_a_reason_and_valid_dates(): void
    {
        $reason = $this->in(fn () => ReasonCode::query()->where('code', 'DAMAGED')->firstOrFail());
        $adj = $this->in(fn () => app(StockAdjustments::class)->request(['warehouse_id' => $this->wh->id, 'item_id' => $this->item->id, 'bin_id' => $this->bin->id, 'qty' => -1, 'reason_code_id' => $reason->id], $this->viewer));
        $this->assertSame(0, DB::table('wms_approval_steps')->where('subject_id', $adj->id)->count());
        $this->actingAs($this->manager)->post(route('warehouse.stock.adjustments.decide', $adj->uuid), ['decision' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame(StockAdjustment::POSTED, $adj->fresh()->status);

        $this->actingAs($this->viewer)->post(route('warehouse.approvals.delegate'), ['from_user_id' => $this->manager->id, 'to_user_id' => $this->supervisor->id,
            'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString(), 'reason' => 'x'])->assertSessionHasErrors('refused');
        $this->actingAs($this->viewer)->post(route('warehouse.approvals.delegate'), ['to_user_id' => $this->supervisor->id,
            'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->subDay()->toDateTimeString(), 'reason' => 'x'])->assertSessionHasErrors('ends_at');
    }
}
