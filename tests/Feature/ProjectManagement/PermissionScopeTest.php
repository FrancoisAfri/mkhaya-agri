<?php

namespace Tests\Feature\ProjectManagement;

use App\Core\User\Models\User;
use App\Modules\Operations\ProjectManagement\Models\PermissionOverride;
use App\Modules\Operations\ProjectManagement\Models\PmRole;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\PermissionEvaluator;
use App\Modules\Operations\ProjectManagement\Services\RoleProvisioner;
use App\Modules\Operations\ProjectManagement\Support\ProjectPresenter;
use App\Modules\Operations\ProjectManagement\Support\RoleCatalog;

/**
 * Scoped permissions (PM-1020, PM-1021, PM-1023): a role on one project grants nothing on another,
 * a deny beats every grant, and external roles never receive internal cost.
 */
class PermissionScopeTest extends ProjectManagementTestCase
{
    private PermissionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        app(RoleProvisioner::class)->ensure();
        $this->evaluator = app(PermissionEvaluator::class);
    }

    // ── Scope ────────────────────────────────────────────────────────────────

    public function test_a_project_role_grants_only_on_that_project(): void
    {
        $theirs = $this->makeProject(['code' => 'PRJ-THEIRS']);
        $ours = $this->makeProject(['code' => 'PRJ-OURS']);

        $user = $this->memberUser($ours, 'project_manager');

        $this->assertTrue($this->evaluator->can($user, RoleCatalog::UPDATE_PROJECTS, $ours));
        $this->assertFalse(
            $this->evaluator->can($user, RoleCatalog::UPDATE_PROJECTS, $theirs),
            'A role held on one project must grant nothing on another.'
        );
    }

    public function test_a_project_only_user_lists_only_their_projects(): void
    {
        $mine = $this->makeProject(['code' => 'PRJ-MINE']);
        $this->makeProject(['code' => 'PRJ-OTHER']);

        $user = $this->memberUser($mine, 'team_member');

        $visible = Project::query()->visibleTo($user)->pluck('code')->all();

        $this->assertSame(['PRJ-MINE'], $visible);
    }

    public function test_a_user_with_project_scoped_access_cannot_list_others_through_the_ui(): void
    {
        $mine = $this->makeProject(['code' => 'PRJ-MINE']);
        $this->makeProject(['code' => 'PRJ-OTHER']);

        $user = $this->memberUser($mine, 'team_member');

        $response = $this->actingAs($user)->get(route('project-management.projects.index'));

        $response->assertOk();
        $codes = array_column($response->viewData('page')['props']['projects']['data'], 'code');

        $this->assertSame(['PRJ-MINE'], $codes);
    }

    public function test_a_tenant_wide_grant_still_sees_everything(): void
    {
        $this->makeProject(['code' => 'PRJ-1']);
        $this->makeProject(['code' => 'PRJ-2']);

        // The manager holds pm.projects.view globally through their platform role.
        $visible = Project::query()->visibleTo($this->manager)->pluck('code')->all();

        $this->assertEqualsCanonicalizing(['PRJ-1', 'PRJ-2'], $visible);
    }

    public function test_a_permission_without_a_project_needs_a_tenant_wide_grant(): void
    {
        $project = $this->makeProject();
        $user = $this->memberUser($project, 'project_manager');

        // They may update *that* project, but "can this person update projects" — asked with no
        // project in mind — is only answerable from a global grant, which they do not have.
        $this->assertTrue($this->evaluator->can($user, RoleCatalog::UPDATE_PROJECTS, $project));
        $this->assertFalse($this->evaluator->can($user, RoleCatalog::UPDATE_PROJECTS));
    }

    // ── Union and deny ───────────────────────────────────────────────────────

    public function test_the_most_permissive_of_global_and_project_wins(): void
    {
        $project = $this->makeProject();

        // Globally a field worker (view + record), but Project Manager on this one job.
        ProjectMember::create([
            'project_id' => $project->id, 'user_id' => $this->fieldWorker->id, 'role_key' => 'project_manager',
        ]);

        $this->evaluator->flush();

        $this->assertTrue($this->evaluator->can($this->fieldWorker, RoleCatalog::MANAGE_SCHEDULE, $project));
        $this->assertTrue($this->evaluator->can($this->fieldWorker, RoleCatalog::VIEW_EVIDENCE, $project));
    }

    public function test_a_deny_override_beats_every_grant(): void
    {
        $project = $this->makeProject();

        $this->assertTrue($this->evaluator->can($this->manager, RoleCatalog::UPDATE_PROJECTS, $project));

        PermissionOverride::create([
            'user_id'    => $this->manager->id,
            'project_id' => $project->id,
            'permission' => RoleCatalog::UPDATE_PROJECTS,
            'effect'     => PermissionOverride::DENY,
            'reason'     => 'Conflict of interest on this job',
        ]);

        $this->evaluator->flush();

        $this->assertFalse(
            $this->evaluator->can($this->manager, RoleCatalog::UPDATE_PROJECTS, $project),
            'A deny must override a grant the user otherwise holds.'
        );
        // …and only on that project.
        $this->assertTrue($this->evaluator->can($this->manager, RoleCatalog::UPDATE_PROJECTS, $this->makeProject(['code' => 'PRJ-ELSE'])));
    }

    public function test_an_allow_override_grants_without_a_role(): void
    {
        $project = $this->makeProject();
        $user = $this->memberUser($project, 'read_only');

        $this->assertFalse($this->evaluator->can($user, RoleCatalog::VIEW_COSTS, $project));

        PermissionOverride::create([
            'user_id'    => $user->id,
            'project_id' => $project->id,
            'permission' => RoleCatalog::VIEW_COSTS,
            'effect'     => PermissionOverride::ALLOW,
        ]);

        $this->evaluator->flush();

        $this->assertTrue($this->evaluator->can($user, RoleCatalog::VIEW_COSTS, $project));
    }

    // ── External roles never see money ───────────────────────────────────────

    public function test_a_client_does_not_receive_cost_fields(): void
    {
        $project = $this->makeProject(['budget_amount' => '1500000.00', 'currency' => 'ZAR']);
        $client = $this->memberUser($project, 'client');

        $row = ProjectPresenter::redactFor(ProjectPresenter::summary($project), $client, $project);

        $this->assertArrayNotHasKey('budget_amount', $row);
        $this->assertArrayNotHasKey('currency', $row);
        // They still see the project itself — redaction is about money, not access.
        $this->assertSame($project->name, $row['name']);
    }

    public function test_a_contractor_does_not_receive_cost_fields(): void
    {
        $project = $this->makeProject(['budget_amount' => '900000.00', 'currency' => 'ZAR']);
        $contractor = $this->memberUser($project, 'contractor');

        $row = ProjectPresenter::redactFor(ProjectPresenter::detail($project), $contractor, $project);

        $this->assertArrayNotHasKey('budget_amount', $row);
    }

    public function test_an_internal_role_does_receive_cost_fields(): void
    {
        $project = $this->makeProject(['budget_amount' => '250000.00', 'currency' => 'ZAR']);
        $pm = $this->memberUser($project, 'project_manager');

        $row = ProjectPresenter::redactFor(ProjectPresenter::summary($project), $pm, $project);

        $this->assertSame('250000.00', $row['budget_amount']);
        $this->assertSame('ZAR', $row['currency']);
    }

    public function test_the_client_redaction_holds_through_the_ui(): void
    {
        $project = $this->makeProject(['budget_amount' => '1000000.00', 'currency' => 'ZAR']);
        $client = $this->memberUser($project, 'client');
        $this->grantModuleTier($client);

        $props = $this->actingAs($client)
            ->get(route('project-management.projects.show', $project->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayNotHasKey('budget_amount', $props['project']);
    }

    // ── Role catalogue ───────────────────────────────────────────────────────

    public function test_every_built_in_role_is_provisioned_for_the_tenant(): void
    {
        // Derived from the catalogue, not hard-coded: adding a role to RoleCatalog is a product
        // decision, and having it fail a count assertion teaches people to edit the test rather
        // than read it.
        $this->assertCount(count(RoleCatalog::builtIn()), PmRole::all());
        $this->assertEqualsCanonicalizing(
            array_keys(RoleCatalog::builtIn()),
            PmRole::pluck('key')->all()
        );
    }

    public function test_provisioning_is_idempotent_and_leaves_tenant_clones_alone(): void
    {
        $clone = app(RoleProvisioner::class)->clone(
            PmRole::where('key', 'project_manager')->firstOrFail(),
            'site_lead',
            'Site Lead',
            [RoleCatalog::VIEW_PROJECTS]
        );

        app(RoleProvisioner::class)->ensure();

        // The built-ins plus the one clone made above.
        $this->assertCount(count(RoleCatalog::builtIn()) + 1, PmRole::all());
        $this->assertSame([RoleCatalog::VIEW_PROJECTS], $clone->fresh()->permissions);
        $this->assertFalse($clone->fresh()->is_builtin);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** A user whose only PM access is a role on one project. */
    private function memberUser(Project $project, string $roleKey): User
    {
        $user = $this->makeUser("pm-{$roleKey}-" . uniqid() . '@example.test', null);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id'    => $user->id,
            'role_key'   => $roleKey,
        ]);

        $this->evaluator->flush();

        return $user;
    }
}
