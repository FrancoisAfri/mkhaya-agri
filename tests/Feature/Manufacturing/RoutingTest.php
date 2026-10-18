<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Routing;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\RoutingCalculator;
use App\Modules\Operations\Manufacturing\Services\RoutingVersioning;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MFG-003 -- routing and production operations.
 *
 * The example routing from the spec runs through most of these: material preparation, cutting,
 * assembly, quality inspection, packaging, finished-goods receipt.
 */
class RoutingTest extends ManufacturingTestCase
{
    private function calc(): RoutingCalculator
    {
        return app(RoutingCalculator::class);
    }

    private function specExample(bool $approved = false): Routing
    {
        return $this->routing($this->product('CABINET'), [
            ['Material preparation', 15, 2],
            ['Cutting', 30, 4],
            ['Assembly', 20, 10],
            ['Quality inspection', 0, 1, ['is_inspection_point' => true, 'inspection_plan_reference' => 'QP-12']],
            ['Packaging', 10, 1],
            ['Finished-goods receipt', 0, 0.5],
        ], [], $approved);
    }

    // ── The routing engine's precedence rule ────────────────────────────────────────────────

    public function test_an_operation_requires_every_mandatory_operation_before_it(): void
    {
        $routing = $this->specExample();
        $ops = $routing->operations()->get()->keyBy('name');

        $this->assertSame([10, 20, 30, 40, 50],
            $this->calc()->prerequisites($ops['Finished-goods receipt'])->pluck('sequence')->all());
        $this->assertSame([], $this->calc()->prerequisites($ops['Material preparation'])->pluck('sequence')->all());
    }

    public function test_parallel_operations_do_not_wait_for_each_other_and_optional_ones_do_not_block(): void
    {
        $routing = $this->routing($this->product('DESK'), [
            ['Prepare', 0, 1],
            ['Paint legs', 0, 5, ['parallel_group' => 'A']],
            ['Machine top', 0, 8, ['parallel_group' => 'A']],
            ['Engrave logo', 0, 2, ['is_mandatory' => false]],
            ['Assemble', 0, 3],
        ]);
        $ops = $routing->operations()->get()->keyBy('name');

        $this->assertSame([10], $this->calc()->prerequisites($ops['Machine top'])->pluck('sequence')->all(),
            'Two operations in one parallel group made each other wait.');
        $this->assertSame([10, 20, 30], $this->calc()->prerequisites($ops['Assemble'])->pluck('sequence')->all(),
            'An optional operation blocked the next one.');
    }

    // ── Lead time and capacity ──────────────────────────────────────────────────────────────

    public function test_lead_time_follows_the_critical_path_and_capacity_counts_every_operation(): void
    {
        // Paint 10 units x 5 min = 50; machine 10 x 8 = 80; they run together, so the stage is 80.
        // Prepare 10 x 1 = 10, assemble 10 x 3 = 30. Lead time 10 + 80 + 30 = 120 minutes.
        // Capacity counts paint AND machine: 10 + 50 + 80 + 30 = 170 machine minutes.
        $routing = $this->routing($this->product('DESK2'), [
            ['Prepare', 0, 1],
            ['Paint', 0, 5, ['parallel_group' => 'A', 'machine_type' => 'BOOTH']],
            ['Machine', 0, 8, ['parallel_group' => 'A', 'machine_type' => 'CNC']],
            ['Assemble', 0, 3],
        ]);

        $e = $this->asCompany($this->company, fn () => $this->calc()->estimate($routing, 10));

        $this->assertEqualsWithDelta(120.0, $e['lead_minutes'], 0.01);
        $this->assertEqualsWithDelta(170.0, array_sum(array_column($e['capacity'], 'minutes')), 0.01);
    }

    public function test_setup_is_paid_once_per_batch_and_a_large_order_is_split_into_batches(): void
    {
        // Max batch 100. 250 units = 3 batches of 83.33. Setup 30 x 3 = 90; run 250 x 1 = 250.
        $routing = $this->routing($this->product('BATCHY'), [['Mix', 30, 1]], ['max_batch_size' => 100]);

        $e = $this->asCompany($this->company, fn () => $this->calc()->estimate($routing, 250));

        $this->assertSame(3, $e['batches']);
        $this->assertEqualsWithDelta(83.3333, $e['batch_size'], 0.001);
        $this->assertEqualsWithDelta(340.0, $e['lead_minutes'], 0.01);
    }

