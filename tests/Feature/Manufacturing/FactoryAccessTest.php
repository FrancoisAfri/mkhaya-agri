<?php

namespace Tests\Feature\Manufacturing;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Department;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\FactoryAssignment;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use App\Modules\Operations\Manufacturing\Services\FactoryAccess;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;

/**
 * MFG-001 acceptance:
 *
 *   "A user assigned to Factory A must not access Factory B data unless explicitly authorised.
 *    Company-level isolation must apply regardless of the factory selected."
 *
 * Company isolation is row-level security and is tested elsewhere. This is the layer inside it:
 * one company, two plants, and a person who works at only one of them.
 */
class FactoryAccessTest extends ManufacturingTestCase
{
    private Factory $alpha;

    private Factory $beta;

    private User $alphaOperator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->factory('ALPHA');
        $this->beta = $this->factory('BETA');

        foreach ([$this->alpha, $this->beta] as $factory) {
            $this->asCompany($this->company, function () use ($factory) {
                WorkCentre::create([
                    'company_id' => $this->company->id, 'factory_id' => $factory->id,
                    'code' => 'WC-'.$factory->code, 'name' => 'Press '.$factory->code, 'is_active' => true,
                ]);
                Shift::create([
                    'company_id' => $this->company->id, 'factory_id' => $factory->id, 'code' => 'D-'.$factory->code,
                    'name' => 'Day '.$factory->code, 'starts_at' => '06:00', 'ends_at' => '14:00',
                    'days_of_week' => [1, 2, 3, 4, 5], 'overtime_multiplier' => 1.5, 'is_active' => true,
                ]);
            });
        }

        // A configurator who is placed at ALPHA only.
        $this->alphaOperator = $this->makeUser('alpha-op@example.test',
            \App\Core\Role\Models\Role::where('name', 'mfg test configurator')->first());
        $this->grantModuleTier($this->alphaOperator);

