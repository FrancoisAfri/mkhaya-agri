<?php

namespace Tests\Feature\Manufacturing;

use App\Core\Role\Models\Role;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Finance\Accounting\Services\StockLedger;
use App\Modules\Operations\Manufacturing\Models\DowntimeEvent;
use App\Modules\Operations\Manufacturing\Models\DowntimeReason;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FloorEscalation;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\OperationTimeLog;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder as PO;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\ManufacturingReferenceData;
use App\Modules\Operations\Manufacturing\Services\ProductionOrderLifecycle;
use App\Modules\Operations\Manufacturing\Services\ProductionOrders;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;

/**
 * MFG-007 -- the shop floor through the terminal's own endpoints: queues, timed work, stoppages,
 * scanning, escalation, and every write applied once however often a tablet resends it.
 */
class ShopFloorTest extends ManufacturingTestCase
{
    private Factory $plant;

    private FinWarehouse $store;

    private Item $widget;

    private WorkCentre $centre;

    private $operator;

    private $operator2;

    private $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openBooks();

        $operator = Role::firstOrCreate(['name' => 'mfg test floor operator', 'guard_name' => 'web']);
        $operator->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ORDERS, RoleCatalog::EXECUTE, RoleCatalog::ISSUE_MATERIAL]);
        $supervisor = Role::firstOrCreate(['name' => 'mfg test floor supervisor', 'guard_name' => 'web']);
        $supervisor->syncPermissions([RoleCatalog::VIEW_DASHBOARD, RoleCatalog::VIEW_ORDERS, RoleCatalog::EXECUTE, RoleCatalog::SUPERVISE_FLOOR]);
        $this->operator = $this->makeUser('mfg-op1@example.test', $operator);
        $this->operator2 = $this->makeUser('mfg-op2@example.test', $operator);
        $this->supervisor = $this->makeUser('mfg-sup@example.test', $supervisor);
        foreach ([$this->operator, $this->operator2, $this->supervisor] as $u) {
            $this->grantModuleTier($u);
        }

        $this->plant = $this->factory('PLT');
        $this->store = $this->warehouse('STORE');
        $this->asCompany($this->company, fn () => Shift::create([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'D', 'name' => 'Day',
            'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'overtime_multiplier' => 1.5, 'observes_public_holidays' => false, 'is_active' => true,
        ]));
        $this->asCompany($this->company, fn () => app(ManufacturingReferenceData::class)->installFor($this->company->id));

        $steel = $this->stockItem('STEEL', 'kg', 10);
        $fg = $this->stockItem('WIDGET', 'ea', 0);
        $this->widget = $this->asCompany($this->company, fn () => Item::create([
            'company_id' => $this->company->id, 'stock_item_id' => $fg->id, 'kind' => 'FINISHED',
            'default_uom_id' => $this->uom('ea')->id, 'is_active' => true,
        ]));
        $this->approvedBom($this->widget, [[$steel, 2, 0, 'kg']]);
        $this->centre = $this->asCompany($this->company, fn () => WorkCentre::create([
            'company_id' => $this->company->id, 'factory_id' => $this->plant->id, 'code' => 'ASM', 'name' => 'Assembly',
            'cost_per_hour' => 120, 'labour_rate_per_hour' => 60, 'is_active' => true,
        ]));
        $this->routing($this->widget, [
            ['Cut', 10, 1, ['work_centre_id' => $this->centre->id, 'instructions' => 'Cut to 400 mm, deburr both ends.']],
            ['Weld', 15, 2, ['work_centre_id' => $this->centre->id]],
        ], [], true);

        $this->asCompany($this->company, fn () => app(StockLedger::class)->receive($steel->fresh(), 100, 10, $this->store, ['reference' => 'GRN', 'lot_number' => null]));
    }

    private function released(?int $supervisorId = null): PO
    {
        return $this->asCompany($this->company, function () use ($supervisorId) {
            $order = app(ProductionOrders::class)->create(['item' => $this->widget, 'quantity' => 10, 'supervisor_id' => $supervisorId,
                'due_date' => now()->addDays(5)->toDateString(), 'factory' => $this->plant, 'warehouse' => $this->store], $this->engineer);
            $life = app(ProductionOrderLifecycle::class);
            $life->plan($order, $this->engineer);
            $life->approve($order, $this->approver);
            $life->release($order, $this->approver);

            return $order->fresh();
        });
    }

    private function tap(string $name, array|string $params, array $data = [], $user = null, ?string $key = null)
    {
        // A fresh session each time: the tests travel through hours of shift, which would otherwise
        // trip the idle-session lock and answer 423 instead of the floor's own response.
        $this->flushSession();

        return $this->actingAs($user ?? $this->operator)
            ->withHeaders($key ? ['Idempotency-Key' => $key] : [])
            ->postJson(route("manufacturing.floor.{$name}", $params), $data);
    }

    public function test_the_terminal_and_its_hub_cards_are_for_the_floor(): void
    {
        $page = $this->actingAs($this->operator)->get(route('manufacturing.floor.terminal'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Floor/Terminal', $page['component']);
        $this->assertContains('ASM', array_column($page['props']['work_centres'], 'code'));
        $this->assertNotEmpty($page['props']['reasons'], 'The standard downtime reasons were not installed.');
        $this->assertFalse($page['props']['can']['supervise']);

        $cards = array_column($this->actingAs($this->operator)->get('/manufacturing/production')->viewData('page')['props']['cards'], 'path');
        foreach (['/manufacturing/floor', '/manufacturing/floor/downtime', '/manufacturing/floor/escalations'] as $path) {
            $this->assertContains($path, $cards);
        }

        $this->actingAs($this->engineer)->get(route('manufacturing.floor.terminal'))->assertForbidden();
        $this->actingAs($this->outsider)->getJson(route('manufacturing.floor.queue'))->assertForbidden();
    }

    public function test_the_queue_shows_what_can_be_worked_on_and_a_job_carries_its_instructions(): void
    {
        $order = $this->released();

        $jobs = $this->actingAs($this->operator)->getJson(route('manufacturing.floor.queue', ['work_centre' => $this->centre->id]))->assertOk()->json('jobs');
        $this->assertSame(['Cut', 'Weld'], array_column($jobs, 'name'));
        $this->assertSame('READY', $jobs[0]['status']);
        $this->assertSame('PENDING', $jobs[1]['status']);
        $this->assertSame($order->number, $jobs[0]['order']['number']);

        $job = $this->actingAs($this->operator)->getJson(route('manufacturing.floor.operations.show', $jobs[0]['id']))->assertOk()->json();
        $this->assertSame('Cut to 400 mm, deburr both ends.', $job['instructions']);
        $this->assertSame('STEEL', $job['components'][0]['code']);
        $this->assertEquals(20.0, $job['components'][0]['outstanding']);
    }

    public function test_work_is_timed_a_stoppage_is_downtime_and_completion_uses_the_measured_minutes(): void
    {
        $this->freezeTime(); // only the travels below move the clock
        $order = $this->released();
        $cut = $order->operations()->where('name', 'Cut')->first();
        $breakdown = $this->asCompany($this->company, fn () => DowntimeReason::where('code', 'BRK')->sole());

        $this->tap('operations.start', $cut->uuid, [], null, 'k-start')->assertOk();
        $this->assertSame(PO::IN_PRODUCTION, $order->fresh()->status);

        $this->travel(12)->minutes();
        $this->tap('operations.pause', $cut->uuid, ['reason_id' => $breakdown->id, 'notes' => 'Blade snapped'], null, 'k-pause')->assertOk();
        $stop = $this->asCompany($this->company, fn () => DowntimeEvent::where('order_operation_id', $cut->id)->sole());
        $this->assertTrue($stop->isOpen());

        $this->travel(30)->minutes();
        $this->tap('operations.start', $cut->uuid, [], null, 'k-resume')->assertOk();
        $this->assertFalse($stop->fresh()->isOpen(), 'Resuming did not end the stoppage.');
        $this->assertEquals(30.0, (float) $stop->fresh()->minutes);

        $this->travel(8)->minutes();
        $this->tap('operations.complete', $cut->uuid, ['completed' => 10], null, 'k-done')->assertOk();

        // Twelve minutes, then eight: twenty minutes of work, not the fifty the clock shows.
        $this->assertEquals(20.0, (float) OperationTimeLog::withoutGlobalScopes()->where('order_operation_id', $cut->id)->sum('minutes'));
        $this->assertEquals(20.0, (float) $cut->fresh()->actual_machine_minutes);
        $this->assertSame('COMPLETED', $cut->fresh()->status);

        // The downtime page totals the stoppage by its reason.
        $page = $this->actingAs($this->supervisor)->get(route('manufacturing.floor.downtime.index'))->assertOk()->viewData('page');
        $brk = collect($page['props']['by_reason'])->firstWhere('code', 'BRK');
        $this->assertEquals(30.0, $brk['minutes']);
    }

    public function test_a_resent_tap_is_applied_once(): void
    {
        $order = $this->released();
        $cut = $order->operations()->where('name', 'Cut')->first();
        $steel = $order->components()->first();

        $this->tap('operations.start', $cut->uuid, [], null, 'same-key')->assertOk();
        $again = $this->tap('operations.start', $cut->uuid, [], null, 'same-key')->assertOk()->json();
        $this->assertTrue($again['replayed']);
        $this->assertSame(1, OperationTimeLog::withoutGlobalScopes()->where('order_operation_id', $cut->id)->count());

        $this->tap('operations.issue', ['uuid' => $cut->uuid, 'componentUuid' => $steel->uuid], ['qty' => 5], null, 'issue-1')->assertOk();
        $this->tap('operations.issue', ['uuid' => $cut->uuid, 'componentUuid' => $steel->uuid], ['qty' => 5], null, 'issue-1')->assertOk();
        $this->assertEquals(5.0, (float) $steel->fresh()->issued_qty, 'A resent material issue was posted twice.');

        // A refusal is reported, not swallowed.
        $this->tap('operations.issue', ['uuid' => $cut->uuid, 'componentUuid' => $steel->uuid], ['qty' => 500], null, 'issue-2')
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'more than the order needs'));
    }

    public function test_an_assigned_job_belongs_to_its_operator_and_a_supervisor_assigns(): void
    {
        $order = $this->released();
        $cut = $order->operations()->where('name', 'Cut')->first();

        $this->tap('operations.assign', $cut->uuid, ['user_id' => $this->operator->id])->assertForbidden();
        $this->tap('operations.assign', $cut->uuid, ['user_id' => $this->operator->id], $this->supervisor, 'a1')->assertOk();
        $this->assertSame($this->operator->id, (int) $cut->fresh()->assigned_to);

        $this->tap('operations.start', $cut->uuid, [], $this->operator2, 'b1')->assertStatus(422);
        $this->tap('operations.start', $cut->uuid, [], $this->operator, 'b2')->assertOk();

        $mine = $this->actingAs($this->operator)->getJson(route('manufacturing.floor.queue', ['mine' => 1]))->json('jobs');
        $this->assertSame(['Cut'], array_column($mine, 'name'));
        $this->assertSame('ASSIGNED', $order->events()->where('action', 'ASSIGNED')->value('action'));
    }

    public function test_scanning_recognises_job_cards_orders_lots_and_items(): void
    {
        $order = $this->released();
        $cut = $order->operations()->where('name', 'Cut')->first();
        $this->asCompany($this->company, fn () => app(StockLedger::class)->lot(FinStockItem::where('code', 'STEEL')->first(), 'HEAT-77'));

        $this->tap('scan', [], ['code' => 'JOB:'.$cut->uuid])->assertOk()->assertJson(['type' => 'operation', 'id' => $cut->uuid]);
        $this->tap('scan', [], ['code' => $order->number])->assertOk()->assertJson(['type' => 'order', 'id' => $order->uuid]);
        $this->tap('scan', [], ['code' => 'HEAT-77'])->assertOk()->assertJson(['type' => 'lot']);
        $this->tap('scan', [], ['code' => 'STEEL'])->assertOk()->assertJson(['type' => 'item']);
        $this->tap('scan', [], ['code' => 'NOPE'])->assertNotFound();

        $card = $this->actingAs($this->operator)->get(route('manufacturing.production.orders.job-card', $order->uuid))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Floor/JobCard', $card['component']);
        $this->assertSame('JOB:'.$cut->uuid, $card['props']['operations'][0]['job_code']);
    }

    public function test_a_call_for_help_reaches_the_supervisor_and_climbs_if_nobody_answers(): void
    {
        $order = $this->released($this->supervisor->id);
        $cut = $order->operations()->where('name', 'Cut')->first();

        $this->tap('escalations.store', [], ['kind' => 'MATERIAL', 'message' => 'Out of 3 mm plate', 'operation' => $cut->uuid], null, 'e1')->assertOk();
        $e = $this->asCompany($this->company, fn () => FloorEscalation::sole());
        $this->assertSame($this->supervisor->id, (int) $e->assigned_to);
        $this->assertSame(1, $e->level);

        $this->travel(31)->minutes();
        $this->artisan('mfg:escalate-floor', ['--company' => $this->company->id])->assertSuccessful();
        $this->assertSame(2, $e->fresh()->level, 'An unanswered escalation did not climb.');

        $this->flushSession(); // the jump forward made the session idle-locked
        $this->actingAs($this->operator)->post(route('manufacturing.floor.escalations.acknowledge', $e->uuid))->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('manufacturing.floor.escalations.acknowledge', $e->uuid))->assertSessionHas('success');
        $this->travel(60)->minutes();
        $this->artisan('mfg:escalate-floor', ['--company' => $this->company->id])->assertSuccessful();
        $this->assertSame(2, $e->fresh()->level, 'An acknowledged escalation kept climbing.');
        $this->flushSession();

        $this->actingAs($this->supervisor)->post(route('manufacturing.floor.escalations.resolve', $e->uuid), ['resolution' => 'Plate delivered from store B'])
            ->assertSessionHas('success');
        $this->assertSame('RESOLVED', $e->fresh()->status);

        $page = $this->actingAs($this->supervisor)->get(route('manufacturing.floor.escalations.index').'?status=RESOLVED')->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Floor/Escalations', $page['component']);
        $this->assertCount(1, $page['props']['escalations']['data']);
    }

    public function test_notes_and_stoppages_without_a_job_are_recorded(): void
    {
        $order = $this->released();
        $cut = $order->operations()->where('name', 'Cut')->first();
        $utilities = $this->asCompany($this->company, fn () => DowntimeReason::where('code', 'UTL')->sole());

        $this->tap('operations.note', $cut->uuid, ['text' => 'Batch of plate is slightly warped'], null, 'n1')->assertOk();
        $this->assertSame('Batch of plate is slightly warped', $order->events()->where('action', 'NOTE')->value('reason'));

        $stop = $this->tap('downtime.start', [], ['work_centre' => $this->centre->id, 'reason_id' => $utilities->id], null, 'd1')->assertOk()->json();
        $this->assertTrue($stop['is_open']);
        $queue = $this->actingAs($this->operator)->getJson(route('manufacturing.floor.queue', ['work_centre' => $this->centre->id]))->json();
        $this->assertCount(1, $queue['downtime']);
        $this->tap('downtime.end', $stop['id'], [], null, 'd2')->assertOk()->assertJson(['is_open' => false]);
    }

    public function test_reason_codes_are_configuration_and_operators_cannot_change_engineering(): void
    {
        $this->actingAs($this->operator)->post(route('manufacturing.floor.downtime.reasons.store'), ['code' => 'X', 'name' => 'X', 'category' => 'OTHER'])->assertForbidden();
        $this->actingAs($this->configurator)->post(route('manufacturing.floor.downtime.reasons.store'), [
            'code' => 'tool', 'name' => 'Tool change', 'category' => 'CHANGEOVER', 'is_planned' => false, 'is_active' => true,
        ])->assertSessionHas('success');
        $this->assertTrue($this->asCompany($this->company, fn () => DowntimeReason::where('code', 'TOOL')->exists()));

        // Recording production grants nothing over the recipe (MFG-007).
        $bom = $this->asCompany($this->company, fn () => \App\Modules\Operations\Manufacturing\Models\Bom::where('item_id', $this->widget->id)->first());
        $this->actingAs($this->operator)->patch(route('manufacturing.engineering.boms.update', $bom->uuid), ['name' => 'hack'])->assertForbidden();
    }
}
