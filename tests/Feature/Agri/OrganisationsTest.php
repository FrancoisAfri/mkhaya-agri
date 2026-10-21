<?php

namespace Tests\Feature\Agri;

use App\Core\Role\Models\Role;
use App\Core\User\Models\User;
use App\Modules\Agri\Models\FarmMembership;
use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyMembership;
use App\Modules\Tenancy\Notifications\AddedToOrganisation;
use Illuminate\Support\Facades\Notification;

/**
 * One person, several organisations: an agronomist from another business brought into this one,
 * switching between them with the right roles in each, and the farm screens accepting them.
 */
class OrganisationsTest extends AgriTestCase
{
    private Company $other;

    private User $agronomist;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->other = Company::create(['name' => 'Agri Advisory (Pty) Ltd']);
        $this->entitle($this->other);
        $ownerRole = Role::firstOrCreate(['name' => 'Farm Owner', 'guard_name' => 'web']);
        $ownerRole->syncPermissions(RoleCatalog::suggestedRoles()['Farm Owner']);
        $viewer = Role::firstOrCreate(['name' => 'Farm Viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions(RoleCatalog::suggestedRoles()['Farm Viewer']);
        $supervisor = Role::firstOrCreate(['name' => 'Farm Supervisor', 'guard_name' => 'web']);
        $supervisor->syncPermissions(RoleCatalog::suggestedRoles()['Farm Supervisor']);
        // At home the agronomist owns their own consultancy.
        $this->agronomist = $this->makeUser('agronomist@advisory.test', $ownerRole, $this->other);
        $this->admin->assignRole(Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']));
    }

    private function invite(User $actor, array $roles = ['Farm Supervisor']): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($actor)->post(route('organisations.members.store'), ['email' => 'Agronomist@Advisory.test', 'roles' => $roles]);
    }

    private function membership(User $u, Company $c): ?CompanyMembership
    {
        return CompanyMembership::query()->where('user_id', $u->id)->where('company_id', $c->id)->first();
    }

    public function test_everybody_belongs_to_their_own_organisation(): void
    {
        $this->assertNotNull($this->membership($this->worker, $this->company));
        $this->assertNotNull($this->membership($this->agronomist, $this->other));
    }

    public function test_an_administrator_adds_somebody_from_another_organisation_and_they_are_told(): void
    {
        $this->invite($this->admin)->assertSessionHasNoErrors();

        $m = $this->membership($this->agronomist, $this->company);
        $this->assertSame(['Farm Supervisor'], $m->roles);
        $this->assertSame((int) $this->other->id, (int) $this->agronomist->fresh()->company_id, 'They keep working where they were.');
        Notification::assertSentTo($this->agronomist, AddedToOrganisation::class);
    }

    public function test_switching_takes_the_roles_held_there_and_brings_the_home_roles_back(): void
    {
        $this->invite($this->admin)->assertSessionHasNoErrors();

        // They land where their roles here let them in: the farm dashboard, not a refused /dashboard.
        $this->actingAs($this->agronomist)->post(route('organisations.switch', $this->company->id))->assertRedirect(route('agri.dashboard'));
        $a = $this->agronomist->fresh();
        $this->assertSame((int) $this->company->id, (int) $a->company_id);
        $this->assertSame(['Farm Supervisor'], $a->getRoleNames()->all());
        $this->assertFalse($a->can(RoleCatalog::ALL_FARMS), 'Owner at home is not owner here.');

        $this->actingAs($a)->post(route('organisations.switch', $this->other->id));
        $a = $this->agronomist->fresh();
        $this->assertSame((int) $this->other->id, (int) $a->company_id);
        $this->assertSame(['Farm Owner'], $a->getRoleNames()->all());
    }

    public function test_nobody_switches_into_an_organisation_they_do_not_belong_to(): void
    {
        $this->actingAs($this->agronomist)->post(route('organisations.switch', $this->company->id))->assertSessionHasErrors('company');
        $this->assertSame((int) $this->other->id, (int) $this->agronomist->fresh()->company_id);
    }

    public function test_only_administrators_add_members_and_never_with_more_power_than_they_hold(): void
    {
        $this->invite($this->worker)->assertForbidden();
        $this->invite($this->outsider)->assertForbidden();

        // A farm manager (who may manage farm access) can bring someone in as a viewer, not as an owner.
        $this->invite($this->manager, ['Farm Owner'])->assertSessionHasErrors('roles.0');
        $this->invite($this->manager, ['Farm Viewer'])->assertSessionHasNoErrors();
    }

    public function test_removing_a_member_who_is_working_here_sends_them_home_and_the_last_membership_cannot_go(): void
    {
        $this->invite($this->admin)->assertSessionHasNoErrors();
        $this->actingAs($this->agronomist)->post(route('organisations.switch', $this->company->id));
        $m = $this->membership($this->agronomist, $this->company);

        $this->actingAs($this->admin)->patch(route('organisations.members.update', $m->id), ['status' => 'suspended'])->assertSessionHasNoErrors();
        $this->assertSame((int) $this->other->id, (int) $this->agronomist->fresh()->company_id);
        $this->assertSame('suspended', $m->fresh()->status);

        // The worker has nowhere else to go.
        $home = $this->membership($this->worker, $this->company);
        $this->actingAs($this->admin)->patch(route('organisations.members.update', $home->id), ['status' => 'suspended'])->assertSessionHasErrors('membership');
    }

    public function test_an_administrator_cannot_touch_another_organisations_memberships(): void
    {
        $theirs = $this->membership($this->agronomist, $this->other);
        $this->actingAs($this->admin)->patch(route('organisations.members.update', $theirs->id), ['roles' => ['Farm Viewer']])->assertForbidden();
    }

    public function test_a_member_from_elsewhere_can_be_given_a_farm_and_its_work(): void
    {
        $this->invite($this->admin)->assertSessionHasNoErrors();
        $farm = $this->farm('NORTH');

        $people = array_column($this->actingAs($this->admin)->get(route('agri.farms.show', $farm->uuid))->viewData('page')['props']['people'], 'id');
        $this->assertContains($this->agronomist->id, $people, 'The people picker lists members from other organisations.');

        $this->actingAs($this->admin)->post(route('agri.farms.access.store', $farm->uuid), ['user_id' => $this->agronomist->id, 'role' => 'supervisor'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(FarmMembership::withoutGlobalScopes()->where('farm_id', $farm->id)->where('user_id', $this->agronomist->id)->exists());

        $this->actingAs($this->admin)->post(route('agri.tasks.store'), ['farm_id' => $farm->uuid, 'title' => 'Soil sampling', 'priority' => 'normal',
            'assigned_to' => $this->agronomist->id])->assertSessionHasNoErrors();

        // Once working in this organisation, they see the farm and the task.
        $this->actingAs($this->agronomist)->post(route('organisations.switch', $this->company->id));
        app(\App\Modules\Agri\Services\FarmAccess::class)->flush();
        $a = $this->agronomist->fresh();
        $this->actingAs($a)->get(route('agri.farms.show', $farm->uuid))->assertOk();
        $this->assertSame(['Soil sampling'], array_column($this->actingAs($a)->get(route('agri.my-work'))->viewData('page')['props']['tasks'], 'title'));
    }

    public function test_somebody_with_no_account_cannot_be_added_here(): void
    {
        $this->actingAs($this->admin)->post(route('organisations.members.store'), ['email' => 'nobody@nowhere.test', 'roles' => ['Farm Viewer']])
            ->assertSessionHasErrors('email');
        $this->assertSame(0, FarmTask::withoutGlobalScopes()->count());
    }

    public function test_farm_people_land_on_farm_screens_after_signing_in(): void
    {
        $worker = Role::firstOrCreate(['name' => 'Farm Worker', 'guard_name' => 'web']);
        $worker->syncPermissions(RoleCatalog::suggestedRoles()['Farm Worker']);
        $picker = $this->makeUser('picker@farm.test', $worker, $this->company);

        $this->assertSame(route('agri.my-work'), \App\Core\Auth\Support\LandingPage::for($picker));
        $this->assertSame(route('agri.dashboard'), \App\Core\Auth\Support\LandingPage::for($this->agronomist));
    }
}