        $this->assign($this->alphaOperator, $this->alpha);
    }

    private function assign(User $user, Factory $factory, ?Department $department = null): void
    {
        FactoryAssignment::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'factory_id' => $factory->id,
            'user_id' => $user->id, 'department_id' => $department?->id,
        ]);
        app(FactoryAccess::class)->flush();
    }

    private function factoriesSeenBy(User $user): array
    {
        app(FactoryAccess::class)->flush();

        return array_column(
            $this->actingAs($user)->get(route('manufacturing.setup.factories'))->assertOk()
                ->viewData('page')['props']['factories'],
            'code'
        );
    }

    public function test_a_person_assigned_to_one_factory_cannot_see_another(): void
    {
        $this->assertSame(['ALPHA'], $this->factoriesSeenBy($this->alphaOperator));
    }

    public function test_the_other_factorys_work_centres_and_shifts_are_hidden_too(): void
    {
        app(FactoryAccess::class)->flush();

        $props = $this->actingAs($this->alphaOperator)
            ->get(route('manufacturing.setup.work-centres'))->assertOk()->viewData('page')['props'];
        $this->assertSame(['WC-ALPHA'], array_column($props['work_centres'], 'code'));

        $shifts = $this->actingAs($this->alphaOperator)
            ->get(route('manufacturing.setup.shifts'))->assertOk()->viewData('page')['props']['shifts'];
        $this->assertSame(['D-ALPHA'], array_column($shifts, 'code'));
    }

    public function test_the_other_factory_cannot_be_reached_by_guessing_its_uuid(): void
    {
        app(FactoryAccess::class)->flush();

        $this->actingAs($this->alphaOperator)
            ->patch(route('manufacturing.setup.factories.update', $this->beta->uuid), [
                'code' => 'BETA', 'name' => 'Taken', 'timezone' => 'UTC', 'currency' => 'ZAR',
                'measurement_system' => 'METRIC',
            ])->assertNotFound();

        $this->actingAs($this->alphaOperator)
            ->delete(route('manufacturing.setup.factories.destroy', $this->beta->uuid))->assertNotFound();

        // Nor into it: the factory cannot be found, so nothing can be created inside it. A 404
        // rather than a validation message, which would confirm that the factory exists.
        $this->actingAs($this->alphaOperator)->post(route('manufacturing.setup.work-centres.store'), [
            'factory_uuid' => $this->beta->uuid, 'code' => 'SNEAK', 'name' => 'Sneak',
        ])->assertNotFound();

        $this->assertSame('Plant BETA', DB::table('mfg_factories')->where('id', $this->beta->id)->value('name'));
        $this->assertSame(0, DB::table('mfg_work_centres')->where('code', 'SNEAK')->count());
    }

    public function test_somebody_with_no_assignment_is_not_locked_out(): void
    {
        // Every existing tenant is in this state, and must not lose its own plant.
        $this->assertEqualsCanonicalizing(['ALPHA', 'BETA'], $this->factoriesSeenBy($this->configurator));
    }

    public function test_the_all_factories_permission_lifts_the_confinement(): void
    {
        $this->alphaOperator->givePermissionTo(RoleCatalog::ALL_FACTORIES);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Still assigned to ALPHA, but explicitly authorised to see everything.
        $this->assertEqualsCanonicalizing(['ALPHA', 'BETA'], $this->factoriesSeenBy($this->alphaOperator->fresh()));
    }

    public function test_being_assigned_to_two_factories_shows_exactly_those_two(): void
    {
        $gamma = $this->factory('GAMMA');
        $this->assign($this->alphaOperator, $gamma);

        $this->assertEqualsCanonicalizing(['ALPHA', 'GAMMA'], $this->factoriesSeenBy($this->alphaOperator));
    }

    public function test_a_department_assignment_confines_a_person_to_its_work_centres(): void
    {
        $extrusion = $this->asCompany($this->company, fn () => $this->alpha->departments()->create([
            'company_id' => $this->company->id, 'code' => 'EXT', 'name' => 'Extrusion', 'is_active' => true,
        ]));
        $moulding = $this->asCompany($this->company, fn () => $this->alpha->departments()->create([
            'company_id' => $this->company->id, 'code' => 'MLD', 'name' => 'Moulding', 'is_active' => true,
        ]));

        $this->asCompany($this->company, function () use ($extrusion, $moulding) {
            foreach ([['EXTRUDER', $extrusion], ['MOULD-1', $moulding]] as [$code, $dept]) {
                WorkCentre::create([
                    'company_id' => $this->company->id, 'factory_id' => $this->alpha->id,
                    'department_id' => $dept->id, 'code' => $code, 'name' => $code, 'is_active' => true,
                ]);
            }
        });

        // Replace the whole-factory assignment with an Extrusion-only one.
        FactoryAssignment::withoutGlobalScopes()->where('user_id', $this->alphaOperator->id)->delete();
        $this->assign($this->alphaOperator, $this->alpha, $extrusion);

        $seen = array_column($this->actingAs($this->alphaOperator)
            ->get(route('manufacturing.setup.work-centres'))->assertOk()
            ->viewData('page')['props']['work_centres'], 'code');

        $this->assertContains('EXTRUDER', $seen);
        $this->assertNotContains('MOULD-1', $seen, 'A department-level assignment still showed another department.');
        // A work centre with no department is a factory-level record and is not hidden by it.
        $this->assertContains('WC-ALPHA', $seen);
    }

    public function test_a_whole_factory_assignment_beats_a_department_one_at_the_same_plant(): void
    {
        $dept = $this->asCompany($this->company, fn () => $this->alpha->departments()->create([
            'company_id' => $this->company->id, 'code' => 'ONLY', 'name' => 'Only', 'is_active' => true,
        ]));

        // Already whole-factory from setUp; adding a department too must not narrow it.
        $this->assign($this->alphaOperator, $this->alpha, $dept);

        $restriction = app(FactoryAccess::class)->restrictionFor($this->alphaOperator);
        $this->assertNull($restriction['departments'][$this->alpha->id]);
    }

    public function test_assignments_are_managed_from_the_screen_and_are_confined_to_the_tenant(): void
    {
        // A second plant manager: holds the configure permission, so there is a screen to look at.
        $person = $this->makeUser('newhire@example.test',
            \App\Core\Role\Models\Role::where('name', 'mfg test configurator')->first());
        $this->grantModuleTier($person);

        $this->actingAs($this->configurator)
            ->post(route('manufacturing.setup.factories.access.store', $this->beta->uuid), ['user_id' => $person->id])
            ->assertSessionHas('success');

        $this->assertSame(1, FactoryAssignment::withoutGlobalScopes()
            ->where('factory_id', $this->beta->id)->where('user_id', $person->id)->count());

        // The same grant twice is a friendly refusal, not a unique-index exception.
        $this->actingAs($this->configurator)
            ->post(route('manufacturing.setup.factories.access.store', $this->beta->uuid), ['user_id' => $person->id])
            ->assertSessionHas('error');

        // And it takes effect for that person immediately.
        $this->assertSame(['BETA'], $this->factoriesSeenBy($person->fresh()));

        // Revoking it hands them back to "unrestricted" (nobody assigned), as designed.
        $uuid = FactoryAssignment::withoutGlobalScopes()->where('user_id', $person->id)->value('uuid');
        $this->actingAs($this->configurator)
            ->delete(route('manufacturing.setup.factories.access.destroy', [$this->beta->uuid, $uuid]))
            ->assertSessionHas('success');
        $this->assertEqualsCanonicalizing(['ALPHA', 'BETA'], $this->factoriesSeenBy($person->fresh()));
    }

    public function test_a_department_of_another_factory_cannot_be_used_to_assign(): void
    {
        $betaDept = $this->asCompany($this->company, fn () => $this->beta->departments()->create([
            'company_id' => $this->company->id, 'code' => 'BD', 'name' => 'Beta dept', 'is_active' => true,
        ]));
        $person = $this->makeUser('stray@example.test', null);

        $this->actingAs($this->configurator)
            ->post(route('manufacturing.setup.factories.access.store', $this->alpha->uuid), [
                'user_id' => $person->id, 'department_uuid' => $betaDept->uuid,
            ])->assertSessionHas('error');

        $this->assertSame(0, FactoryAssignment::withoutGlobalScopes()->where('user_id', $person->id)->count());
    }

    public function test_only_a_configurator_may_assign_people(): void
    {
        $person = $this->makeUser('target@example.test', null);

        foreach ([$this->planner, $this->engineer, $this->approver, $this->outsider] as $user) {
            $this->actingAs($user)
                ->post(route('manufacturing.setup.factories.access.store', $this->alpha->uuid), ['user_id' => $person->id])
                ->assertForbidden();
        }
    }

    public function test_a_person_from_another_tenant_cannot_be_assigned(): void
    {
        $rival = \App\Modules\Tenancy\Models\Company::create(['name' => 'Other Foundry']);
        $stranger = User::factory()->create(['email' => 'stranger@example.test', 'status' => 1, 'company_id' => $rival->id]);

        $this->actingAs($this->configurator)
            ->post(route('manufacturing.setup.factories.access.store', $this->alpha->uuid), ['user_id' => $stranger->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_the_dashboard_counts_only_what_the_person_may_see(): void
    {
        app(FactoryAccess::class)->flush();

        $r = $this->actingAs($this->alphaOperator)->get(route('manufacturing.dashboard'))
            ->assertOk()->viewData('page')['props']['readiness'];

        $this->assertSame(1, $r['factories']);
        $this->assertSame(1, $r['work_centres']);
    }

    public function test_an_assignment_is_on_the_audit_trail(): void
    {
        $person = $this->makeUser('audited@example.test', null);

        $this->actingAs($this->configurator)
            ->post(route('manufacturing.setup.factories.access.store', $this->alpha->uuid), ['user_id' => $person->id])
            ->assertSessionHas('success');

        $this->assertTrue(
            DB::table('activity_log')->where('log_name', 'manufacturing')
                ->where('subject_type', FactoryAssignment::class)->exists(),
            'Who was given access to which plant left no record.'
        );
    }
}
