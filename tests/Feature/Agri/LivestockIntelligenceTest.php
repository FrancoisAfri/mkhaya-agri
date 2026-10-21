<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\Animal;
use App\Modules\Agri\Models\AnimalEvent;
use App\Modules\Agri\Models\AnimalHealthRecord;
use App\Modules\Agri\Models\AnimalMovement;
use App\Modules\Agri\Models\FeedRation;
use App\Modules\Agri\Models\Feeding;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\LivestockProduction;
use App\Modules\Agri\Models\VaccinationProgramme;
use App\Modules\Agri\Services\LivestockIntelligence;
use App\Modules\Agri\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Phase 2 §4: livestock and animal production, with the section's acceptance tests — duplicate
 * identifiers refused, movement history kept, feed consumption worked out correctly, and
 * prohibited dispatches blocked by withdrawal and health rules.
 */
class LivestockIntelligenceTest extends AgriTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogue();
    }

    private function group($farm, string $code, string $species = 'CATTLE', array $o = []): LivestockGroup
    {
        $this->actingAs($this->manager)->post(route('agri.livestock.groups.store'), $o + ['farm_id' => $farm->uuid, 'species_id' => $this->speciesId($species),
            'code' => $code, 'name' => "Group {$code}", 'purpose' => 'breeding'])->assertSessionHasNoErrors();

        return LivestockGroup::withoutGlobalScopes()->where('code', $code)->firstOrFail();
    }

    private function animal($farm, string $tag, array $o = []): Animal
    {
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.store'), $o + ['farm_id' => $farm->uuid, 'species_id' => $this->speciesId('CATTLE'),
            'tag_number' => $tag, 'sex' => 'female', 'acquisition' => 'born', 'birth_date' => today()->subYears(3)->toDateString()])->assertSessionHasNoErrors();

        return Animal::withoutGlobalScopes()->where('tag_number', $tag)->firstOrFail();
    }

    public function test_acceptance_duplicate_identifiers_are_refused_and_movement_history_is_kept(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $field = $this->field($farm, 'K1');
        $field2 = $this->field($farm, 'K2');
        $cow = $this->animal($farm, 'ZA-1', ['ownership' => 'agisted', 'owner_name' => 'Neighbour Trust', 'birth_date_estimated' => true]);
        $this->assertSame('agisted', $cow->ownership);
        $this->assertTrue((bool) $cow->birth_date_estimated);

        $this->actingAs($this->manager)->post(route('agri.livestock.animals.store'), ['farm_id' => $farm->uuid, 'species_id' => $this->speciesId('CATTLE'),
            'tag_number' => 'za-1', 'sex' => 'male', 'acquisition' => 'purchased'])->assertSessionHasErrors('tag_number');

        foreach ([$field, $field2] as $f) {
            $this->actingAs($this->manager)->post(route('agri.livestock.animals.move', $cow->uuid), ['field_id' => $f->uuid, 'moved_on' => today()->toDateString()])->assertSessionHasNoErrors();
        }
        $moves = AnimalMovement::withoutGlobalScopes()->where('animal_id', $cow->id)->orderBy('id')->get();
        $this->assertCount(2, $moves);
        $this->assertSame([$field->id, $field2->id], $moves->pluck('to_field_id')->map(fn ($v) => (int) $v)->all());
        // History is append-only: nothing in the application edits or deletes a movement.
        $verbs = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'movements'))->flatMap->methods();
        $this->assertEmpty($verbs->intersect(['PUT', 'PATCH', 'DELETE']));
    }

    public function test_acceptance_feed_is_issued_by_ration_share_once_and_feed_conversion_is_worked_out(): void
    {
        $this->openBooks();
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $maize = $this->stockItem('MAIZE-MEAL', 'kg', 4);
        $conc = $this->stockItem('CONC-40', 'kg', 10);
        $this->stockOnFarm($farm, $maize, 5000, 4);
        $this->stockOnFarm($farm, $conc, 2000, 10);
        $group = $this->group($farm, 'FEEDLOT-1', 'CATTLE', ['purpose' => 'feedlot', 'started_on' => today()->subDays(30)->toDateString()]);
        foreach (['F1', 'F2'] as $tag) {
            $a = $this->animal($farm, $tag, ['group_id' => $group->uuid, 'sex' => 'castrate']);
            $this->asCompany($this->company, function () use ($farm, $a) {
                app(\App\Modules\Agri\Services\Livestock::class)->weigh($farm, $a, null, ['weighed_on' => today()->subDays(20)->toDateString(), 'weight_kg' => 300], $this->manager);
                app(\App\Modules\Agri\Services\Livestock::class)->weigh($farm, $a, null, ['weighed_on' => today()->toDateString(), 'weight_kg' => 340], $this->manager);
            });
        }

        // A ration that does not add up to 100% is refused.
        $this->actingAs($this->manager)->post(route('agri.livestock.rations.store'), ['name' => 'Bad', 'items' => [['stock_item_id' => $maize->id, 'share_pct' => 60]]])
            ->assertStatus(422);
        $this->actingAs($this->manager)->post(route('agri.livestock.rations.store'), ['name' => 'Finisher', 'items' => [
            ['stock_item_id' => $maize->id, 'share_pct' => 60], ['stock_item_id' => $conc->id, 'share_pct' => 40]]])->assertSessionHasNoErrors();
        $ration = FeedRation::withoutGlobalScopes()->sole();

        $client = (string) Str::uuid();
        $feed = fn () => $this->actingAs($this->manager)->post(route('agri.livestock.feedings.store'), ['farm_id' => $farm->uuid, 'group_id' => $group->uuid,
            'ration_id' => $ration->uuid, 'fed_on' => today()->subDays(5)->toDateString(), 'quantity_kg' => 500, 'head_count' => 2, 'client_id' => $client]);
        $feed()->assertSessionHasNoErrors();
        $feed()->assertSessionHasNoErrors();   // sent twice by a phone: recorded once

        $f = Feeding::withoutGlobalScopes()->sole();
        $this->assertEquals([300.0, 200.0], array_column($f->issues, 'kg'));
        $this->assertEqualsWithDelta(3200.0, $f->cost, 0.01);      // 300 × R4 + 200 × R10
        $this->assertEqualsWithDelta(4700.0, (float) $maize->fresh()->qty_on_hand, 0.001);
        $this->assertEqualsWithDelta(1800.0, (float) $conc->fresh()->qty_on_hand, 0.001);

        $m = $this->asCompany($this->company, fn () => app(LivestockIntelligence::class)->feedMetrics($group, today()->subDays(30)->toDateString(), today()->toDateString()));
        $this->assertEquals(500, $m['feed_kg']);
        $this->assertEquals(80, $m['gain_kg']);         // 2 × 40 kg
        $this->assertEquals(6.25, $m['fcr']);           // 500 kg feed / 80 kg gain
        $this->assertEquals(250, $m['kg_per_head_day']);

        $cost = $this->asCompany($this->company, fn () => app(LivestockIntelligence::class)->costing($group->fresh()));
        $this->assertEqualsWithDelta(3200.0, $cost['costs']['feed'], 0.01);
        $this->assertEqualsWithDelta(1600.0, $cost['cost_per_head'], 0.01);
    }

    public function test_acceptance_prohibited_dispatches_are_blocked_by_withdrawal_quarantine_and_unverified_release(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->supervisor, $farm, 'supervisor');
        $cow = $this->animal($farm, 'ZA-9');
        $treat = fn (array $o) => $this->actingAs($this->supervisor)->post(route('agri.livestock.health.store'), $o + ['farm_id' => $farm->uuid,
            'animal_id' => $cow->uuid, 'event_type' => 'treatment']);

        // Treated 10 days ago, 14-day withdrawal, a residue test required before sale.
        $treat(['treated_on' => today()->subDays(10)->toDateString(), 'product_name' => 'Oxytet LA', 'withdrawal_days' => 14, 'requires_clearance' => true])->assertSessionHasNoErrors();
        $sell = fn () => $this->actingAs($this->manager)->post(route('agri.livestock.animals.exit', $cow->uuid), ['status' => 'sold', 'exited_on' => today()->toDateString(), 'value' => 15000]);
        $sell()->assertSessionHasErrors('status');

        // Milk from her is recorded but withheld, and cannot be sold.
        $this->actingAs($this->manager)->post(route('agri.livestock.production.store'), ['farm_id' => $farm->uuid, 'animal_id' => $cow->uuid,
            'product' => 'milk', 'recorded_on' => today()->toDateString(), 'quantity' => 18, 'unit' => 'l']);
        $lot = LivestockProduction::withoutGlobalScopes()->sole();
        $this->assertTrue($lot->withheld);
        $this->actingAs($this->manager)->post(route('agri.livestock.production.update', $lot->uuid), ['action' => 'sell', 'value' => 120])->assertSessionHasErrors('lot');
        $this->actingAs($this->manager)->post(route('agri.livestock.production.update', $lot->uuid), ['action' => 'discard', 'reason' => 'Withdrawal milk'])->assertSessionHasNoErrors();

        // After the withdrawal ends, the release must still be verified — by somebody else.
        $record = AnimalHealthRecord::withoutGlobalScopes()->sole();
        $this->asCompany($this->company, fn () => $record->update(['treated_on' => today()->subDays(20), 'withdrawal_until' => today()->subDays(6)]));
        $sell()->assertSessionHasErrors('status');
        $this->actingAs($this->supervisor)->post(route('agri.livestock.health.clear', $record->uuid), ['note' => 'Test negative'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.livestock.health.clear', $record->uuid), ['note' => 'Residue test negative, lab ref 4411'])->assertSessionHasNoErrors();

        // A notifiable disease quarantines her: no sale, no transfer off the farm.
        $treat(['event_type' => 'diagnosis', 'treated_on' => today()->toDateString(), 'condition' => 'Suspected FMD', 'notifiable' => true, 'quarantine_days' => 21])
            ->assertSessionHasNoErrors();
        $this->assertSame(today()->addDays(21)->toDateString(), $cow->fresh()->quarantine_until->toDateString());
        $sell()->assertSessionHasErrors('status');
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.exit', $cow->uuid), ['status' => 'transferred', 'exited_on' => today()->toDateString()])
            ->assertSessionHasErrors('status');
        $this->assertSame('active', $cow->fresh()->status);
    }

    public function test_breeding_gives_an_expected_birth_and_the_birth_registers_the_calf_to_its_dam_and_sire(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $cow = $this->animal($farm, 'COW-1');
        $this->animal($farm, 'BULL-1', ['sex' => 'male']);
        $mated = today()->subDays(100);
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.breeding', $cow->uuid), ['type' => 'mating', 'occurred_on' => $mated->toDateString(),
            'method' => 'natural', 'sire_tag' => 'BULL-1'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('agri.livestock.animals.breeding', $cow->uuid), ['type' => 'pregnancy_check', 'occurred_on' => today()->subDays(40)->toDateString(),
            'outcome' => 'pregnant'])->assertSessionHasNoErrors();
        $check = AnimalEvent::withoutGlobalScopes()->where('event_type', 'pregnancy_check')->sole();
        $this->assertSame($mated->copy()->addDays(283)->toDateString(), $check->expected_on->toDateString());   // cattle gestation

        $this->actingAs($this->manager)->post(route('agri.livestock.animals.birth', $cow->uuid), ['born_on' => today()->toDateString(), 'tag_number' => 'CALF-1', 'sex' => 'male'])
            ->assertSessionHasNoErrors();
        $calf = Animal::withoutGlobalScopes()->where('tag_number', 'CALF-1')->sole();
        $this->assertSame($cow->id, (int) $calf->dam_id);
        $this->assertSame(Animal::withoutGlobalScopes()->where('tag_number', 'BULL-1')->value('id'), (int) $calf->sire_id);
        $this->assertTrue(AnimalEvent::withoutGlobalScopes()->where('animal_id', $cow->id)->where('event_type', 'calving')->exists());
    }

    public function test_vaccinations_due_herd_composition_and_restricted_treatment_records(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->member($this->outsider, $farm, 'viewer');
        $this->asCompany($this->company, fn () => \App\Modules\Agri\Models\LivestockSpecies::withoutGlobalScopes()->whereKey($this->speciesId('CATTLE'))
            ->update(['breeding_age_months' => 15, 'productive_years' => 8]));
        $this->group($farm, 'HERD', 'CATTLE', ['target_head' => 10]);
        $this->animal($farm, 'OLD', ['birth_date' => today()->subYears(11)->toDateString()]);
        $this->animal($farm, 'COW', ['birth_date' => today()->subYears(4)->toDateString()]);
        $this->animal($farm, 'HEIFER', ['birth_date' => today()->subMonths(10)->toDateString()]);
        $this->asCompany($this->company, fn () => VaccinationProgramme::withoutGlobalScopes()->create(['company_id' => $this->company->id,
            'species_id' => $this->speciesId('CATTLE'), 'name' => 'Lumpy skin', 'product' => 'LSD vaccine', 'applies_to' => 'all', 'first_at_age_days' => 180, 'repeat_every_days' => 365]));

        $intel = app(LivestockIntelligence::class);
        $due = $this->asCompany($this->company, fn () => $intel->vaccinationsDue($farm, 30));
        $this->assertCount(3, $due, 'Never vaccinated, all old enough.');
        $this->assertTrue(collect($due)->every(fn ($d) => $d['overdue']));
        $c = $this->asCompany($this->company, fn () => $intel->composition($farm))[0];
        $this->assertSame(2, $c['breeding_females']);
        $this->assertSame(1, $c['due_to_cull']);
        $this->assertSame(1, $c['replacements_coming']);
        $this->assertSame(8, $c['replacement_gap']);    // target 10 − (2 breeding − 1 cull + 1 heifer)

        // Treatment records: a viewer without the health permission sees none.
        $this->actingAs($this->manager)->post(route('agri.livestock.health.store'), ['farm_id' => $farm->uuid, 'animal_id' => Animal::withoutGlobalScopes()->where('tag_number', 'COW')->value('uuid'),
            'event_type' => 'treatment', 'treated_on' => today()->toDateString(), 'condition' => 'Mastitis'])->assertSessionHasNoErrors();
        $viewer = \App\Modules\Agri\Support\RoleCatalog::suggestedRoles()['Farm Viewer'];
        $this->assertNotContains(RoleCatalog::VIEW_HEALTH, $viewer);
        $role = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'Farm Viewer', 'guard_name' => 'web']);
        $role->syncPermissions($viewer);
        $this->outsider->syncRoles([$role]);
        $this->actingAs($this->outsider->fresh())->get(route('agri.livestock.index', ['farm' => $farm->uuid]))->assertOk()
            ->assertInertia(fn ($p) => $p->where('health', []));
        $this->actingAs($this->manager)->get(route('agri.livestock.index', ['farm' => $farm->uuid]))->assertOk()
            ->assertInertia(fn ($p) => $p->has('health', 1));
    }
}
