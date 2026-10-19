<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Spec §3.4 (tenancy and farm hierarchy) and §4.4 (farm registration) acceptance criteria.
 */
class FarmHierarchyTest extends AgriTestCase
{
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'code' => 'NORTH', 'name' => 'Noordplaas', 'farm_type' => 'mixed', 'location' => 'Bethlehem',
            'area' => 450.5, 'area_unit' => 'ha', 'timezone' => 'Africa/Johannesburg', 'currency' => 'ZAR',
        ];
    }

    public function test_an_organisation_administrator_registers_several_farms_and_sees_them_all(): void
    {
        $this->actingAs($this->admin)->post(route('agri.farms.store'), $this->payload())->assertRedirect();
        $this->actingAs($this->admin)->post(route('agri.farms.store'), $this->payload(['code' => 'SOUTH', 'name' => 'Suidplaas']))->assertRedirect();

        $this->assertEqualsCanonicalizing(['NORTH', 'SOUTH'], $this->farmsSeenBy($this->admin));
        $this->assertSame(450.5, (float) Farm::withoutGlobalScopes()->where('code', 'NORTH')->value('area'));
    }

    public function test_a_farm_manager_reaches_assigned_farms_only_and_another_farm_is_a_404(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $this->member($this->manager, $north);

        $this->assertSame(['NORTH'], $this->farmsSeenBy($this->manager));
        $this->actingAs($this->manager)->get(route('agri.farms.show', $north->uuid))->assertOk();
        // Refused without confirming the farm exists.
        $this->actingAs($this->manager)->get(route('agri.farms.show', $south->uuid))->assertNotFound();
        $this->actingAs($this->manager)->patch(route('agri.farms.update', $south->uuid), $this->payload(['code' => 'SOUTH']))->assertNotFound();
    }

    public function test_access_is_never_assumed_a_person_with_no_membership_sees_no_farm(): void
    {
        $this->farm('NORTH');

        $this->assertSame([], $this->farmsSeenBy($this->worker));
        $this->assertSame([], app(FarmAccess::class)->farmIdsFor($this->worker));
    }

    public function test_another_organisations_farm_is_invisible_even_by_its_uuid(): void
    {
        $other = Company::create(['name' => 'Ander Boerdery']);
        $theirs = $this->farm('NORTH', $other);

        $this->assertSame([], $this->farmsSeenBy($this->admin));
        $this->actingAs($this->admin)->get(route('agri.farms.show', $theirs->uuid))->assertNotFound();
    }

    public function test_suspending_a_membership_stops_the_very_next_request(): void
    {
        $north = $this->farm('NORTH');
        $membership = $this->member($this->manager, $north);
        $this->actingAs($this->manager)->get(route('agri.farms.show', $north->uuid))->assertOk();

        $this->actingAs($this->admin)
            ->patch(route('agri.farms.access.update', [$north->uuid, $membership->uuid]), ['status' => 'suspended'])
            ->assertRedirect();

        app(FarmAccess::class)->flush();
        $this->actingAs($this->manager)->get(route('agri.farms.show', $north->uuid))->assertNotFound();
        $this->assertSame('suspended', FarmMembership::withoutGlobalScopes()->find($membership->id)->status);
    }

    public function test_farm_codes_are_unique_per_organisation_ignoring_case_but_free_across_organisations(): void
    {
        $this->farm('NORTH');

        $this->actingAs($this->admin)->post(route('agri.farms.store'), $this->payload(['code' => 'north']))
            ->assertSessionHasErrors('code');

        // Another organisation may use the same code.
        $other = Company::create(['name' => 'Ander Boerdery']);
        $this->farm('NORTH', $other);
        $this->assertSame(2, Farm::withoutGlobalScopes()->whereRaw('lower(code) = ?', ['north'])->count());
    }

    public function test_a_restricted_manager_cannot_reuse_a_code_held_by_a_farm_they_cannot_see(): void
    {
        $this->farm('HIDDEN');

        $this->actingAs($this->manager)->post(route('agri.farms.store'), $this->payload(['code' => 'hidden']))
            ->assertSessionHasErrors('code');
    }

    public function test_a_restricted_manager_who_registers_a_farm_can_open_it(): void
    {
        $this->actingAs($this->manager)->post(route('agri.farms.store'), $this->payload(['code' => 'NEW']))->assertRedirect();

        $farm = Farm::withoutGlobalScopes()->where('code', 'NEW')->firstOrFail();
        $this->assertSame('manager', FarmMembership::withoutGlobalScopes()->where('farm_id', $farm->id)->where('user_id', $this->manager->id)->value('role'));
        $this->assertSame(['NEW'], $this->farmsSeenBy($this->manager));
    }

    public function test_without_farm_administration_permission_farms_cannot_be_created_or_edited(): void
    {
        $north = $this->farm('NORTH');
        $this->member($this->worker, $north, 'worker');

        $this->actingAs($this->worker)->post(route('agri.farms.store'), $this->payload(['code' => 'X']))->assertForbidden();
        $this->actingAs($this->worker)->patch(route('agri.farms.update', $north->uuid), $this->payload())->assertForbidden();
        $this->actingAs($this->outsider)->get(route('agri.farms.index'))->assertForbidden();
    }

    public function test_invalid_area_units_and_coordinates_are_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('agri.farms.store'), $this->payload([
            'area' => -5, 'area_unit' => 'morgen', 'latitude' => 120, 'longitude' => 28.1,
        ]))->assertSessionHasErrors(['area', 'area_unit', 'latitude']);

        $this->actingAs($this->admin)->post(route('agri.farms.store'), $this->payload(['latitude' => -28.2]))
            ->assertSessionHasErrors('longitude');
    }

    public function test_archiving_keeps_history_but_blocks_new_operational_records(): void
    {
        $north = $this->farm('NORTH');
        $this->actingAs($this->admin)->post(route('agri.farms.fields.store', $north->uuid), [
            'code' => 'F1', 'name' => 'Land 1', 'land_use_type' => 'field', 'area' => 40, 'area_unit' => 'ha',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post(route('agri.farms.status', $north->uuid), ['status' => 'archived'])->assertRedirect();

        $farm = Farm::withoutGlobalScopes()->find($north->id);
        $this->assertSame('archived', $farm->status);
        $this->assertNotNull($farm->archived_at);
        $this->assertSame(1, FarmField::withoutGlobalScopes()->where('farm_id', $north->id)->count(), 'Archiving must not delete history.');

        // Still readable…
        $this->actingAs($this->admin)->get(route('agri.farms.show', $north->uuid))->assertOk();
        // …but no new work.
        $this->actingAs($this->admin)->post(route('agri.farms.fields.store', $north->uuid), [
            'code' => 'F2', 'name' => 'Land 2', 'land_use_type' => 'field', 'area_unit' => 'ha',
        ])->assertSessionHasErrors('farm');
        $this->assertSame(0, Farm::query()->operational()->count());
    }

    public function test_a_farm_with_history_cannot_be_deleted_only_archived(): void
    {
        $north = $this->farm('NORTH');
        $empty = $this->farm('EMPTY');
        $this->asCompany($this->company, fn () => FarmField::create([
            'company_id' => $this->company->id, 'farm_id' => $north->id, 'code' => 'F1', 'name' => 'Land 1', 'area_unit' => 'ha',
        ]));

        $this->actingAs($this->admin)->delete(route('agri.farms.destroy', $north->uuid))->assertSessionHasErrors('farm');
        $this->assertNotNull(Farm::withoutGlobalScopes()->find($north->id));

        $this->actingAs($this->admin)->delete(route('agri.farms.destroy', $empty->uuid))->assertRedirect(route('agri.farms.index'));
        $this->assertNull(Farm::withoutGlobalScopes()->find($empty->id));
    }

    public function test_field_codes_are_unique_within_a_farm_but_reusable_on_another(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $field = ['code' => 'F1', 'name' => 'Land 1', 'land_use_type' => 'orchard', 'area' => 12, 'area_unit' => 'ha'];

        $this->actingAs($this->admin)->post(route('agri.farms.fields.store', $north->uuid), $field)->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('agri.farms.fields.store', $north->uuid), ['code' => 'f1'] + $field)->assertSessionHasErrors('code');
        $this->actingAs($this->admin)->post(route('agri.farms.fields.store', $south->uuid), $field)->assertSessionHasNoErrors();
    }

    public function test_access_can_only_be_granted_within_the_granters_authority_and_organisation(): void
    {
        $north = $this->farm('NORTH');
        $south = $this->farm('SOUTH');
        $this->member($this->manager, $north);

        // The manager may give access to their own farm…
        $this->actingAs($this->manager)->post(route('agri.farms.access.store', $north->uuid), ['user_id' => $this->worker->id, 'role' => 'worker'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['NORTH'], $this->farmsSeenBy($this->worker));

        // …but not to a farm they cannot reach (which, for them, does not exist).
        $this->actingAs($this->manager)->post(route('agri.farms.access.store', $south->uuid), ['user_id' => $this->worker->id, 'role' => 'worker'])
            ->assertNotFound();

        // Nor to somebody in another organisation.
        $stranger = $this->makeUser('stranger@other.test', null, Company::create(['name' => 'Ander Boerdery']));
        $this->actingAs($this->manager)->post(route('agri.farms.access.store', $north->uuid), ['user_id' => $stranger->id, 'role' => 'worker'])
            ->assertStatus(422);
    }

    public function test_farm_and_field_changes_are_audited_against_the_organisation(): void
    {
        $this->actingAs($this->admin)->post(route('agri.farms.store'), $this->payload())->assertRedirect();
        $farm = Farm::withoutGlobalScopes()->where('code', 'NORTH')->firstOrFail();
        $this->actingAs($this->admin)->patch(route('agri.farms.update', $farm->uuid), $this->payload(['name' => 'Noordplaas Oos']))->assertRedirect();

        $entries = DB::table('activity_log')->where('log_name', 'agri')->where('subject_id', $farm->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $entries->pluck('event')->all());
        $this->assertTrue($entries->every(fn ($e) => (int) $e->company_id === $this->company->id));
        $this->assertSame($this->admin->id, (int) $entries->last()->causer_id);
    }
}
