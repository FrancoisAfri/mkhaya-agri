<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Assets\Actions\CreateWorkOrderAction;
use App\Modules\Assets\Models\Assets;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\PlanningParam;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\RoutingOperation;
use App\Modules\Operations\Manufacturing\Models\RoutingOperationAlternate;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\Maintenance;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Services\Scheduler;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * MFG-014 -- the scheduler on a plant with known capacity: one 8-hour shift every day (480
 * minutes) at Assembly, one job at a time. A WIDGET takes 60 minutes a unit there.
 */
class ScheduleTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private WorkCentre $asm;

    private Item $widget;

    private $scheduler2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();
        $this->freezeTime();

        $role = Role::firstOrCreate(['name' => 'mfg test scheduler', 'guard_name' => 'web']);
        $role->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_PLANNING, RoleCatalog::MANAGE_PLANNING]);
        $this->scheduler2 = $this->makeUser('mfg-sched@example.test', $role);
        $this->grantModuleTier($this->scheduler2);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, function () {
            Shift::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
                'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 0, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true]);
            FactoryPolicy::for($this->plant->id, $this->company->id)->update(['require_material_for_approval' => false, 'block_on_capacity_conflict' => false]);
        });

        $this->asm = $this->asCompany($this->company, fn () => WorkCentre::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'code' => 'ASM', 'name' => 'Assembly', 'cost_per_hour' => 100, 'labour_rate_per_hour' => 50, 'is_active' => true, 'concurrent_jobs' => 1]));

        $steel = $this->stockItem('STEEL', 'kg', 10);
        $this->widget = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('WIDGET', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($this->widget, [[$steel, 1, 0, 'kg']]);
        $this->routing($this->widget, [['Assemble', 0, 60, ['work_centre_id' => $this->asm->id]]], [], true);
        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($steel->fresh(), 1000, 10, $this->store, ['reference' => 'GRN']));
    }

    private function order(float $qty, int $dueInDays): PO
    {
        return $this->asCompany($this->company, function () use ($qty, $dueInDays) {
            $o = app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => $qty, 'due_date' => now()->addDays($dueInDays)->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($o, $this->engineer);
            $life->approve($o, $this->approver);

            return $o->fresh();
        });
    }

    private function scheduleNow(array $changes = [], bool $save = false): array
    {
        return $this->asCompany($this->company, fn () => app(Scheduler::class)->schedule('DUE_DATE', 14, $changes, $save, $this->scheduler2));
    }

    private function finish(array $result, PO $o): string
    {
        return collect($result['orders'])->firstWhere('id', $o->uuid)['finish'];
    }

    public function test_orders_are_placed_in_priority_order_against_daily_capacity(): void
    {
        $a = $this->order(10, 2);   // 600 minutes, due in 2 days
        $b = $this->order(6, 5);    // 360 minutes, due in 5 days

        $r = $this->scheduleNow();

        // A takes all of day 0 and 120 minutes of day 1; B fits in the remaining 360 of day 1.
        $this->assertSame(now()->addDay()->toDateString(), $this->finish($r, $a));
        $this->assertSame(now()->addDay()->toDateString(), $this->finish($r, $b));
        $this->assertSame([], collect($r['orders'])->where('late', true)->pluck('number')->all());

        $asm = collect($r['utilisation'])->firstWhere('code', 'ASM');
        $this->assertEquals(100.0, $asm['days'][0]['percent']);
        $this->assertEquals(100.0, $asm['days'][1]['percent']);
        $this->assertSame('ASM', $r['bottleneck']);
    }

    public function test_an_alternate_work_centre_is_used_when_it_finishes_sooner(): void
    {
        // A GADGET is made at Assembly, or -- an approved alternate -- at the spare bench.
        $alt = $this->asCompany($this->company, fn () => WorkCentre::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
            'code' => 'ALT', 'name' => 'Spare bench', 'cost_per_hour' => 80, 'labour_rate_per_hour' => 50, 'is_active' => true, 'concurrent_jobs' => 1]));
        $gadget = $this->asCompany($this->company, fn () => Item::create(['company_id' => $this->company->id,
            'stock_item_id' => $this->stockItem('GADGET', 'ea', 0)->id, 'kind' => 'FINISHED', 'default_uom_id' => $this->uom('ea')->id, 'is_active' => true]));
        $this->approvedBom($gadget, [[\App\Modules\Finance\Accounting\Models\FinStockItem::withoutGlobalScopes()->where('code', 'STEEL')->first(), 1, 0, 'kg']]);
        $routing = $this->routing($gadget, [['Assemble', 0, 60, ['work_centre_id' => $this->asm->id]]]);
        $this->asCompany($this->company, function () use ($routing, $alt) {
            RoutingOperationAlternate::create(['company_id' => $this->company->id, 'operation_id' => $routing->operations()->first()->id,
                'work_centre_id' => $alt->id, 'priority' => 1]);
            DB::table('mfg_routings')->where('id', $routing->id)->update(['status' => 'APPROVED', 'is_default' => true,
                'approved_by' => $this->approver->id, 'approved_at' => now(), 'effective_from' => now()->toDateString()]);
        });

        $a = $this->order(8, 1);   // fills Assembly for today
        $b = $this->asCompany($this->company, function () use ($gadget) {
            $o = app(ProductionOrders::class)->create(['item' => $gadget, 'quantity' => 8, 'due_date' => now()->addDay()->toDateString(),
                'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            app(ProductionOrderLifecycle::class)->plan($o, $this->engineer);
            app(ProductionOrderLifecycle::class)->approve($o, $this->approver);

            return $o->fresh();
        });

        $r = $this->scheduleNow();
        $bBar = collect($r['bars'])->firstWhere('order_id', $b->uuid);
        $this->assertSame($alt->id, $bBar['centre_id'], 'The second order waited for Assembly instead of using the free alternate.');
        $this->assertTrue($bBar['alternate']);
        $this->assertSame(now()->toDateString(), $this->finish($r, $b));
        $this->assertSame($this->asm->id, collect($r['bars'])->firstWhere('order_id', $a->uuid)['centre_id']);
    }

    public function test_material_maintenance_and_lateness_are_exceptions(): void
    {
        // A material with nothing in stock and a 3-day lead time.
        $paint = $this->stockItem('PAINT', 'l', 5);
        $this->asCompany($this->company, fn () => PlanningParam::create(['company_id' => $this->company->id, 'stock_item_id' => $paint->id, 'lead_time_days' => 3]));
        $o = $this->order(4, 1);
        $this->asCompany($this->company, fn () => $o->components()->create(['company_id' => $this->company->id, 'stock_item_id' => $paint->id,
            'line_kind' => 'COMPONENT', 'required_qty' => 2, 'uom' => 'l']));

        // The only machine at Assembly is due for preventive maintenance in three days.
        $press = $this->asCompany($this->company, fn () => Assets::factory()->create(['company_id' => $this->company->id, 'name' => 'Press']));
        DB::table('mfg_work_centre_machines')->insert(['company_id' => $this->company->id, 'work_centre_id' => $this->asm->id, 'asset_id' => $press->id,
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->asCompany($this->company, fn () => app(CreateWorkOrderAction::class)->execute(['asset_id' => $press->id, 'title' => 'Service',
            'type' => 'preventive', 'scheduled_for' => now()->addDays(3)->toDateString()]));

        $r = $this->scheduleNow();
        $kinds = collect($r['exceptions'])->pluck('kind')->all();
        $this->assertContains('MATERIAL', $kinds);
        $this->assertContains('LATE', $kinds);

        // It cannot start before the paint arrives (day 3), and day 3 is lost to the service: day 4.
        $this->assertSame(now()->addDays(4)->toDateString(), collect($r['orders'])->firstWhere('id', $o->uuid)['start']);
        $asm = collect($r['utilisation'])->firstWhere('code', 'ASM');
        $this->assertEquals(0.0, $asm['days'][3]['capacity']);

        // A held machine leaves the centre with no capacity at all.
        $this->asCompany($this->company, fn () => app(Maintenance::class)->hold($press, 'SAFETY', 'Guard missing', $this->engineer));
        $this->assertContains('NO_CAPACITY', collect($this->scheduleNow()['exceptions'])->pluck('kind')->all());
    }

    public function test_a_what_if_previews_without_saving_and_applying_sets_a_baseline(): void
    {
        $a = $this->order(4, 10);
        $saved = $this->scheduleNow([], true);
        $this->assertSame(now()->toDateString(), PO::withoutGlobalScopes()->find($a->id)->sched_finish->toDateString());
        $op = Op::withoutGlobalScopes()->where('order_id', $a->id)->first();
        $this->assertSame(now()->toDateString(), $op->baseline_end->toDateString());

        // What if it may not start for 5 days?
        $preview = $this->scheduleNow([$a->uuid => ['earliest_start' => now()->addDays(5)->toDateString()]]);
        $this->assertSame(now()->addDays(5)->toDateString(), $this->finish($preview, $a));
        $this->assertSame(now()->toDateString(), PO::withoutGlobalScopes()->find($a->id)->sched_finish->toDateString(), 'A preview changed the saved schedule.');

        $this->scheduleNow([$a->uuid => ['earliest_start' => now()->addDays(5)->toDateString()]], true);
        $this->assertSame(now()->addDays(5)->toDateString(), PO::withoutGlobalScopes()->find($a->id)->sched_finish->toDateString());
        $this->assertSame(now()->toDateString(), $op->fresh()->baseline_end->toDateString(), 'Rescheduling moved the baseline.');

        // Finishing two days after the baseline counts against adherence.
        $op->fresh()->forceFill(['status' => Op::COMPLETED, 'completed_at' => now()->addDays(2)])->saveQuietly();
        $adherence = $this->asCompany($this->company, fn () => app(Scheduler::class)->adherence(now()->subDay(), now()->addDays(3)));
        $this->assertSame(1, $adherence['operations']);
        $this->assertEquals(0.0, $adherence['percent']);
        $this->assertEquals(2.0, $adherence['average_slip_days']);
    }

    public function test_the_schedule_screen(): void
    {
        $a = $this->order(4, 10);

        $cards = array_column($this->actingAs($this->scheduler2)->get('/manufacturing/planning')->viewData('page')['props']['cards'], 'path');
        $this->assertContains('/manufacturing/planning/schedule', $cards);

        $page = $this->actingAs($this->scheduler2)->get(route('manufacturing.planning.schedule'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Planning/Schedule', $page['component']);
        $this->assertSame($a->number, $page['props']['schedule']['bars'][0]['order']);

        $preview = $this->actingAs($this->scheduler2)->postJson(route('manufacturing.planning.schedule.preview'),
            ['changes' => [$a->uuid => ['earliest_start' => now()->addDays(2)->toDateString()]]])->assertOk()->json();
        $this->assertSame(now()->addDays(2)->toDateString(), collect($preview['orders'])->firstWhere('id', $a->uuid)['finish']);

        $this->actingAs($this->scheduler2)->post(route('manufacturing.planning.schedule.apply'), ['changes' => []])->assertSessionHas('success');
        $this->actingAs($this->engineer)->post(route('manufacturing.planning.schedule.apply'), [])->assertForbidden();
    }
}
