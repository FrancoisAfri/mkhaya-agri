<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryAssignment;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\NotificationLog;
use App\Modules\Operations\Manufacturing\Models\NotificationRule;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\StandardCost;
use App\Modules\Operations\Manufacturing\Notifications\ManufacturingAlert;
use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use App\Modules\Operations\Manufacturing\Services\Notifier;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * MFG-020: alerts reach the permission holders and the person responsible, only inside the factory
 * they may see; repeats are counted not resent; many become one summary; unresolved ones escalate
 * once; and an alert clears itself when its cause does.
 */
class AlertsTest extends ManufacturingTestCase
{
    private Factory $plant;

    private Factory $other;

    private FinWarehouse $store;

    private FinStockItem $steel;

    private Item $frame;

    private $planner2;

    private $elsewhere;

    private $boss;

    private $costApprover;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $planning = Role::firstOrCreate(['name' => 'mfg test material planner', 'guard_name' => 'web']);
        $planning->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::MANAGE_PLANNING]);
        $boss = Role::firstOrCreate(['name' => 'mfg test production manager', 'guard_name' => 'web']);
        $boss->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::APPROVE_ORDERS, RoleCatalog::ALL_FACTORIES, RoleCatalog::CONFIGURE]);
        $costs = Role::firstOrCreate(['name' => 'mfg test cost approver', 'guard_name' => 'web']);
        $costs->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::APPROVE_COSTS]);
        $this->planner2 = $this->makeUser('alert-planner@example.test', $planning);
        $this->elsewhere = $this->makeUser('alert-elsewhere@example.test', $planning);
        $this->boss = $this->makeUser('alert-boss@example.test', $boss);
        $this->costApprover = $this->makeUser('alert-costs@example.test', $costs);
        foreach ([$this->planner2, $this->elsewhere, $this->boss, $this->costApprover] as $u) {
            $this->grantModuleTier($u);
        }

        $this->plant = $this->factory('PLT');
        $this->other = $this->factory('OTH');
        // The second planner works at the other plant only.
        $this->asCompany($this->company, fn () => FactoryAssignment::create(['company_id' => $this->company->id, 'factory_id' => $this->other->id,
            'user_id' => $this->elsewhere->id, 'granted_by' => $this->boss->id]));
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)
            ->update(['require_material_for_approval' => false, 'require_routing' => false]));
        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->frame = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('FRAME', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->frame, [[$this->steel, 2, 0, 'kg']]);
        app(FactoryAccess::class)->flush();
    }

    /** An approved order with no steel in stock: short. */
    private function shortOrder(): PO
    {
        return $this->asCompany($this->company, function () {
            $o = app(ProductionOrders::class)->create(['item' => $this->frame, 'quantity' => 5, 'due_date' => now()->addDays(20)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store, 'supervisor_id' => $this->engineer->id], $this->boss);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->boss);
            $life->approve($o->fresh(), $this->boss);

            return $o->fresh();
        });
    }

    private function sweep(): array
    {
        return $this->asCompany($this->company, fn () => app(Notifier::class)->run($this->company->id));
    }

    private function alerts($user): int
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->where('data', 'like', '%mfg_material_shortage%')->count();
    }

    public function test_a_shortage_reaches_planners_at_that_factory_and_the_supervisor_once(): void
    {
        $order = $this->shortOrder();
        $this->sweep();

        $this->assertSame(1, $this->alerts($this->planner2));
        $this->assertSame(1, $this->alerts($this->engineer), 'The supervisor is responsible for the order.');
        $this->assertSame(0, $this->alerts($this->elsewhere), 'Assigned to the other plant only.');
        $this->assertSame(0, $this->alerts($this->outsider));
        $this->assertSame('RESPONSIBLE', NotificationLog::withoutGlobalScopes()->where('recipient_id', $this->engineer->id)->value('reason'));

        // Again inside the quiet period: counted, not sent.
        $stats = $this->sweep();
        $this->assertGreaterThanOrEqual(2, $stats['suppressed']);
        $this->assertSame(1, $this->alerts($this->planner2));
        $this->assertSame(2, NotificationLog::withoutGlobalScopes()->where('recipient_id', $this->planner2->id)->value('occurrences'));

        // Steel arrives: the alert clears itself.
        $this->asCompany($this->company, fn () => app(\App\Modules\Finance\Accounting\Services\StockLedger::class)->receive($this->steel->fresh(), 50, 10, $this->store, ['reference' => 'GRN']));
        $this->assertGreaterThanOrEqual(2, $this->sweep()['resolved']);
        $this->assertSame(0, NotificationLog::withoutGlobalScopes()->where('event_type', 'MATERIAL_SHORTAGE')->whereNull('resolved_at')->count());
        $this->assertNotNull($order);
    }

    public function test_an_unresolved_alert_escalates_once_and_many_alerts_arrive_as_one_summary(): void
    {
        foreach (range(1, 4) as $i) {
            $this->shortOrder();
        }
        $this->sweep();
        $this->assertSame(1, $this->alerts($this->planner2), 'Four shortages, one summary.');
        $this->assertSame(4, NotificationLog::withoutGlobalScopes()->where('recipient_id', $this->planner2->id)->where('in_digest', true)->count());

        // A day later nobody has acted: the production manager hears, once per order.
        $this->travel(25)->hours();
        $stats = $this->sweep();
        $this->assertSame(4, $stats['escalated']);
        $this->assertSame(4, NotificationLog::withoutGlobalScopes()->where('recipient_id', $this->boss->id)->where('level', 1)->count());
        $this->assertSame(0, $this->sweep()['escalated'], 'Not again.');
    }

    public function test_approvals_go_only_to_whoever_may_approve_that_thing_and_mail_goes_where_configured(): void
    {
        Notification::fake();
        $this->asCompany($this->company, function () {
            NotificationRule::query()->where('event_type', 'APPROVAL_PENDING')->delete();
            app(Notifier::class)->rule($this->company->id, 'APPROVAL_PENDING')->update(['channels' => ['database', 'mail']]);
            $std = app(\App\Modules\Operations\Manufacturing\Services\StandardCosting::class)->calculate($this->frame, 1, $this->boss);
            DB::table('mfg_standard_costs')->where('id', $std->id)->update(['created_at' => now()->subDay()]);
        });
        $this->sweep();

        Notification::assertSentTo($this->costApprover, ManufacturingAlert::class,
            fn ($n, $channels) => $n->eventType === 'APPROVAL_PENDING' && in_array('mail', $channels, true));
        Notification::assertNotSentTo($this->boss, ManufacturingAlert::class, fn ($n) => $n->eventType === 'APPROVAL_PENDING');
    }

    public function test_the_alert_screens_and_a_factory_rule(): void
    {
        $this->actingAs($this->boss)->get(route('manufacturing.notifications.rules'))->assertOk();
        $rule = $this->asCompany($this->company, fn () => NotificationRule::query()->where('event_type', 'MATERIAL_SHORTAGE')->firstOrFail());

        // The plant gets its own rule: only named people, nobody by permission.
        $this->actingAs($this->boss)->post(route('manufacturing.notifications.rules.save'), [
            'event_type' => 'MATERIAL_SHORTAGE', 'factory_id' => $this->plant->id, 'is_active' => true, 'recipient_permissions' => [],
            'recipient_user_ids' => [$this->boss->id], 'notify_responsible' => false, 'channels' => ['database'],
            'lead_hours' => 0, 'suppress_hours' => 24,
        ])->assertSessionHas('success');
        $this->shortOrder();
        $this->sweep();
        $this->assertSame(0, $this->alerts($this->planner2));
        $this->assertSame(1, $this->alerts($this->boss));

        $log = $this->actingAs($this->boss)->get(route('manufacturing.notifications.log'))->assertOk()->viewData('page');
        $this->assertSame('NAMED', $log['props']['entries'][0]['reason']);
        $this->actingAs($this->boss)->post(route('manufacturing.notifications.run'))->assertSessionHas('success');
        $this->actingAs($this->planner2)->get(route('manufacturing.notifications.rules'))->assertForbidden();
        $this->actingAs($this->planner2)->post(route('manufacturing.notifications.rules.save'), ['id' => $rule->id])->assertForbidden();

        $this->artisan('mfg:send-alerts', ['--company' => $this->company->id])->assertSuccessful();
    }
}
