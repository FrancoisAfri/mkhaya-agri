<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\ExternalRef;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Services\ClientSyncService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Operations\ProjectManagement\Services\TimesheetService;

/**
 * Putting people on a project, and saying who it is for (EPIC-030, PM-1043).
 *
 * Both were holes rather than bugs: membership could only be created in code, so the Delivery page
 * said "nobody has been added to this project yet" with no way to add anybody — and everything
 * downstream of it (assignments, timesheets, labour cost) was therefore empty by construction. The
 * client field had the same shape: a column with no way to fill it.
 */
class TeamAndClientsTest extends ProjectManagementTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject();
    }

    // ── People ───────────────────────────────────────────────────────────────

    public function test_somebody_is_added_by_name_alone(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.members.store', $this->project->uuid), [
                'display_name' => 'Sipho Dlamini',
                'role_key'     => 'supervisor',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $member = ProjectMember::where('project_id', $this->project->id)->first();

        // A name and nothing else has to be enough: a subcontractor's foreman is a person on the
        // job whether or not anybody has given him a login or an HR record.
        $this->assertSame('Sipho Dlamini', $member->display_name);
        $this->assertSame('supervisor', $member->role_key);
        $this->assertNull($member->user_id);
        $this->assertTrue($member->is_active);
    }

    public function test_a_member_appears_on_the_delivery_page_before_they_have_any_work(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.members.store', $this->project->uuid), [
                'display_name' => 'Sipho Dlamini', 'role_key' => 'team_member',
            ]);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.delivery', $this->project->uuid))
            ->assertOk()
            ->viewData('page')['props'];

        // Driven by `members`, not by `team`: `team` only carries people with work in the window,
        // so somebody added this morning would be invisible on the page that added them.
        $this->assertCount(1, $props['members']);
        $this->assertSame('Sipho Dlamini', $props['members'][0]['name']);
        $this->assertTrue($props['can']['members']);
    }

    public function test_adding_the_same_person_twice_reuses_the_row(): void
    {
        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->manager)
                ->post(route('project-management.members.store', $this->project->uuid), [
                    'display_name' => 'Sipho Dlamini', 'role_key' => 'team_member',
                ]);
        }

        // Otherwise their hours split across two members and the labour report double-counts them.
        $this->assertSame(1, ProjectMember::where('project_id', $this->project->id)->count());
    }

    public function test_someone_with_booked_hours_is_deactivated_rather_than_deleted(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.members.store', $this->project->uuid), [
                'display_name' => 'Sipho Dlamini', 'role_key' => 'team_member',
            ]);

        $member = ProjectMember::first();

        app(TimesheetService::class)->log($this->project, $member, [
            'worked_on' => now()->subDay()->toDateString(), 'hours' => 6,
        ]);

        $this->actingAs($this->manager)
            ->delete(route('project-management.members.destroy', [$this->project->uuid, $member->uuid]))
            ->assertRedirect();

        // The hours are a fact about what happened; a foreign key that vanishes turns a costed
        // timesheet into an orphan.
        $member->refresh();
        $this->assertFalse($member->is_active);
        $this->assertFalse($member->trashed());
    }

    public function test_someone_added_by_mistake_is_genuinely_deleted(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.members.store', $this->project->uuid), [
                'display_name' => 'Typo Person', 'role_key' => 'observer',
            ]);

        $member = ProjectMember::first();

        $this->actingAs($this->manager)
            ->delete(route('project-management.members.destroy', [$this->project->uuid, $member->uuid]));

        $this->assertSame(0, ProjectMember::where('project_id', $this->project->id)->count());
    }

    public function test_a_bad_project_role_is_refused(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.members.store', $this->project->uuid), [
                'display_name' => 'Sipho Dlamini', 'role_key' => 'chief_wizard',
            ])
            ->assertSessionHasErrors('role_key');
    }

    public function test_managing_members_needs_the_grant(): void
    {
        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.members.store', $this->project->uuid), [
                'display_name' => 'Sneaky', 'role_key' => 'team_member',
            ])
            ->assertForbidden();
    }

    /** The whole point of adding somebody: the delivery chain works from end to end. */
    public function test_a_member_can_then_be_assigned_work_and_book_hours_against_it(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.members.store', $this->project->uuid), [
                'display_name' => 'Sipho Dlamini', 'role_key' => 'team_member',
            ]);

        $member = ProjectMember::first();
        $task = app(TaskService::class)->create($this->project, ['name' => 'Excavate', 'duration_minutes' => 480]);

        $this->actingAs($this->manager)
            ->post(route('project-management.assign.store', [$this->project->uuid, $task->uuid]), [
                'member_id' => $member->uuid, 'allocation_pct' => 100,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager)
            ->post(route('project-management.time.store', $this->project->uuid), [
                'member_id' => $member->uuid,
                'task_id'   => $task->uuid,
                'worked_on' => now()->subDay()->toDateString(),
                'hours'     => 8,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.insights.reports'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertNotEmpty($props['reports']['resource_load']['rows'], 'Resource allocation is still empty.');
        $this->assertNotEmpty($props['reports']['timesheets']['rows'], 'Timesheets are still empty.');
    }

    // ── Clients ──────────────────────────────────────────────────────────────

    public function test_a_client_is_created_from_the_project_form_and_sticks_to_the_project(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.clients.store'), [
                'name'  => 'Sasol Secunda',
                'email' => 'projects@sasol.example',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $ref = ExternalRef::where('local_type', 'project_client')->first();

        $this->assertSame('Sasol Secunda', $ref->display_name);
        $this->assertSame('local', $ref->source_module, 'CRM is not installed in this test.');
        $this->assertSame('projects@sasol.example', $ref->snapshot['email']);

        $this->actingAs($this->manager)
            ->put(route('project-management.projects.update', $this->project->uuid), [
                'name'      => $this->project->name,
                'currency'  => $this->project->currency,
                'timezone'  => $this->project->timezone,
                'client_id' => $ref->uuid,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($ref->id, $this->project->fresh()->client_ref_id);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.projects.show', $this->project->uuid))
            ->viewData('page')['props'];

        $this->assertSame('Sasol Secunda', $props['project']['client']);
        // The id the picker can select by, not only a name nobody can select.
        $this->assertSame($ref->uuid, $props['project']['client_id']);
    }

    public function test_the_client_search_reports_who_owns_the_list(): void
    {
        $this->actingAs($this->manager)->post(route('project-management.clients.store'), ['name' => 'Eskom']);

        $payload = $this->actingAs($this->manager)
            ->getJson(route('project-management.clients.search', ['q' => 'Esk']))
            ->assertOk()
            ->json();

        $this->assertSame('Eskom', $payload['clients'][0]['label']);
        // Without CRM, PM owns them — and the screen has to be able to say so.
        $this->assertFalse($payload['owned_by_crm']);
    }

    public function test_adoption_does_nothing_when_no_module_owns_clients(): void
    {
        $this->actingAs($this->manager)->post(route('project-management.clients.store'), ['name' => 'Eskom']);

        $sync = app(ClientSyncService::class);

        $this->assertFalse($sync->isIntegrated());
        $this->assertSame(1, $sync->pendingAdoptionCount());

        // Reported as a no-op rather than thrown: the scheduled command runs on every tenant, and
        // most of them do not have CRM. "PM owns these" is a complete state, not a pending one.
        $this->assertSame(['adopted' => 0, 'matched' => 0, 'skipped' => 0], $sync->adoptAll());
        $this->assertSame('local', ExternalRef::first()->source_module);
    }

    public function test_the_setup_page_lists_clients_with_how_many_projects_use_each(): void
    {
        $this->actingAs($this->manager)->post(route('project-management.clients.store'), ['name' => 'Eskom']);

        $ref = ExternalRef::where('local_type', 'project_client')->first();
        $this->project->forceFill(['client_ref_id' => $ref->id])->save();

        $adminRole = \App\Core\Role\Models\Role::firstOrCreate(['name' => 'pm client admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(['pm.projects.view', 'pm.settings.manage']);
        $admin = $this->makeUser('pm-client-admin@example.test', $adminRole);
        $this->grantModuleTier($admin);

        $props = $this->actingAs($admin)
            ->get(route('project-management.setup.clients'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame('Eskom', $props['clients'][0]['name']);
        $this->assertSame(1, $props['clients'][0]['projects']);
        $this->assertFalse($props['integrated']);
    }

    /** Guard against the obvious regression: the client field must not break the task chain. */
    public function test_a_project_saves_with_no_client_at_all(): void
    {
        $this->actingAs($this->manager)
            ->put(route('project-management.projects.update', $this->project->uuid), [
                'name'      => 'Renamed',
                'currency'  => $this->project->currency,
                'timezone'  => $this->project->timezone,
                'client_id' => null,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull($this->project->fresh()->client_ref_id);
        $this->assertSame('Renamed', $this->project->fresh()->name);

    }

    public function test_the_report_datasets_say_where_their_rows_come_from(): void
    {
        app(TaskService::class)->create($this->project, ['name' => 'Excavate']);

        $props = $this->actingAs($this->manager)
            ->get(route('project-management.insights.reports'))
            ->viewData('page')['props'];

        // An empty BI table with no explanation reads as a broken feature; nine times out of ten it
        // means nobody has been added to a project yet.
        foreach (['labour_cost', 'resource_load', 'timesheets'] as $key) {
            $this->assertArrayHasKey('source', $props['reports'][$key]);
            $this->assertNotEmpty($props['reports'][$key]['source']);
        }

        $this->assertSame('labour', $props['reports']['labour_cost']['source']);
        $this->assertSame('assignments', $props['reports']['resource_load']['source']);

    }
}
