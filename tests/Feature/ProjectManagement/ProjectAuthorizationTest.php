<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;
use App\Modules\Operations\ProjectManagement\Models\Project;

/**
 * Authorisation is enforced at the service layer, not only in the UI (Definition of Done #4).
 * Every assertion here calls the API directly with an unauthorised role and expects 403 — hiding
 * a button proves nothing.
 */
class ProjectAuthorizationTest extends ProjectManagementTestCase
{
    public function test_a_user_without_grants_cannot_list_projects(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('project-management.projects.index'))
            ->assertForbidden();
    }

    public function test_a_user_without_grants_cannot_create_a_project(): void
    {
        $this->actingAs($this->outsider)
            ->post(route('project-management.projects.store'), $this->validProject())
            ->assertForbidden();

        $this->assertDatabaseCount('pm_projects', 0);
    }

    public function test_a_field_worker_can_view_but_not_create_a_project(): void
    {
        $this->makeProject();

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.projects.index'))
            ->assertOk();

        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.projects.store'), $this->validProject())
            ->assertForbidden();
    }

    public function test_a_field_worker_cannot_delete_a_project(): void
    {
        $project = $this->makeProject();

        $this->actingAs($this->fieldWorker)
            ->delete(route('project-management.projects.destroy', $project->uuid))
            ->assertForbidden();

        $this->assertNotSoftDeleted('pm_projects', ['id' => $project->id]);
    }

    public function test_a_manager_can_create_and_delete_a_project(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), $this->validProject())
            ->assertRedirect();

        $project = Project::firstOrFail();

        $this->actingAs($this->manager)
            ->delete(route('project-management.projects.destroy', $project->uuid))
            ->assertRedirect();

        // Soft delete, not erasure (PM-ADR-007).
        $this->assertSoftDeleted('pm_projects', ['id' => $project->id]);
    }

    public function test_changing_status_needs_its_own_grant(): void
    {
        $project = $this->makeProject(['status' => 'draft']);

        // The field worker holds neither update nor manage.
        $this->actingAs($this->fieldWorker)
            ->put(route('project-management.projects.update', $project->uuid), [
                'name'     => $project->name,
                'currency' => 'ZAR',
                'timezone' => 'Africa/Johannesburg',
                'status'   => 'active',
            ])
            ->assertForbidden();

        $this->assertSame('draft', $project->fresh()->status);
    }

    public function test_recording_evidence_requires_the_record_grant(): void
    {
        $project = $this->makeProject();

        $payload = [
            'project_id'   => $project->uuid,
            'subject_type' => 'project',
            'event_type'   => 'progress.reported',
            'occurred_at'  => now()->toIso8601String(),
            'occurred_tz'  => 'Africa/Johannesburg',
        ];

        $this->actingAs($this->outsider)
            ->post(route('project-management.evidence.store'), $payload)
            ->assertForbidden();

        // The field worker's whole job is recording evidence.
        $this->actingAs($this->fieldWorker)
            ->post(route('project-management.evidence.store'), $payload)
            ->assertRedirect();

        $this->assertSame(1, EvidenceEvent::count());
    }

    public function test_the_chain_audit_requires_the_verify_grant(): void
    {
        // Neither test role holds pm.evidence.verify — it is an auditor's grant.
        $this->actingAs($this->manager)
            ->get(route('project-management.evidence.audit'))
            ->assertForbidden();

        $this->actingAs($this->fieldWorker)
            ->get(route('project-management.evidence.audit'))
            ->assertForbidden();
    }

    public function test_module_settings_require_the_settings_grant(): void
    {
        $this->actingAs($this->manager)
            ->get(route('project-management.setup.calendars'))
            ->assertForbidden();

        $this->actingAs($this->manager)
            ->get(route('project-management.setup.integrations'))
            ->assertForbidden();
    }

    public function test_the_hub_tier_gate_blocks_the_admin_area_independently_of_permissions(): void
    {
        // Tier and permission are separate gates. Drop the manager to READ and the ADMIN setup
        // subtree must become unreachable by URL, whatever pm.* grants they hold — this is what
        // makes the hub role separation real rather than visual.
        $this->grantModuleTier($this->manager, 1);

        $this->actingAs($this->manager)
            ->get(route('project-management.setup.calendars'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_an_unauthenticated_request_is_redirected_to_login(): void
    {
        $this->get(route('project-management.projects.index'))
            ->assertRedirect(route('login'));
    }

    /** @return array<string,mixed> */
    private function validProject(): array
    {
        return [
            'name'     => 'New civils package',
            'currency' => 'ZAR',
            'timezone' => 'Africa/Johannesburg',
        ];
    }
}
