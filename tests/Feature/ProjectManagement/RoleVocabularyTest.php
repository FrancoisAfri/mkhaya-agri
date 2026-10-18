<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;
use App\Modules\Operations\ProjectManagement\Services\RoleProvisioner;
use App\Modules\Operations\ProjectManagement\Services\TeamService;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Models\Company;

/**
 * The roles a member can be given are the roles that actually exist.
 *
 * Project permissions resolve through pm_project_members.role_key → pm_roles → the catalogue. A
 * key offered by TeamService but absent from RoleCatalog does not grant a smaller set of
 * permissions; it grants none, silently, on a screen that names a role. This suite is the guard
 * against the two vocabularies drifting apart again.
 */
class RoleVocabularyTest extends ProjectManagementTestCase
{
    /**
     * No *new* orphan may appear.
     *
     * Three are known and listed on the service with the reason they were left alone. Anything
     * beyond those three is drift, and this fails the moment it is introduced.
     */
    public function test_no_new_assignable_role_is_missing_from_the_catalogue(): void
    {
        $catalogue = array_keys(RoleCatalog::builtIn());

        $orphans = array_values(array_diff(TeamService::ROLES, $catalogue));

        $this->assertSame(
            TeamService::KNOWN_ORPHANED_ROLES,
            $orphans,
            "TeamService offers a role RoleCatalog does not define, so a member given it resolves "
            . 'to no permissions at all. Add it to RoleCatalog::builtIn() with a permission set, '
            . 'or rename it onto an existing role.',
        );
    }

    public function test_every_non_orphaned_role_resolves_to_a_provisioned_role_row(): void
    {
        $company = Company::create(['name' => 'Vocabulary Co']);

        $this->asCompany($company, function () {
            app(RoleProvisioner::class)->ensure();

            $usable = array_diff(TeamService::ROLES, TeamService::KNOWN_ORPHANED_ROLES);

            foreach ($usable as $role) {
                $this->assertNotNull(
                    \App\Modules\Operations\ProjectManagement\Models\PmRole::where('key', $role)->first(),
                    "No pm_roles row was provisioned for '{$role}'.",
                );
            }
        });
    }

    /** The orphan list is a record of a defect, not a place to put anything inconvenient. */
    public function test_the_orphan_list_contains_only_roles_that_are_offered(): void
    {
        $this->assertSame(
            [],
            TeamService::KNOWN_ORPHANED_ROLES,
            'Every assignable role now carries permissions. This list exists to be empty.',
        );

        foreach (TeamService::KNOWN_ORPHANED_ROLES as $role) {
            $this->assertContains(
                $role,
                TeamService::ROLES,
                "'{$role}' is listed as a known orphan but is no longer assignable. Remove it.",
            );
        }
    }

    /**
     * Being in the catalogue is not the same as being able to do anything.
     *
     * The three roles that were orphaned would have passed a membership check and then failed
     * every permission check behind it. This asserts the thing that actually matters: each role
     * a tenant can assign resolves to a real permission set, and the ones meant to stay outside
     * the money do not carry cost visibility.
     */
    public function test_every_assignable_role_grants_something(): void
    {
        $catalogue = RoleCatalog::builtIn();

        foreach (TeamService::ROLES as $role) {
            $this->assertArrayHasKey($role, $catalogue, "'{$role}' is offered but undefined.");
            $this->assertNotEmpty(
                $catalogue[$role]['permissions'],
                "'{$role}' resolves to an empty permission set, so holding it does nothing.",
            );
            $this->assertContains(
                RoleCatalog::VIEW_PROJECTS,
                $catalogue[$role]['permissions'],
                "'{$role}' cannot open the project they were added to.",
            );
        }

        foreach (['planner', 'supervisor', 'observer', 'client', 'contractor'] as $role) {
            $this->assertNotContains(
                RoleCatalog::VIEW_COSTS,
                $catalogue[$role]['permissions'],
                "'{$role}' must not see cost or margin.",
            );
        }
    }

    /**
     * The rename is what lets a subcontractor reach the portal built for them.
     *
     * PortalController selects on `role_key = 'contractor'`. Before the rename the only role the
     * team screen offered for that person was `subcontractor`, which that query never matched.
     */
    public function test_the_contractor_role_is_assignable_and_is_the_key_the_portal_looks_for(): void
    {
        $this->assertContains('contractor', TeamService::ROLES);
        $this->assertContains('contractor', RoleCatalog::EXTERNAL_ROLES);
        $this->assertNotContains('subcontractor', TeamService::ROLES);
    }

    public function test_a_retired_role_key_is_translated_rather_than_stored(): void
    {
        $company = Company::create(['name' => 'Rename Co']);

        $this->asCompany($company, function () {
            app(RoleProvisioner::class)->ensure();
            $project = $this->makeProject();

            $member = app(TeamService::class)->add($project, [
                'display_name' => 'Old Payload Foreman',
                'role_key'     => 'subcontractor',
            ]);

            $this->assertSame('contractor', $member->role_key);
        });
    }

    public function test_a_retired_role_key_is_translated_on_update_too(): void
    {
        $company = Company::create(['name' => 'Rename Update Co']);

        $this->asCompany($company, function () {
            app(RoleProvisioner::class)->ensure();
            $project = $this->makeProject();
            $team = app(TeamService::class);

            $member = $team->add($project, [
                'display_name' => 'Site Lead',
                'role_key'     => 'team_member',
            ]);

            $team->update($member, ['role_key' => 'subcontractor']);

            $this->assertSame('contractor', $member->fresh()->role_key);
        });
    }

    public function test_an_unknown_role_is_still_refused(): void
    {
        $company = Company::create(['name' => 'Bad Role Co']);

        $this->asCompany($company, function () {
            $project = $this->makeProject();

            $this->expectException(\InvalidArgumentException::class);

            app(TeamService::class)->add($project, [
                'display_name' => 'Nobody',
                'role_key'     => 'chief_wizard',
            ]);
        });
    }

    /**
     * The point of the whole change: a member on an assignable role holds real permissions.
     *
     * Asserted through PermissionEvaluator rather than by reading pm_roles, because that is the
     * path the policies actually take.
     */
    public function test_a_contractor_member_actually_holds_permissions(): void
    {
        $company = Company::create(['name' => 'Grants Co']);

        $this->asCompany($company, function () {
            app(RoleProvisioner::class)->ensure();
            $project = $this->makeProject();

            ProjectMember::create([
                'project_id'   => $project->id,
                'user_id'      => $this->outsider->id,
                'role_key'     => 'contractor',
                'display_name' => 'Contractor Co',
                'is_active'    => true,
            ]);

            $evaluator = app(PermissionEvaluator::class);
            $evaluator->flush();

            $this->assertTrue(
                $evaluator->can($this->outsider, RoleCatalog::VIEW_PROJECTS, $project),
                'A contractor member should be able to see the project they are working on.',
            );
            $this->assertFalse(
                $evaluator->can($this->outsider, RoleCatalog::VIEW_COSTS, $project),
                'A contractor must never see cost.',
            );
        });
    }
}
