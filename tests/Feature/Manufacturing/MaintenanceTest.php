<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Assets\Models\Assets;
use App\Modules\Assets\Models\AssetWorkOrder;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FaultCode;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\MachineProfile;
use App\Modules\Operations\Manufacturing\Models\OeeSetting;
use App\Modules\Operations\Manufacturing\Models\PmRule;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\Maintenance;
use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use App\Modules\Operations\Manufacturing\Services\Oee;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Services\ShopFloor;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * MFG-013 -- preventive maintenance on calendar, run hours and breakdowns; breakdowns with fault
 * codes and corrective work orders in the asset module; holds that stop the floor until a machine
 * is returned to service; and OEE from stated definitions, with missing data flagged.
 */
class MaintenanceTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private WorkCentre $centre;

    private Assets $press;

    private Item $widget;

    private $planner2;

    private $technician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $tech = Role::firstOrCreate(['name' => 'mfg test maintenance technician', 'guard_name' => 'web']);
        $tech->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_MAINTENANCE, RoleCatalog::MANAGE_MAINTENANCE, RoleCatalog::RELEASE_MACHINE, RoleCatalog::EXECUTE]);
        $this->technician = $this->makeUser('mfg-tech@example.test', $tech);
        $this->grantModuleTier($this->technician);

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, function () {
            Shift::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
                'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 0, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
                'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true]);
            app(ManufacturingReferenceData::class)->installFor($this->company->id);
        });

        $this->centre = $this->asCompany($this->company, fn () => WorkCentre::create([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'PRS', 'name' => 'Press line',
            'cost_per_hour' => 100, 'labour_rate_per_hour' => 50, 'is_active' => true,
        ]));
        $this->press = $this->asCompany($this->company, fn () => Assets::factory()->create(['company_id' => $this->company->id, 'name' => 'Hydraulic press 1']));
        DB::table('mfg_work_centre_machines')->insert(['company_id' => $this->company->id, 'work_centre_id' => $this->centre->id,
            'asset_id' => $this->press->id, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        $steel = $this->stockItem('STEEL', 'kg', 10);
        $this->widget = $this->asCompany($this->company, fn () => Item::create([
            'company_id' => $this->company->id, 'stock_item_id' => $this->stockItem('WIDGET', 'ea', 0)->id, 'kind' => 'FINISHED',
            'default_uom_id' => $this->uom('ea')->id, 'is_active' => true,
        ]));
        $this->approvedBom($this->widget, [[$steel, 1, 0, 'kg']]);
        $this->routing($this->widget, [['Press', 0, 2, ['work_centre_id' => $this->centre->id]]], [], true);
    }

    private function maintenance(): Maintenance { return app(Maintenance::class); }

    private function releasedOrder(float $qty = 10): PO
    {
        return $this->asCompany($this->company, function () use ($qty) {
            FactoryPolicyHelper::relax($this->plant->id, $this->company->id);
            $order = app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => $qty,
                'due_date' => now()->addDay()->toDateString(), 'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->engineer);
            $life->approve($order, $this->approver);
            $life->release($order, $this->approver);

            return $order->fresh();
        });
    }

    public function test_a_calendar_rule_raises_one_work_order_when_due_and_never_a_second_while_it_is_open(): void
    {
        $rule = $this->asCompany($this->company, fn () => PmRule::create(['company_id' => $this->company->id, 'asset_id' => $this->press->id,
            'title' => 'Hydraulic oil change', 'kind' => 'TIME', 'interval_days' => 90, 'tasks' => "Drain oil\nReplace filter\nRefill and test",
            'work_order_type' => 'preventive', 'priority' => 'medium', 'is_active' => true]));

        $this->assertSame([], $this->asCompany($this->company, fn () => $this->maintenance()->generateDue($this->company->id)), 'A rule fired before it was due.');

        $this->travel(91)->days();
        $raised = $this->asCompany($this->company, fn () => $this->maintenance()->generateDue($this->company->id));
        $this->assertCount(1, $raised);
        $this->assertSame('90 days', $raised[0]['trigger']);

        $wo = AssetWorkOrder::withoutGlobalScopes()->where('asset_id', $this->press->id)->sole();
        $this->assertSame('preventive', $wo->type);
        $this->assertSame(3, $wo->tasks()->count(), 'The checklist did not become the work order\'s tasks.');

        $this->travel(91)->days();
        $this->assertSame([], $this->asCompany($this->company, fn () => $this->maintenance()->generateDue($this->company->id)),
            'A second work order was raised while the first is still open.');

        $wo->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertCount(1, $this->asCompany($this->company, fn () => $this->maintenance()->generateDue($this->company->id)));
    }

    public function test_a_usage_rule_counts_the_hours_the_machine_actually_ran(): void
    {
        $this->asCompany($this->company, fn () => PmRule::create(['company_id' => $this->company->id, 'asset_id' => $this->press->id,
            'title' => 'Seal inspection', 'kind' => 'USAGE', 'interval_run_hours' => 2, 'run_hours_at_last' => 0,
            'work_order_type' => 'inspection', 'priority' => 'low', 'is_active' => true]));

        $order = $this->releasedOrder();
        $op = $order->operations()->first();
        $this->freezeTime();
        $this->asCompany($this->company, fn () => app(ShopFloor::class)->start($op, $this->engineer));
        $this->travel(90)->minutes();
        $this->asCompany($this->company, fn () => app(ShopFloor::class)->pause($op->fresh(), $this->engineer));

        $this->assertEquals(1.5, $this->asCompany($this->company, fn () => $this->maintenance()->runHours($this->press)));
        $this->assertSame([], $this->asCompany($this->company, fn () => $this->maintenance()->generateDue($this->company->id)));

        $this->asCompany($this->company, fn () => app(ShopFloor::class)->start($op->fresh(), $this->engineer));
        $this->travel(45)->minutes();
        $this->asCompany($this->company, fn () => app(ShopFloor::class)->pause($op->fresh(), $this->engineer));

        $raised = $this->asCompany($this->company, fn () => $this->maintenance()->generateDue($this->company->id));
        $this->assertSame('2.3 run hours', $raised[0]['trigger']);
    }

    public function test_a_breakdown_raises_a_corrective_work_order_holds_the_machine_and_stops_the_floor(): void
    {
        $fault = $this->asCompany($this->company, fn () => FaultCode::create(['company_id' => $this->company->id, 'code' => 'HYD-LEAK', 'name' => 'Hydraulic leak', 'category' => 'HYDRAULIC', 'is_active' => true]));
        $order = $this->releasedOrder();

        $event = $this->asCompany($this->company, fn () => $this->maintenance()->reportBreakdown($this->centre, $this->press, $fault, 'Oil on the floor', true, $this->engineer));
        $this->assertSame($fault->id, (int) $event->fault_code_id);
        $wo = AssetWorkOrder::withoutGlobalScopes()->findOrFail($event->work_order_id);
        $this->assertSame('corrective', $wo->type);
        $this->assertSame('high', $wo->priority);
        $this->assertTrue($this->asCompany($this->company, fn () => $this->maintenance()->profile($this->press)->isHeld()));

        // The floor cannot start work at a centre whose only machine is held.
        try {
            $this->asCompany($this->company, fn () => app(ShopFloor::class)->start($order->operations()->first(), $this->engineer));
            $this->fail('Work started on a held machine.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('on hold', $e->getMessage());
        }

        // Not back in service while the repair is open, nor without the check recorded.
        foreach ([[$this->technician, 'Leak fixed', 'Repairs are still open']] as [$who, $notes, $expect]) {
            try {
                $this->asCompany($this->company, fn () => $this->maintenance()->release($this->press, $notes, $who));
                $this->fail('A machine was returned to service with its repair still open.');
            } catch (\DomainException $e) {
                $this->assertStringContainsString($expect, $e->getMessage());
            }
        }

        $wo->update(['status' => 'completed', 'completed_at' => now(), 'total_cost' => 850]);
        $this->asCompany($this->company, fn () => $this->maintenance()->release($this->press, 'Seal replaced, pressure tested at 200 bar', $this->technician));
        $this->assertFalse($this->asCompany($this->company, fn () => $this->maintenance()->profile($this->press)->isHeld()));
        $this->asCompany($this->company, fn () => app(ShopFloor::class)->start($order->operations()->first(), $this->engineer));

        $summary = $this->asCompany($this->company, fn () => $this->maintenance()->summary($this->press, now()->subDay(), now()->addDay()));
        $this->assertSame(1, $summary['breakdowns']);
        $this->assertEquals(850.0, $summary['maintenance_cost']);
    }

    public function test_repeated_breakdowns_trigger_a_condition_rule(): void
    {
        $this->asCompany($this->company, fn () => PmRule::create(['company_id' => $this->company->id, 'asset_id' => $this->press->id,
            'title' => 'Root-cause overhaul', 'kind' => 'CONDITION', 'breakdowns_threshold' => 2, 'window_days' => 30,
            'work_order_type' => 'inspection', 'priority' => 'high', 'is_active' => true]));

        foreach (range(1, 2) as $i) {
            $this->asCompany($this->company, fn () => $this->maintenance()->reportBreakdown($this->centre, $this->press, null, "Stop {$i}", false, $this->engineer));
        }

        $raised = $this->asCompany($this->company, fn () => $this->maintenance()->generateDue($this->company->id));
        $this->assertSame('2 breakdowns in 30 days', collect($raised)->firstWhere('rule', 'Root-cause overhaul')['trigger']);
    }

    public function test_oee_is_calculated_from_its_definitions_and_flags_what_it_cannot_know(): void
    {
        $from = now()->startOfDay();
        $to = now()->endOfDay();

        // Nothing configured, nothing made: missing, not zero and not 100%.
        $empty = $this->asCompany($this->company, fn () => app(Oee::class)->forCentre($this->centre, $from, $to));
        $this->assertNull($empty['performance']);
        $this->assertNull($empty['quality']);
        $this->assertNull($empty['oee']);
        $this->assertNotEmpty($empty['flags']);

        // 8 h shift = 480 min; 60 min unplanned stop; 400 units at 60 s ideal = 400 min; 380 good, 20 rejected.
        $this->asCompany($this->company, function () {
            OeeSetting::create(['company_id' => $this->company->id, 'work_centre_id' => $this->centre->id, 'ideal_cycle_seconds' => 60, 'exclude_planned_downtime' => true]);
            $order = $this->releasedOrder(400);
            $op = $order->operations()->first();
            $op->update(['status' => 'COMPLETED', 'qty_completed' => 380, 'qty_rejected' => 20, 'completed_at' => now()]);
            $brk = DowntimeReason::where('code', 'BRK')->sole();
            \App\Modules\Operations\Manufacturing\Models\DowntimeEvent::create(['company_id' => $this->company->id, 'factory_id' => $this->plant->id,
                'work_centre_id' => $this->centre->id, 'reason_id' => $brk->id, 'started_at' => now()->startOfDay()->addHours(8),
                'ended_at' => now()->startOfDay()->addHours(9), 'minutes' => 60]);
        });

        $oee = $this->asCompany($this->company, fn () => app(Oee::class)->forCentre($this->centre, $from, $to));
        $this->assertEquals(480.0, $oee['inputs']['planned_production_minutes']);
        $this->assertEquals(87.5, $oee['availability']);    // 420 / 480
        $this->assertEquals(95.2, $oee['performance']);     // 400 / 420
        $this->assertEquals(95.0, $oee['quality']);         // 380 / 400
        $this->assertEquals(79.2, $oee['oee']);
        $this->assertSame([], $oee['flags']);

        // An ideal cycle slower than reality would put performance over 100%: reported invalid.
        OeeSetting::withoutGlobalScopes()->where('work_centre_id', $this->centre->id)->update(['ideal_cycle_seconds' => 120]);
        $invalid = $this->asCompany($this->company, fn () => app(Oee::class)->forCentre($this->centre, $from, $to));
        $this->assertNull($invalid['performance']);
        $this->assertStringContainsString('invalid', implode(' ', $invalid['flags']));
    }

    public function test_the_maintenance_screens_and_who_may_do_what(): void
    {
        // The maintenance cards now sit in the production hub; only theirs are compared.
        $cards = array_values(array_filter(array_column($this->actingAs($this->technician)->get('/manufacturing/production')->assertOk()->viewData('page')['props']['cards'], 'path'), fn ($p) => str_starts_with($p, '/manufacturing/maintenance/')));
        $this->assertEqualsCanonicalizing(['/manufacturing/maintenance/machines', '/manufacturing/maintenance/preventive', '/manufacturing/maintenance/oee'], $cards);

        $list = $this->actingAs($this->technician)->get(route('manufacturing.maintenance.machines.index'))->assertOk()->viewData('page');
        $this->assertSame(['Hydraulic press 1'], array_column($list['props']['machines'], 'name'));

        $this->actingAs($this->technician)->post(route('manufacturing.maintenance.rules.store', $this->press->uuid), [
            'title' => 'Weekly check', 'kind' => 'TIME', 'interval_days' => 7, 'work_order_type' => 'inspection', 'priority' => 'low',
        ])->assertSessionHas('success');
        $this->actingAs($this->technician)->post(route('manufacturing.maintenance.fault-codes.store'), ['code' => 'el-01', 'name' => 'Motor overload', 'category' => 'ELECTRICAL'])
            ->assertSessionHas('success');

        $this->actingAs($this->technician)->post(route('manufacturing.maintenance.machines.hold', $this->press->uuid), ['kind' => 'SAFETY', 'reason' => 'Guard missing'])
            ->assertSessionHas('success');
        $this->actingAs($this->engineer)->post(route('manufacturing.maintenance.machines.release', $this->press->uuid), ['notes' => 'x'])->assertForbidden();
        $this->actingAs($this->technician)->post(route('manufacturing.maintenance.machines.release', $this->press->uuid), ['notes' => 'Guard refitted'])
            ->assertSessionHas('success');

        $show = $this->actingAs($this->technician)->get(route('manufacturing.maintenance.machines.show', $this->press->uuid))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Maintenance/MachineShow', $show['component']);
        $this->assertSame(['Weekly check'], array_column($show['props']['rules'], 'title'));

        $this->actingAs($this->technician)->get(route('manufacturing.maintenance.preventive'))->assertOk();
        $oee = $this->actingAs($this->technician)->get(route('manufacturing.maintenance.oee'))->assertOk()->viewData('page');
        $this->assertSame('PRS', $oee['props']['centres'][0]['work_centre']['code']);
        $this->actingAs($this->technician)->post(route('manufacturing.maintenance.oee.settings', $this->centre->id), ['ideal_cycle_seconds' => 30, 'ideal_from_routing' => false, 'exclude_planned_downtime' => true])
            ->assertSessionHas('success');

        $this->actingAs($this->outsider)->get(route('manufacturing.maintenance.machines.index'))->assertForbidden();
    }
}

/** Loosen the fixture factory so orders can be released without stock or quality gates. */
final class FactoryPolicyHelper
{
    public static function relax(int $factoryId, int $companyId): void
    {
        \App\Modules\Operations\Manufacturing\Models\FactoryPolicy::for($factoryId, $companyId)->update([
            'require_material_for_approval' => false, 'require_qc_before_putaway' => false,
        ]);
    }
}
