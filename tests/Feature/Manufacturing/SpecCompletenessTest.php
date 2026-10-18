<?php

namespace Tests\Feature\Manufacturing;

use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\Shift;
use Illuminate\Support\Facades\DB;

/**
 * The requirements a first pass left recorded but unreachable: tax settings per factory
 * (MFG-001.08), the working calendar on screen (MFG-001.04), and a product's dimensions and
 * configurable attributes (MFG-002).
 */
class SpecCompletenessTest extends ManufacturingTestCase
{
    public function test_a_factory_carries_its_own_tax_settings(): void
    {
        $this->actingAs($this->configurator)->post(route('manufacturing.setup.factories.store'), [
            'code' => 'GAB', 'name' => 'Gaborone', 'timezone' => 'Africa/Gaborone', 'currency' => 'BWP',
            'tax_code' => 'VAT14', 'tax_rate' => 14, 'tax_registration_number' => 'BW-123',
            'measurement_system' => 'METRIC',
        ])->assertSessionHasNoErrors();

        $factory = $this->asCompany($this->company, fn () => Factory::where('code', 'GAB')->firstOrFail());
        $this->assertSame('VAT14', $factory->tax_code);
        $this->assertEquals(14, $factory->tax_rate);

        // No rate given is "not configured", not zero-rated.
        $this->actingAs($this->configurator)->post(route('manufacturing.setup.factories.store'), [
            'code' => 'NEW', 'name' => 'New', 'timezone' => 'UTC', 'currency' => 'ZAR', 'measurement_system' => 'METRIC',
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->asCompany($this->company, fn () => Factory::where('code', 'NEW')->value('tax_rate')));

        $this->actingAs($this->configurator)->post(route('manufacturing.setup.factories.store'), [
            'code' => 'BAD', 'name' => 'Bad', 'timezone' => 'UTC', 'currency' => 'ZAR',
            'measurement_system' => 'METRIC', 'tax_rate' => 150,
        ])->assertSessionHasErrors('tax_rate');
    }

    public function test_a_products_dimensions_and_attributes_round_trip(): void
    {
        $item = $this->product('BRACKET');

        $this->actingAs($this->engineer)->patch(route('manufacturing.engineering.products.update', $item->uuid), [
            'dimensions' => ['length' => 120, 'width' => 40, 'height' => 3, 'uom' => 'mm'],
            'attributes' => [
                ['key' => 'Finish', 'value' => 'Hot-dip galvanised'],
                ['key' => 'Hole size', 'value' => 'M10'],
            ],
        ])->assertSessionHasNoErrors();

        // A column named `attributes` shares a name with Eloquent's own internal array; this proves
        // the cast is what is read, not the model's internals.
        $fresh = $this->asCompany($this->company, fn () => Item::find($item->id));
        $this->assertSame(['Finish' => 'Hot-dip galvanised', 'Hole size' => 'M10'], $fresh->getAttribute('attributes'));
        $this->assertEquals(['length' => 120, 'width' => 40, 'height' => 3, 'uom' => 'mm'], $fresh->dimensions);

        $page = $this->actingAs($this->planner)
            ->get(route('manufacturing.engineering.products.show', $item->uuid))
            ->assertOk()->viewData('page')['props']['item'];
        $this->assertSame(['Finish' => 'Hot-dip galvanised', 'Hole size' => 'M10'], $page['attributes']);
        $this->assertSame('mm', $page['dimensions']['uom']);
    }

    public function test_two_attributes_cannot_share_a_name_and_blank_dimensions_are_none(): void
    {
        $item = $this->product('DUP');

        $this->actingAs($this->engineer)->patch(route('manufacturing.engineering.products.update', $item->uuid), [
            'attributes' => [['key' => 'Colour', 'value' => 'Red'], ['key' => 'Colour', 'value' => 'Blue']],
        ])->assertSessionHasErrors('attributes.1.key');

        $this->actingAs($this->engineer)->patch(route('manufacturing.engineering.products.update', $item->uuid), [
            'dimensions' => ['length' => null, 'width' => null, 'height' => null, 'uom' => null],
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->asCompany($this->company, fn () => Item::find($item->id))->dimensions);
    }

    public function test_the_shifts_screen_shows_the_working_calendar(): void
    {
        $plant = $this->factory('CALS');
        $this->asCompany($this->company, fn () => Shift::create([
            'company_id' => $this->company->id, 'factory_id' => $plant->id, 'code' => 'DAY', 'name' => 'Day',
            'starts_at' => '06:00', 'ends_at' => '14:00', 'break_minutes' => 30, 'days_of_week' => [1, 2, 3, 4, 5],
            'overtime_multiplier' => 1.5, 'observes_public_holidays' => true, 'is_active' => true,
        ]));

        // A fixed fortnight starting on a Monday in November: ten working days of 450 minutes.
        $calendar = $this->actingAs($this->configurator)
            ->get(route('manufacturing.setup.shifts').'?calendar_factory='.$plant->uuid.'&calendar_from=2026-11-02')
            ->assertOk()->viewData('page')['props']['calendar'];

        $this->assertSame($plant->uuid, $calendar['factory_id']);
        $this->assertCount(14, $calendar['days']);
        $this->assertSame(10 * 450, $calendar['total_minutes']);
    }

    public function test_another_tenants_factory_cannot_be_previewed_on_the_calendar(): void
    {
        $rival = \App\Modules\Tenancy\Models\Company::create(['name' => 'Rival']);
        $theirs = $this->asCompany($rival, fn () => Factory::create([
            'company_id' => $rival->id, 'code' => 'R', 'name' => 'Rival plant', 'is_active' => true,
        ]));

        $calendar = $this->actingAs($this->configurator)
            ->get(route('manufacturing.setup.shifts').'?calendar_factory='.$theirs->uuid)
            ->assertOk()->viewData('page')['props']['calendar'];

        $this->assertNull($calendar['factory_id']);
        $this->assertSame([], $calendar['days']);
    }
}