    public function test_an_order_below_the_minimum_batch_is_flagged(): void
    {
        $routing = $this->routing($this->product('SMALLB'), [['Mix', 0, 1]], ['min_batch_size' => 50]);

        $e = $this->asCompany($this->company, fn () => $this->calc()->estimate($routing, 20));

        $this->assertTrue($e['below_min_batch']);
        $this->assertNotEmpty($e['warnings']);
    }

    public function test_yield_runs_backwards_so_each_operation_is_timed_for_what_passes_through_it(): void
    {
        // 100 good wanted. Assembly keeps 98%: starts 102.0408. Cutting keeps 95%: starts 107.4114.
        $routing = $this->routing($this->product('YIELDY'), [
            ['Cutting', 0, 1, ['expected_yield_percent' => 95]],
            ['Assembly', 0, 1, ['expected_yield_percent' => 98]],
        ]);

        $e = $this->asCompany($this->company, fn () => $this->calc()->estimate($routing, 100));

        $this->assertEqualsWithDelta(107.4114, $e['start_quantity'], 0.001);
        $this->assertEqualsWithDelta(102.0408, $e['operations'][1]['units_in'], 0.001);
    }

    public function test_a_subcontracted_operation_takes_calendar_days_and_none_of_the_plants_capacity(): void
    {
        $routing = $this->routing($this->product('PLATED'), [
            ['Machine', 0, 2],
            ['Electroplating', 0, 0, ['is_subcontracted' => true, 'subcontract_lead_days' => 5, 'machine_type' => null]],
        ]);

        $e = $this->asCompany($this->company, fn () => $this->calc()->estimate($routing, 10));

        $this->assertSame(5, $e['subcontract_days']);
        $this->assertEqualsWithDelta(20.0, $e['lead_minutes'], 0.01);
        $this->assertCount(1, $e['capacity'], 'A subcontracted operation was counted against plant capacity.');
    }

    public function test_the_finish_date_is_worked_through_the_factory_calendar(): void
    {
        $plant = $this->factory('RTCAL');
        $this->asCompany($this->company, fn () => Shift::create([
            'company_id' => $this->company->id, 'factory_id' => $plant->id, 'code' => 'D', 'name' => 'Day',
            'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5],
            'overtime_multiplier' => 1.5, 'observes_public_holidays' => true, 'is_active' => true,
        ]));

        // 900 minutes of work at 450 a day: Friday and Monday, not Friday and Saturday.
        $routing = $this->routing($this->product('LONGJOB'), [['Weld', 0, 9]]);
        $friday = Carbon::create(2026, 11, 6);
        $this->assertTrue($friday->isFriday());

        $e = $this->asCompany($this->company, fn () => $this->calc()->estimate($routing, 100, $plant, $friday));

        $this->assertSame('2026-11-09', $e['estimated_finish']);
    }

    // ── Versioning ──────────────────────────────────────────────────────────────────────────

