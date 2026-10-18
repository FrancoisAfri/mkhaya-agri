<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\ApprovalLimit;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryPolicy;
use App\Modules\Operations\Manufacturing\Models\NumberSequence;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\Shutdown;
use App\Modules\Operations\Manufacturing\Models\Uom;
use App\Modules\Operations\Manufacturing\Models\UomConversion;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use Illuminate\Support\Facades\DB;

/**
 * MFG-001, end to end, as the person who actually does the configuring.
 *
 * Every configuration screen is one ability (`configure`), held by one kind of person. The planner
 * and the engineer hold none of it, and the tests say so for every action rather than for one.
 */
class FactoryConfigurationFlowTest extends ManufacturingTestCase
{
    private function as()
    {
        return $this->actingAs($this->configurator);
    }

    // ── Factories ────────────────────────────────────────────────────────────────────────────

    public function test_a_factory_with_its_departments_and_lines_can_be_set_up(): void
    {
        $this->as()->post(route('manufacturing.setup.factories.store'), [
            'code' => 'JHB1', 'name' => 'Johannesburg Plant', 'timezone' => 'Africa/Johannesburg',
            'currency' => 'ZAR', 'measurement_system' => 'METRIC',
        ])->assertSessionHasNoErrors();

        $factory = $this->asCompany($this->company, fn () => Factory::where('code', 'JHB1')->firstOrFail());

        $this->as()->post(route('manufacturing.setup.departments.store', $factory->uuid),
            ['code' => 'EXT', 'name' => 'Extrusion'])->assertSessionHasNoErrors();
        $this->as()->post(route('manufacturing.setup.lines.store', $factory->uuid),
            ['code' => 'L1', 'name' => 'Line One'])->assertSessionHasNoErrors();

        $this->assertSame(1, $factory->departments()->count());
        $this->assertSame(1, $factory->productionLines()->count());
    }

    public function test_two_factories_cannot_share_a_code_inside_one_tenant(): void
    {
        $this->factory('JHB1');

        $this->as()->post(route('manufacturing.setup.factories.store'), [
            'code' => 'JHB1', 'name' => 'Duplicate', 'timezone' => 'Africa/Johannesburg',
            'currency' => 'ZAR', 'measurement_system' => 'METRIC',
        ])->assertSessionHasErrors('code');
    }

    public function test_a_factory_with_work_centres_is_deactivated_not_deleted(): void
    {
        $factory = $this->factory('DBN1');
        $this->asCompany($this->company, fn () => WorkCentre::create([
            'company_id' => $this->company->id, 'factory_id' => $factory->id,
            'code' => 'WC1', 'name' => 'Press', 'is_active' => true,
        ]));

        $this->as()->delete(route('manufacturing.setup.factories.destroy', $factory->uuid));

        $row = $this->asCompany($this->company, fn () => Factory::withTrashed()->find($factory->id));
        $this->assertNotNull($row, 'The factory was removed although its work centres carry history.');
        $this->assertNull($row->deleted_at);
        $this->assertFalse((bool) $row->is_active);
    }

    public function test_an_empty_factory_is_deleted(): void
    {
        $factory = $this->factory('EMPTY');

        $this->as()->delete(route('manufacturing.setup.factories.destroy', $factory->uuid));

        $this->assertSame(0, $this->asCompany($this->company, fn () => Factory::where('id', $factory->id)->count()));
    }

    // ── Work centres ─────────────────────────────────────────────────────────────────────────

    public function test_work_centre_codes_are_unique_inside_a_factory_but_not_across_them(): void
    {
        $a = $this->factory('PLA');
        $b = $this->factory('PLB');

        $make = fn (Factory $f, string $code) => $this->as()->post(route('manufacturing.setup.work-centres.store'), [
            'factory_uuid' => $f->uuid, 'code' => $code, 'name' => 'Press',
        ]);

        $make($a, 'PRESS')->assertSessionHasNoErrors();
        $make($a, 'PRESS')->assertSessionHasErrors('code');   // same factory: refused
        $make($b, 'PRESS')->assertSessionHasNoErrors();        // another factory: fine
    }

