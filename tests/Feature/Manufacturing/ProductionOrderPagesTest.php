<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinStockLot;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use App\Core\Role\Models\Role;

/**
 * MFG-006 through the screens: every lifecycle step, material movement and floor action as the
 * person who would actually take it, through the same routes the pages call.
 *
 * Three people: the planner raises and plans, the production manager approves, releases, completes
 * and closes, and the operator issues material and records the floor. None of them can do the
 * others' steps.
 */
class ProductionOrderPagesTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private Item $widget;

    private FinStockItem $steel;

    private FinStockItem $bolt;

    private $manager;

    private $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        Role::findByName('mfg test planner', 'web')->givePermissionTo([RoleCatalog::VIEW_ORDERS, RoleCatalog::MANAGE_ORDERS]);

        $manager = Role::firstOrCreate(['name' => 'mfg test production manager', 'guard_name' => 'web']);
        $manager->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ORDERS, RoleCatalog::APPROVE_ORDERS,
            RoleCatalog::RELEASE_ORDERS, RoleCatalog::CLOSE_ORDERS, RoleCatalog::OVERRIDE_CONTROLS]);
        $operator = Role::firstOrCreate(['name' => 'mfg test operator', 'guard_name' => 'web']);
        $operator->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ORDERS, RoleCatalog::EXECUTE, RoleCatalog::ISSUE_MATERIAL]);

        $this->manager = $this->makeUser('mfg-manager@example.test', $manager);
        $this->operator = $this->makeUser('mfg-operator@example.test', $operator);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->planner = $this->planner->fresh();
        foreach ([$this->manager, $this->operator] as $user) {
            $this->grantModuleTier($user);
        }

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');

        $this->asCompany($this->company, function () {
            Shift::create([
                'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
                'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true,
            ]);
        });

        $this->steel = $this->stockItem('STEEL', 'kg', 10);
        $this->bolt = $this->stockItem('BOLT', 'ea', 2);
        $fg = $this->stockItem('WIDGET', 'ea', 0);
        $this->widget = $this->asCompany($this->company, fn () => Item::create([
            'company_id' => $this->company->id, 'stock_item_id' => $fg->id, 'kind' => 'FINISHED',
            'default_uom_id' => $this->uom('ea')->id, 'is_active' => true,
        ]));
        $this->approvedBom($this->widget, [[$this->steel, 2, 0, 'kg'], [$this->bolt, 4, 0, 'ea']]);

        $centre = $this->asCompany($this->company, fn () => WorkCentre::create([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'ASM', 'name' => 'Assembly',
            'cost_per_hour' => 120, 'labour_rate_per_hour' => 60, 'is_active' => true,
        ]));
        $this->routing($this->widget, [
            ['Prepare', 10, 1, ['work_centre_id' => $centre->id]],
            ['Assemble', 15, 3, ['work_centre_id' => $centre->id, 'is_inspection_point' => true]],
            ['Pack', 5, 1, ['work_centre_id' => $centre->id]],
        ], [], true);
    }

    private function stockUp(): void
    {
        $this->asCompany($this->company, function () {
            $ledger = app(StockLedger::class);
            $ledger->receive($this->steel->fresh(), 100, 10, $this->store, ['reference' => 'GRN-1']);
            $ledger->receive($this->bolt->fresh(), 200, 2, $this->store, ['reference' => 'GRN-1']);
        });
    }

    private function url(string $name, PO $order, array $extra = []): string
    {
        return route("manufacturing.production.orders.{$name}", ['uuid' => $order->uuid, ...$extra]);
    }

    private function raiseThroughTheScreen(float $qty = 10): PO
    {
        $this->actingAs($this->planner)->post(route('manufacturing.production.orders.store'), [
            'item_uuid' => $this->widget->uuid, 'factory_uuid' => $this->plant->uuid, 'quantity' => $qty,
            'due_date' => now()->addDays(10)->toDateString(), 'warehouse_id' => $this->store->id,
            'priority' => 2, 'demand_type' => 'SALES_ORDER', 'demand_reference' => 'SO-1001',
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $this->asCompany($this->company, fn () => PO::query()->latest('id')->firstOrFail());
    }

    // ── Navigation and reading ──────────────────────────────────────────────────────────────

    public function test_the_production_hub_offers_orders_to_those_who_may_read_them(): void
    {
        // The production hub also holds planning, engineering and maintenance cards; this is about orders.
        $cards = fn ($user) => array_values(array_filter(array_column($this->actingAs($user)->get('/manufacturing/production')->assertOk()
            ->viewData('page')['props']['cards'], 'path'), fn ($p) => str_starts_with($p, '/manufacturing/production/')));

        $this->assertContains('/manufacturing/production/orders', $cards($this->planner));
        $this->assertContains('/manufacturing/production/orders', $cards($this->operator));
        $this->assertSame([], $cards($this->engineer), 'An engineer with no order permission was offered the orders card.');

        $this->actingAs($this->engineer)->get(route('manufacturing.production.orders.index'))->assertForbidden();
        $this->actingAs($this->outsider)->get(route('manufacturing.production.orders.index'))->assertForbidden();
    }

    public function test_the_list_offers_the_create_form_only_to_a_planner_and_filters_open_orders(): void
    {
        $order = $this->raiseThroughTheScreen();

        $page = $this->actingAs($this->planner)->get(route('manufacturing.production.orders.index'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Production/Orders', $page['component']);
        $this->assertTrue($page['props']['can']['create']);
        $this->assertSame([$this->widget->uuid], array_column($page['props']['products'], 'id'));
        $this->assertNotEmpty($page['props']['warehouses']);
        $this->assertSame($order->number, $page['props']['orders']['data'][0]['number']);

        $page = $this->actingAs($this->operator)->get(route('manufacturing.production.orders.index'))->viewData('page');
        $this->assertFalse($page['props']['can']['create']);
        $this->assertSame([], $page['props']['products'], 'The create form\'s choices went to somebody who cannot create.');

        $this->asCompany($this->company, fn () => $order->update(['status' => PO::CANCELLED]));
        $open = $this->actingAs($this->planner)->get(route('manufacturing.production.orders.index').'?status=OPEN')->viewData('page');
        $this->assertSame([], $open['props']['orders']['data']);
        $search = $this->actingAs($this->planner)->get(route('manufacturing.production.orders.index').'?search=SO-1001')->viewData('page');
        $this->assertCount(1, $search['props']['orders']['data']);
    }

    public function test_a_draft_raised_from_the_screen_shows_its_recipe_checks_and_costs(): void
    {
        $order = $this->raiseThroughTheScreen();

        $this->assertSame(PO::DRAFT, $order->status);
        $this->assertSame('SO-1001', $order->demand_reference);
        $this->assertSame(2, $order->priority);

        $page = $this->actingAs($this->planner)->get($this->url('show', $order))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Production/OrderShow', $page['component']);
        $props = $page['props'];

        $this->assertEquals(['STEEL' => 20.0, 'BOLT' => 40.0], collect($props['components'])->pluck('required', 'code')->all());
        $this->assertSame(['Prepare', 'Assemble', 'Pack'], array_column($props['operations'], 'name'));
        $this->assertEquals(280.0, $props['order']['costs']['estimated']['material']);
        // Nothing on the shelf yet: approval would find both materials short, and says so up front.
        $this->assertEqualsCanonicalizing(['STEEL', 'BOLT'], array_column($props['checks']['shortages'], 'item'));
        $this->assertTrue($props['can']['edit']);
        $this->assertTrue($props['can']['plan']);
        $this->assertFalse($props['can']['approve']);
        $this->assertSame('CREATED', $props['events'][0]['action']);
    }

    public function test_a_draft_quantity_change_rescales_it(): void
    {
        $order = $this->raiseThroughTheScreen(10);

        $this->actingAs($this->planner)->patch($this->url('update', $order), ['quantity' => 5, 'notes' => 'Half batch'])
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertEquals(5.0, (float) $order->planned_qty);
        $this->assertSame('Half batch', $order->notes);
        $this->assertEquals(10.0, (float) $order->components()->where('stock_item_id', $this->steel->id)->value('required_qty'));

        $this->actingAs($this->planner)->post($this->url('plan', $order))->assertSessionHas('success');
        $this->actingAs($this->planner)->patch($this->url('update', $order->fresh()), ['quantity' => 8])->assertForbidden();
    }

    // ── Separation of duties ────────────────────────────────────────────────────────────────

    public function test_each_step_belongs_to_its_own_role(): void
    {
        $this->stockUp();
        $order = $this->raiseThroughTheScreen();

        // The manager cannot plan, the operator cannot plan.
        $this->actingAs($this->manager)->post($this->url('plan', $order))->assertForbidden();
        $this->actingAs($this->operator)->post($this->url('plan', $order))->assertForbidden();
        $this->actingAs($this->planner)->post($this->url('plan', $order))->assertSessionHas('success');

        // The planner cannot approve their own order; nobody can release before approval.
        $this->actingAs($this->planner)->post($this->url('approve', $order))->assertForbidden();
        $this->actingAs($this->manager)->post($this->url('release', $order))->assertForbidden();
        $this->actingAs($this->manager)->post($this->url('approve', $order))->assertSessionHas('success');

        // The operator cannot release, and cannot touch an order that is not on the floor.
        $this->actingAs($this->operator)->post($this->url('release', $order))->assertForbidden();
        $steel = $order->components()->where('stock_item_id', $this->steel->id)->first();
        $this->actingAs($this->operator)->post($this->url('components.issue', $order, ['componentUuid' => $steel->uuid]), ['qty' => 1])
            ->assertForbidden();

        $this->actingAs($this->manager)->post($this->url('release', $order))->assertSessionHas('success');
        $this->assertSame(PO::RELEASED, $order->fresh()->status);

        // Closing is not the operator's to do, and not possible before completion anyway.
        $this->actingAs($this->operator)->post($this->url('close', $order))->assertForbidden();
    }

    public function test_a_refusal_from_the_rules_comes_back_as_the_reason_and_changes_nothing(): void
    {
        // No stock: approval is refused on the shortage, with the materials named.
        $order = $this->raiseThroughTheScreen();
        $this->actingAs($this->planner)->post($this->url('plan', $order));

        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)
            ->update(['require_material_for_approval' => true]));

        $this->actingAs($this->manager)->post($this->url('approve', $order))->assertSessionHas('error');
        $this->assertSame(PO::PLANNED, $order->fresh()->status);

        // With a reason, the manager (who holds the override) approves; the event is an exception.
        $this->actingAs($this->manager)->post($this->url('approve', $order), ['override_reason' => 'Steel arrives Monday'])
            ->assertSessionHas('success');
        $this->assertSame(PO::APPROVED, $order->fresh()->status);
        $approved = $order->events()->where('action', 'APPROVED')->first();
        $this->assertTrue($approved->is_exception);
        $this->assertSame('Steel arrives Monday', $approved->reason);
    }

    public function test_reject_hold_and_cancel_require_a_reason(): void
    {
        $order = $this->raiseThroughTheScreen();
        $this->actingAs($this->planner)->post($this->url('plan', $order));

        $this->actingAs($this->manager)->post($this->url('reject', $order), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post($this->url('reject', $order), ['reason' => 'Wrong product'])->assertSessionHas('success');
        $this->assertSame(PO::REJECTED, $order->fresh()->status);

        $this->actingAs($this->planner)->post($this->url('reopen', $order))->assertSessionHas('success');
        $this->actingAs($this->planner)->post($this->url('plan', $order->fresh()))->assertSessionHas('success');

        $this->actingAs($this->manager)->post($this->url('hold', $order), ['reason' => 'Customer paused'])->assertSessionHas('success');
        $this->assertSame(PO::ON_HOLD, $order->fresh()->status);
        $this->actingAs($this->manager)->post($this->url('resume', $order))->assertSessionHas('success');
        $this->assertSame(PO::PLANNED, $order->fresh()->status);

        $this->actingAs($this->planner)->post($this->url('cancel', $order), ['reason' => 'Order withdrawn'])->assertSessionHas('success');
        $this->assertSame(PO::CANCELLED, $order->fresh()->status);
        $this->assertSame(['CREATED', 'PLANNED', 'REJECTED', 'REOPENED', 'PLANNED', 'HELD', 'RESUMED', 'CANCELLED'],
            $order->events()->reorder('id')->pluck('action')->all());
    }

    // ── End to end ──────────────────────────────────────────────────────────────────────────

    public function test_an_order_runs_from_draft_to_closed_through_the_screens(): void
    {
        $this->stockUp();
        // This plant puts good output straight away; quarantine is MFG-009's flow.
        $this->asCompany($this->company, fn () => FactoryPolicy::for($this->plant->id, $this->company->id)
            ->update(['require_qc_before_putaway' => false]));

        $order = $this->raiseThroughTheScreen(10);
        $this->actingAs($this->planner)->post($this->url('plan', $order))->assertSessionHas('success');
        $this->actingAs($this->manager)->post($this->url('approve', $order))->assertSessionHas('success');
        $this->actingAs($this->manager)->post($this->url('release', $order))->assertSessionHas('success');

        // The operator sees the floor actions, and nothing that is the manager's.
        $can = $this->actingAs($this->operator)->get($this->url('show', $order))->viewData('page')['props']['can'];
        $this->assertTrue($can['execute']);
        $this->assertTrue($can['issue']);
        $this->assertFalse($can['release']);
        $this->assertFalse($can['close']);

        $components = $order->components()->get()->keyBy('stock_item_id');
        $steel = $components[$this->steel->id];
        $bolt = $components[$this->bolt->id];

        // Issue, with a scanner-style retry that must count once.
        $issue = fn ($c, $qty, $key) => $this->actingAs($this->operator)
            ->post($this->url('components.issue', $order, ['componentUuid' => $c->uuid]), ['qty' => $qty, 'idempotency_key' => $key]);
        $issue($steel, 20, 'scan-1')->assertSessionHas('success');
        $issue($steel, 20, 'scan-1')->assertSessionHas('success');
        $issue($bolt, 40, 'scan-2')->assertSessionHas('success');
        $this->assertEquals(20.0, (float) $steel->fresh()->issued_qty, 'A retried issue was counted twice.');

        // Over-issue is refused; returning is fine.
        $issue($steel, 1, 'scan-3')->assertSessionHas('error');
        $this->actingAs($this->operator)->post($this->url('components.return', $order, ['componentUuid' => $bolt->uuid]), ['qty' => 4])
            ->assertSessionHas('success');
        $issue($bolt, 4, 'scan-4')->assertSessionHas('success');

        $ops = $order->operations()->get()->keyBy('name');

        // Out of order is refused for the operator, who holds no override.
        $this->actingAs($this->operator)->post($this->url('operations.complete', $order, ['operationUuid' => $ops['Pack']->uuid]), ['completed' => 10])
            ->assertSessionHas('error');

        $this->actingAs($this->operator)->post($this->url('operations.start', $order, ['operationUuid' => $ops['Prepare']->uuid]))->assertSessionHas('success');
        $this->assertSame(PO::IN_PRODUCTION, $order->fresh()->status, 'Starting the first operation did not start the order.');
        $this->actingAs($this->operator)->post($this->url('operations.pause', $order, ['operationUuid' => $ops['Prepare']->uuid]))->assertSessionHas('success');

        $done = fn ($op) => $this->actingAs($this->operator)->post($this->url('operations.complete', $order, ['operationUuid' => $op->uuid]),
            ['completed' => 10, 'machine_minutes' => 20, 'labour_minutes' => 20]);
        $done($ops['Prepare'])->assertSessionHas('success');
        $done($ops['Assemble'])->assertSessionHas('success');

        // The inspection point holds Pack until its gate passes.
        $done($ops['Pack'])->assertSessionHas('error');
        $page = $this->actingAs($this->operator)->get($this->url('show', $order))->viewData('page')['props'];
        $this->assertNotEmpty(collect($page['operations'])->firstWhere('name', 'Pack')['blocked_by']);
        $this->actingAs($this->operator)->post($this->url('operations.gate', $order, ['operationUuid' => $ops['Assemble']->uuid]), ['result' => 'PASSED'])
            ->assertSessionHas('success');
        $done($ops['Pack'])->assertSessionHas('success');

        $this->actingAs($this->operator)->post($this->url('output', $order), ['qty' => 10, 'lot_number' => 'WID-001', 'idempotency_key' => 'out-1'])
            ->assertSessionHas('success');
        $this->actingAs($this->operator)->post($this->url('loss', $order), ['scrapped' => 1, 'reason' => 'Dropped'])->assertSessionHas('success');

        $fg = FinStockItem::withoutGlobalScopes()->find($this->widget->stock_item_id);
        $this->assertEquals(10.0, app(StockLedger::class)->available($fg, $this->store->id));

        $this->actingAs($this->manager)->post($this->url('send-to-quality', $order))->assertSessionHas('success');
        $page = $this->actingAs($this->manager)->get($this->url('show', $order))->viewData('page')['props'];
        $this->assertSame(10.0, (float) $page['reconciliation']['good']);
        $this->assertSame(1.0, (float) $page['reconciliation']['scrapped']);
        $this->assertTrue($page['can']['complete']);

        $this->actingAs($this->manager)->post($this->url('complete', $order))->assertSessionHas('success');
        $this->actingAs($this->manager)->post($this->url('close', $order))->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(PO::CLOSED, $order->status);

        // Issued exactly the standard and received at standard: nothing left in WIP to settle.
        $page = $this->actingAs($this->manager)->get($this->url('show', $order))->viewData('page')['props'];
        $this->assertEquals(0.0, $page['order']['wip']);
        $this->assertFalse(collect($page['can'])->except('override')->contains(true), 'A closed order still offered an action.');
    }

    // ── Boundaries ──────────────────────────────────────────────────────────────────────────

    public function test_another_tenants_factory_or_a_foreign_lot_cannot_be_named(): void
    {
        $rival = Company::create(['name' => 'Rival Works']);
        $theirs = $this->asCompany($rival, fn () => Factory::create([
            'company_id' => $rival->id, 'code' => 'RIV', 'name' => 'Rival plant', 'is_active' => true,
        ]));

        $this->actingAs($this->planner)->post(route('manufacturing.production.orders.store'), [
            'item_uuid' => $this->widget->uuid, 'factory_uuid' => $theirs->uuid, 'quantity' => 1,
            'due_date' => now()->addDay()->toDateString(), 'warehouse_id' => $this->store->id,
        ])->assertSessionHasErrors('factory_uuid');

        // A lot of a different material cannot be issued as this one.
        $this->stockUp();
        $order = $this->raiseThroughTheScreen();
        $this->actingAs($this->planner)->post($this->url('plan', $order));
        $this->actingAs($this->manager)->post($this->url('approve', $order));
        $this->actingAs($this->manager)->post($this->url('release', $order));

        $boltLot = $this->asCompany($this->company, fn () => app(StockLedger::class)->lot($this->bolt->fresh(), 'B-LOT'));
        $steel = $order->components()->where('stock_item_id', $this->steel->id)->first();

        $this->actingAs($this->operator)->post($this->url('components.issue', $order, ['componentUuid' => $steel->uuid]),
            ['qty' => 1, 'lot_id' => $boltLot->id])->assertSessionHas('error', 'That lot is not a lot of this material.');
        $this->assertEquals(0.0, (float) $steel->fresh()->issued_qty);
    }
}
