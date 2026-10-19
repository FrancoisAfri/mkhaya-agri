<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\LivestockCountChange;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Spec Module C: herd and flock register, health with withdrawal periods, exits and counts.
 */
class LivestockTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    private function group($farm, string $code, string $species, int $head = 0): LivestockGroup
    {
        $this->actingAs($this->manager)->post(route('agri.livestock.groups.store'), [
            'farm_id' => $farm->uuid, 'species_id' => $this->speciesId($species), 'code' => $code, 'name' => "Group {$code}",
            'purpose' => 'breeding', 'head_count' => $head,
        ])->assertSessionHasNoErrors();

        return LivestockGroup::withoutGlobalScopes()->where('code', $code)->firstOrFail();
    }

    private function animal($farm, string $tag, array $o = []): Animal
    {
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.store'), $o + [
            'farm_id' => $farm->uuid, 'species_id' => $this->speciesId('CATTLE'), 'tag_number' => $tag, 'sex' => 'female',
            'acquisition' => 'born', 'birth_date' => today()->subYears(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        return Animal::withoutGlobalScopes()->where('tag_number', $tag)->firstOrFail();
    }

    public function test_tags_are_unique_per_species_across_the_organisations_farms_but_free_in_another_organisation(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $this->member($this->manager, $north);
        $this->member($this->manager, $south);
        $this->animal($north, 'ZA-1001');

        $this->actingAs($this->manager)->post(route('agri.livestock.animals.store'), [
            'farm_id' => $south->uuid, 'species_id' => $this->speciesId('CATTLE'), 'tag_number' => 'za-1001', 'sex' => 'male', 'acquisition' => 'purchased',
        ])->assertSessionHasErrors('tag_number');
        // A sheep may carry the same number.
        $this->animal($south, 'ZA-1001', ['species_id' => $this->speciesId('SHEEP')]);
    }

    public function test_a_birth_date_in_the_future_and_a_dam_that_is_male_are_refused(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $bull = $this->animal($farm, 'BULL-1', ['sex' => 'male']);

        $base = ['farm_id' => $farm->uuid, 'species_id' => $this->speciesId('CATTLE'), 'sex' => 'female', 'acquisition' => 'born'];
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.store'), $base + ['tag_number' => 'C1', 'birth_date' => today()->addDay()->toDateString()])
            ->assertSessionHasErrors('birth_date');
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.store'), $base + ['tag_number' => 'C2', 'dam_tag' => $bull->tag_number])
            ->assertSessionHasErrors('dam_id');
    }

    public function test_a_treated_animal_cannot_be_sold_until_its_withdrawal_period_ends(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $cow = $this->animal($farm, 'ZA-2001');

        $this->actingAs($this->manager)->post(route('agri.livestock.health.store'), [
            'farm_id' => $farm->uuid, 'animal_id' => $cow->uuid, 'event_type' => 'treatment', 'treated_on' => today()->subDays(3)->toDateString(),
            'product_name' => 'Oxytetracycline LA', 'dose' => '20 ml', 'withdrawal_days' => 28,
        ])->assertSessionHasNoErrors();
        $this->assertSame(today()->addDays(25)->toDateString(), AnimalHealthRecord::withoutGlobalScopes()->first()->withdrawal_until->toDateString());

        $this->actingAs($this->manager)->post(route('agri.livestock.animals.exit', $cow->uuid), ['status' => 'sold', 'exited_on' => today()->toDateString(), 'value' => 18000])
            ->assertSessionHasErrors('status');
        $this->assertSame('active', $cow->fresh()->status);

        // Dying is not prevented by a withdrawal period.
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.exit', $cow->uuid), ['status' => 'died', 'exited_on' => today()->toDateString(), 'reason' => 'Redwater'])
            ->assertSessionHasNoErrors();
        $this->assertSame('died', $cow->fresh()->status);
        $this->assertNotNull(Animal::withoutGlobalScopes()->find($cow->id), 'An exit never deletes the animal.');

        // And an exited animal takes no new records.
        $this->actingAs($this->manager)->post(route('agri.livestock.weights.store'), ['farm_id' => $farm->uuid, 'animal_id' => $cow->uuid,
            'weighed_on' => today()->toDateString(), 'weight_kg' => 450])->assertSessionHasErrors('animal_id');
    }

    public function test_a_sold_animal_posts_livestock_income_to_the_ledger_against_its_herd(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $herd = $this->group($farm, 'BONS', 'CATTLE');
        $ox = $this->animal($farm, 'ZA-3001', ['sex' => 'castrate', 'group_id' => $herd->uuid]);

        $this->actingAs($this->manager)->post(route('agri.livestock.animals.exit', $ox->uuid), ['status' => 'sold', 'exited_on' => today()->toDateString(),
            'value' => 21500, 'settled_by' => 'bank'])->assertSessionHasNoErrors();

        $line = DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_enterprise' = ?", ['livestock_group:'.$herd->id])->first();
        $this->assertEqualsWithDelta(21500.0, (float) $line->credit, 0.01);
        $this->assertNotNull($ox->fresh()->exit_journal_id);
    }

    public function test_a_group_count_changes_only_with_a_reason_and_never_goes_below_zero(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $flock = $this->group($farm, 'LAYERS-1', 'CHICKEN-L', 2000);
        $this->assertSame(2000, (int) $flock->head_count);
        $this->assertSame(1, LivestockCountChange::withoutGlobalScopes()->count(), 'The opening count is itself a recorded change.');

        $url = route('agri.livestock.groups.count', $flock->uuid);
        $this->actingAs($this->manager)->post($url, ['changed_on' => today()->toDateString(), 'delta' => -35, 'reason' => 'death'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($url, ['changed_on' => today()->toDateString(), 'delta' => 10, 'reason' => 'death'])->assertSessionHasErrors('delta');
        $this->actingAs($this->manager)->post($url, ['changed_on' => today()->toDateString(), 'delta' => -5000, 'reason' => 'sale'])->assertSessionHasErrors('delta');

        $this->assertSame(1965, (int) $flock->fresh()->head_count);
        $this->assertSame(1965, (int) LivestockCountChange::withoutGlobalScopes()->latest('id')->first()->count_after);
    }

    public function test_a_vaccine_drawn_from_the_farm_store_is_issued_and_costed_to_the_herd(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $herd = $this->group($farm, 'NGUNI', 'CATTLE');
        $vaccine = $this->stockItem('VAC-LSD', 'dose', 18);
        $this->stockOnFarm($farm, $vaccine, 200, 18);

        $this->actingAs($this->manager)->post(route('agri.livestock.health.store'), [
            'farm_id' => $farm->uuid, 'group_id' => $herd->uuid, 'event_type' => 'vaccination', 'treated_on' => today()->toDateString(),
            'stock_item_id' => $vaccine->id, 'quantity' => 60, 'head_count' => 60, 'next_due_on' => today()->addYear()->toDateString(),
        ])->assertSessionHasNoErrors();

        $h = AnimalHealthRecord::withoutGlobalScopes()->first();
        $this->assertEqualsWithDelta(1080.0, (float) $h->cost, 0.01);
        $this->assertSame(1, DB::table('fin_stock_movements')->where('source_type', 'agri_health')->count());
        $this->assertEqualsWithDelta(1080.0, (float) DB::table('fin_journal_lines')->whereRaw("dimensions->>'agri_enterprise' = ?", ['livestock_group:'.$herd->id])->sum('debit'), 0.01);
    }

    public function test_animals_of_other_farms_and_other_organisations_are_invisible(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $this->member($this->manager, $north);
        $this->member($this->manager, $south);
        $southCow = $this->animal($south, 'S-1');
        $this->member($this->worker, $north, 'worker');

        $this->actingAs($this->worker)->get(route('agri.livestock.animals.show', $southCow->uuid))->assertNotFound();
        $tags = array_column($this->actingAs($this->worker)->get(route('agri.livestock.index'))->viewData('page')['props']['animals'], 'tag_number');
        $this->assertNotContains('S-1', $tags);

        $other = Company::create(['name' => 'Ander Boerdery']);
        $this->assertSame(0, $this->asCompany($other, fn () => Animal::withoutGlobalScope('farm_access')->count()));
    }
}