    public function test_a_work_centre_with_no_rate_is_not_rated(): void
    {
        $factory = $this->factory('RATE');

        $this->as()->post(route('manufacturing.setup.work-centres.store'), [
            'factory_uuid' => $factory->uuid, 'code' => 'LATHE', 'name' => 'Lathe',
        ])->assertSessionHasNoErrors();

        $centre = $this->asCompany($this->company, fn () => WorkCentre::where('code', 'LATHE')->firstOrFail());

        // A NULL rate is honest. 0.00 would quietly cost production at nothing.
        $this->assertNull($centre->capacity_per_hour);
        $this->assertNull($centre->cost_per_hour);
        $this->assertFalse($centre->isRated());

        $this->as()->patch(route('manufacturing.setup.work-centres.update', $centre->uuid), [
            'capacity_per_hour' => 40, 'cost_per_hour' => 185.50,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($centre->fresh()->isRated());
    }

    public function test_a_work_centre_may_be_given_a_workstation_and_a_machine(): void
    {
        $factory = $this->factory('MCH');
        $centre = $this->asCompany($this->company, fn () => WorkCentre::create([
            'company_id' => $this->company->id, 'factory_id' => $factory->id,
            'code' => 'CNC', 'name' => 'CNC cell', 'is_active' => true,
        ]));

        $this->as()->post(route('manufacturing.setup.workstations.store', $centre->uuid),
            ['code' => 'WS1', 'name' => 'Station 1'])->assertSessionHasNoErrors();

        // Same code at the same centre is refused.
        $this->as()->post(route('manufacturing.setup.workstations.store', $centre->uuid),
            ['code' => 'WS1', 'name' => 'Again'])->assertSessionHasErrors('code');

        $this->assertSame(1, $centre->workstations()->count());
    }

    // ── Shifts and shutdowns ─────────────────────────────────────────────────────────────────

    public function test_an_overnight_shift_is_paid_for_the_hours_it_runs(): void
    {
        $factory = $this->factory('NGT');

        $this->as()->post(route('manufacturing.setup.shifts.store'), [
            'factory_uuid' => $factory->uuid, 'code' => 'NIGHT', 'name' => 'Night',
            'starts_at' => '22:00', 'ends_at' => '06:00', 'crosses_midnight' => true,
            'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5],
        ])->assertSessionHasNoErrors();

        $shift = $this->asCompany($this->company, fn () => Shift::where('code', 'NIGHT')->firstOrFail());

        // 22:00 -> 06:00 is 8 h; less a 30 min unpaid break is 7.5 h = 450 min.
        $this->assertSame(450, $shift->workingMinutes());
    }

    public function test_a_day_shift_is_worked_out_without_the_overnight_flag(): void
    {
        $factory = $this->factory('DAY');

        $this->as()->post(route('manufacturing.setup.shifts.store'), [
            'factory_uuid' => $factory->uuid, 'code' => 'DAY', 'name' => 'Day',
            'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5],
        ])->assertSessionHasNoErrors();

        $this->assertSame(450, $this->asCompany($this->company,
            fn () => Shift::where('code', 'DAY')->firstOrFail())->workingMinutes());
    }

    public function test_a_shift_must_run_on_at_least_one_valid_weekday(): void
    {
        $factory = $this->factory('WKD');
        $base = ['factory_uuid' => $factory->uuid, 'code' => 'X', 'name' => 'X',
            'starts_at' => '06:00', 'ends_at' => '14:00'];

        $this->as()->post(route('manufacturing.setup.shifts.store'), $base + ['days_of_week' => []])
            ->assertSessionHasErrors('days_of_week');
        $this->as()->post(route('manufacturing.setup.shifts.store'), $base + ['days_of_week' => [8]])
            ->assertSessionHasErrors('days_of_week.0');
    }

    public function test_a_shutdown_must_end_after_it_starts_and_may_stop_the_whole_factory(): void
    {
        $factory = $this->factory('SHT');

        $this->as()->post(route('manufacturing.setup.shutdowns.store'), [
            'factory_uuid' => $factory->uuid, 'reason' => 'Backwards', 'kind' => 'PLANNED',
            'starts_at' => '2026-12-24 18:00', 'ends_at' => '2026-12-24 06:00',
        ])->assertSessionHasErrors('ends_at');

        $this->as()->post(route('manufacturing.setup.shutdowns.store'), [
            'factory_uuid' => $factory->uuid, 'reason' => 'Annual maintenance', 'kind' => 'PLANNED',
            'starts_at' => '2026-12-24 06:00', 'ends_at' => '2027-01-04 06:00',
        ])->assertSessionHasNoErrors();

        $shutdown = $this->asCompany($this->company, fn () => Shutdown::firstOrFail());

        // No line named means the whole factory stops -- not that no line is affected.
        $this->assertNull($shutdown->production_line_id);
        $this->assertTrue($shutdown->affectsLine(42));
        $this->assertTrue($shutdown->affectsLine(null));
    }

    // ── Units ────────────────────────────────────────────────────────────────────────────────

    public function test_a_new_unit_is_anchored_to_its_dimensions_base(): void
    {
        $this->as()->post(route('manufacturing.setup.units.store'), [
            'code' => 'lb', 'name' => 'Pound', 'dimension' => 'MASS', 'factor_to_base' => 453.592,
        ])->assertSessionHasNoErrors();

        $lb = $this->uom('lb');
        $this->assertFalse((bool) $lb->is_base);
        $this->assertEqualsWithDelta(453.592, (float) $lb->factor_to_base, 0.0001);
    }

    public function test_unit_codes_are_unique_inside_a_tenant(): void
    {
        $this->as()->post(route('manufacturing.setup.units.store'), [
            'code' => 'kg', 'name' => 'Another kilo', 'dimension' => 'MASS', 'factor_to_base' => 1000,
        ])->assertSessionHasErrors('code');
    }

    public function test_the_base_units_factor_cannot_be_rescaled(): void
    {
        $gram = $this->uom('g');

        $this->as()->patch(route('manufacturing.setup.units.update', $gram->uuid), ['factor_to_base' => 5])
            ->assertSessionHas('error');

        $this->assertEquals(1, $this->uom('g')->factor_to_base);
    }

    public function test_an_item_conversion_between_derivable_units_is_refused(): void
    {
        $item = $this->material('NUTS', 'ea');

        // kg and g already convert exactly. A per-item row would be a second answer, and the
        // converter prefers the item's, so one typo would silently rescale every recipe.
        $this->as()->post(route('manufacturing.setup.units.conversions.store'), [
            'stock_item_id' => $item->id, 'from_uom_id' => $this->uom('kg')->id,
            'to_uom_id' => $this->uom('g')->id, 'factor' => 999,
        ])->assertSessionHas('error');

        $this->assertSame(0, UomConversion::withoutGlobalScopes()->count());

        // But a container can have one.
        $this->as()->post(route('manufacturing.setup.units.conversions.store'), [
            'stock_item_id' => $item->id, 'from_uom_id' => $this->uom('pack')->id,
            'to_uom_id' => $this->uom('ea')->id, 'factor' => 12,
        ])->assertSessionHas('success');
    }

    public function test_the_standard_units_can_be_installed_from_the_screen(): void
    {
        DB::table('mfg_uoms')->where('company_id', $this->company->id)->where('code', 'mg')->delete();

        $this->as()->post(route('manufacturing.setup.units.install'))->assertSessionHas('success');

        $this->assertSame(1, $this->asCompany($this->company, fn () => Uom::where('code', 'mg')->count()));
    }

    // ── Numbering ────────────────────────────────────────────────────────────────────────────

    public function test_a_sequence_cannot_be_wound_back_over_numbers_already_issued(): void
    {
        $seq = $this->asCompany($this->company, fn () => NumberSequence::where('document_type', 'WORK_ORDER')->firstOrFail());
        DB::table('mfg_number_sequences')->where('id', $seq->id)->update(['next_number' => 40]);

        $this->as()->patch(route('manufacturing.setup.numbering.update', $seq->uuid), ['next_number' => 12])
            ->assertSessionHas('error');

        $this->assertSame(40, (int) $seq->fresh()->next_number);

        // Forward is fine.
        $this->as()->patch(route('manufacturing.setup.numbering.update', $seq->uuid), ['next_number' => 500])
            ->assertSessionHas('success');
        $this->assertSame(500, (int) $seq->fresh()->next_number);
    }

    public function test_a_second_sequence_for_the_same_document_and_scope_is_refused(): void
    {
        $this->as()->post(route('manufacturing.setup.numbering.store'), [
            'document_type' => 'PRODUCTION_ORDER', 'padding' => 5, 'next_number' => 1, 'reset_period' => 'YEAR',
        ])->assertSessionHas('error');
    }

    // ── Policies ─────────────────────────────────────────────────────────────────────────────

    public function test_a_factory_policy_overrides_the_company_default_and_leaves_it_alone(): void
    {
        $factory = $this->factory('POL');

        $this->as()->post(route('manufacturing.setup.policies.save'), [
            'factory_uuid' => $factory->uuid, 'scrap_tolerance_percent' => 2.5,
            'cost_variance_tolerance_percent' => 4, 'escalate_after_hours' => 8,
            'require_bom_approval' => false,
        ])->assertSessionHasNoErrors();

        $own = $this->asCompany($this->company, fn () => FactoryPolicy::where('factory_id', $factory->id)->firstOrFail());
        $default = $this->asCompany($this->company, fn () => FactoryPolicy::whereNull('factory_id')->firstOrFail());

        $this->assertEquals(2.5, $own->scrap_tolerance_percent);
        $this->assertFalse((bool) $own->require_bom_approval);

        // The company default is untouched, and its tolerances are still "none configured".
        $this->assertNull($default->scrap_tolerance_percent);
        $this->assertTrue((bool) $default->require_bom_approval);
    }

    public function test_a_blank_tolerance_means_none_configured_and_is_not_zero(): void
    {
        $default = $this->asCompany($this->company, fn () => FactoryPolicy::whereNull('factory_id')->firstOrFail());

        // No opinion: nothing to be inside or outside of.
        $this->assertNull($default->withinCostTolerance(50.0));

        $default->update(['cost_variance_tolerance_percent' => 0]);
        // A zero tolerance is a real instruction -- everything must be exact.
        $this->assertFalse($default->fresh()->withinCostTolerance(0.5));
        $this->assertTrue($default->fresh()->withinCostTolerance(0.0));
    }

    public function test_approval_bands_meet_exactly_and_the_top_band_is_open_ended(): void
    {
        foreach ([[0, 50000], [50000, null]] as [$min, $max]) {
            $this->as()->post(route('manufacturing.setup.policies.limits.store'), [
                'document_type' => 'PRODUCTION_ORDER', 'min_amount' => $min, 'max_amount' => $max,
                'required_permission' => 'mfg.bom.approve',
            ])->assertSessionHasNoErrors();
        }

        $bands = $this->asCompany($this->company, fn () => ApprovalLimit::orderBy('min_amount')->get());

        $this->assertTrue($bands[0]->covers(49999.99));
        $this->assertFalse($bands[0]->covers(50000));   // the upper bound is exclusive...
        $this->assertTrue($bands[1]->covers(50000));    // ...so the next band takes it
        $this->assertTrue($bands[1]->covers(9_000_000)); // null ceiling: and everything above
    }

    public function test_a_band_whose_ceiling_is_below_its_floor_is_refused(): void
    {
        $this->as()->post(route('manufacturing.setup.policies.limits.store'), [
            'document_type' => 'PRODUCTION_ORDER', 'min_amount' => 1000, 'max_amount' => 500,
            'required_permission' => 'mfg.bom.approve',
        ])->assertSessionHasErrors('max_amount');
    }

    // ── Who may configure ────────────────────────────────────────────────────────────────────

    public function test_nobody_but_a_configurator_can_change_the_plant(): void
    {
        $factory = $this->factory('GUARD');

        foreach ([$this->planner, $this->engineer, $this->approver, $this->outsider] as $user) {
            $this->actingAs($user)->post(route('manufacturing.setup.factories.store'), [
                'code' => 'NOPE', 'name' => 'Nope', 'timezone' => 'UTC', 'currency' => 'ZAR',
                'measurement_system' => 'METRIC',
            ])->assertForbidden();

            $this->actingAs($user)->patch(route('manufacturing.setup.factories.update', $factory->uuid),
                ['code' => 'GUARD', 'name' => 'Hijacked', 'timezone' => 'UTC', 'currency' => 'ZAR',
                    'measurement_system' => 'METRIC'])->assertForbidden();

            $this->actingAs($user)->post(route('manufacturing.setup.units.install'))->assertForbidden();
            $this->actingAs($user)->get(route('manufacturing.setup.factories'))->assertForbidden();
        }

        $this->assertSame("Plant GUARD", $this->asCompany($this->company, fn () => Factory::find($factory->id)->name));
    }

    public function test_configuration_changes_are_on_the_audit_trail(): void
    {
        $factory = $this->factory('AUD');

        $this->as()->patch(route('manufacturing.setup.factories.update', $factory->uuid), [
            'code' => 'AUD', 'name' => 'Renamed Plant', 'timezone' => 'Africa/Johannesburg',
            'currency' => 'ZAR', 'measurement_system' => 'METRIC',
        ])->assertSessionHasNoErrors();

        $logged = DB::table('activity_log')->where('log_name', 'manufacturing')
            ->where('subject_type', Factory::class)->where('subject_id', $factory->id)->get();

        $this->assertNotEmpty($logged, 'MFG-001.10: a configuration change left no audit record.');
        $this->assertStringContainsString('Renamed Plant', $logged->last()->properties);
    }
}