    public function test_an_approved_routing_cannot_be_rewritten_even_by_a_raw_query(): void
    {
        $routing = $this->specExample(true);
        $op = $routing->operations()->first();

        try {
            DB::transaction(fn () => DB::table('mfg_routing_operations')->where('id', $op->id)->update(['run_minutes' => 99]));
            $this->fail('An approved routing operation was changed.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('is approved', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('mfg_routings')->where('id', $routing->id)->update(['max_batch_size' => 5]));
            $this->fail('An approved routing header was changed.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('only superseded', $e->getMessage());
        }
    }

    public function test_revise_then_approve_supersedes_the_original_and_keeps_its_operations(): void
    {
        $original = $this->specExample(true);
        $versioning = app(RoutingVersioning::class);

        $draft = $this->asCompany($this->company, fn () => $versioning->revise($original, $this->engineer->id, 'Faster cutter'));
        $this->assertSame(6, $draft->operations()->count());

        $this->asCompany($this->company, fn () => $versioning->approve($draft, $this->approver->id));

        $this->assertSame('SUPERSEDED', $original->fresh()->status);
        $this->assertSame('APPROVED', $draft->fresh()->status);
        $this->assertTrue((bool) $draft->fresh()->is_default);
        $this->assertSame(6, $original->operations()->count());
    }

    public function test_a_routing_that_cannot_be_run_is_not_approvable(): void
    {
        $versioning = app(RoutingVersioning::class);

        $cases = [
            'no operations' => [[], 'no operations'],
            'nowhere to run' => [[['Cut', 0, 1, ['machine_type' => null]]], 'neither where it happens'],
            'gate with no permission' => [[['Sign-off', 0, 0, ['is_approval_gate' => true]]], 'names no permission'],
            'subcontract with no lead time' => [[['Plate', 0, 0, ['is_subcontracted' => true, 'machine_type' => null]]], 'no lead time'],
        ];

        foreach ($cases as $label => [$ops, $message]) {
            $routing = $this->routing($this->product('BAD-'.md5($label)), $ops);

            try {
                $this->asCompany($this->company, fn () => $versioning->approve($routing, $this->approver->id));
                $this->fail("A routing with {$label} was approved.");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $label);
            }
        }
    }

    // ── Through the screens, as the roles ───────────────────────────────────────────────────

    public function test_the_routing_process_end_to_end_through_the_screens(): void
    {
        $item = $this->product('FRAME-R');
        $plant = $this->factory('RT1');
        $centre = $this->asCompany($this->company, fn () => WorkCentre::create([
            'company_id' => $this->company->id, 'factory_id' => $plant->id, 'code' => 'SAW', 'name' => 'Saw', 'is_active' => true,
        ]));

        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.routings.store'), [
            'item_uuid' => $item->uuid, 'max_batch_size' => 50, 'standard_yield_percent' => 97,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $routing = $this->asCompany($this->company, fn () => Routing::where('item_id', $item->id)->firstOrFail());

        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.routings.operations.store', $routing->uuid), [
            'name' => 'Cutting', 'work_centre_id' => $centre->id, 'setup_minutes' => 20, 'run_minutes' => 3,
        ])->assertSessionHas('success');

        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.routings.operations.store', $routing->uuid), [
            'name' => 'Final sign-off', 'machine_type' => 'BENCH', 'is_approval_gate' => true, 'gate_permission' => 'mfg.routing.approve',
        ])->assertSessionHas('success');

        // The drafter cannot approve; the approver can.
        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.routings.approve', $routing->uuid))->assertForbidden();
        $this->actingAs($this->approver)->post(route('manufacturing.engineering.routings.approve', $routing->uuid))->assertSessionHas('success');

        $this->assertSame('APPROVED', $routing->fresh()->status);

        // The estimate the screen asks for.
        $e = $this->actingAs($this->planner)
            ->getJson(route('manufacturing.engineering.routings.estimate', $routing->uuid).'?quantity=120')
            ->assertOk()->json();
        $this->assertSame(3, $e['batches']);

        // And the page shows the routing engine's rule for each operation.
        $page = $this->actingAs($this->planner)->get(route('manufacturing.engineering.routings.show', $routing->uuid))
            ->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Engineering/RoutingShow', $page['component']);
        $this->assertSame([10], $page['props']['routing']['operations'][1]['requires']);
        $this->assertFalse($page['props']['can']['edit']);
    }

    public function test_the_routings_list_and_hub_card_render_for_a_planner(): void
    {
        $this->specExample(true);

        $page = $this->actingAs($this->planner)->get(route('manufacturing.engineering.routings.index'))->assertOk()->viewData('page');
        $this->assertSame('Modules/Manufacturing/Engineering/Routings', $page['component']);
        $this->assertCount(1, $page['props']['routings']['data']);

        $cards = array_column($this->actingAs($this->planner)->get('/manufacturing/engineering')->viewData('page')['props']['cards'], 'path');
        $this->assertContains('/manufacturing/engineering/routings', $cards);
    }

    public function test_a_minimum_batch_above_the_maximum_is_refused(): void
    {
        $this->actingAs($this->engineer)->post(route('manufacturing.engineering.routings.store'), [
            'item_uuid' => $this->product('MINMAX')->uuid, 'min_batch_size' => 100, 'max_batch_size' => 50,
        ])->assertSessionHasErrors('max_batch_size');
    }

    public function test_someone_without_routing_permissions_cannot_see_or_change_them(): void
    {
        $routing = $this->specExample();

        $this->actingAs($this->configurator)->get(route('manufacturing.engineering.routings.index'))->assertForbidden();
        $this->actingAs($this->planner)->post(route('manufacturing.engineering.routings.operations.store', $routing->uuid), ['name' => 'X'])->assertForbidden();
    }
}
