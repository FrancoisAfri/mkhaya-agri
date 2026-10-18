<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\ChecklistItem;
use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\ProjectMember;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TimeEntry;
use App\Modules\Operations\ProjectManagement\Services\AssignmentService;
use App\Modules\Operations\ProjectManagement\Services\CollaborationService;
use App\Modules\Operations\ProjectManagement\Services\EvidenceLedger;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use App\Modules\Tenancy\Models\Company;
use Laravel\Sanctum\Sanctum;

/**
 * The offline field loop over the API (EPIC-060, EPIC-061).
 *
 * Two properties carry this whole epic. One request has to assemble a day's work, because the sync
 * happens in a yard with one bar of signal before the bakkie leaves. And every write-back has to be
 * replay-safe, because a device that loses signal mid-sync retries the entire queue — a second
 * attempt that books another eight hours is the worst failure this loop could have.
 */
class FieldSyncApiTest extends ProjectManagementTestCase
{
    private Company $company;

    private Project $project;

    private Task $task;

    private ProjectMember $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Alpha Construction']);
        $this->entitleCompany($this->company);
        $this->manager->forceFill(['company_id' => $this->company->id])->save();
        $this->manager = $this->manager->fresh();

        $this->asCompany($this->company, function () {
            $this->project = $this->makeProject(['code' => 'PRJ-FIELD']);
            $this->task = app(TaskService::class)->create($this->project, [
                'name' => 'Lay blockwork', 'planned_quantity' => 200, 'quantity_unit' => 'm²',
            ]);
            $this->member = ProjectMember::create([
                'project_id' => $this->project->id, 'user_id' => $this->manager->id,
                'role_key' => 'team_member', 'display_name' => 'Thabo Nkosi', 'is_active' => true,
            ]);
            app(AssignmentService::class)->assign($this->task, $this->member);
        });
    }

    private function asDevice(): self
    {
        Sanctum::actingAs($this->manager, ['*']);

        return $this;
    }

    // ── The bundle ───────────────────────────────────────────────────────────

    public function test_one_request_assembles_a_days_work(): void
    {
        $this->asCompany($this->company, fn () => app(CollaborationService::class)
            ->addChecklistItem($this->task, 'Check the mortar mix'));

        $body = $this->asDevice()->getJson('/api/v1/pm/sync')->assertOk()->json();

        $this->assertSame('PRJ-FIELD', $body['projects'][0]['code']);
        $this->assertSame('Lay blockwork', $body['tasks'][0]['name']);
        $this->assertTrue($body['tasks'][0]['is_assigned']);
        $this->assertSame('Check the mortar mix', $body['checklists'][0]['text']);
        // What "done" is measured in has to reach the phone, or the field can only ever guess.
        // assertEquals, not assertSame: a whole number serialises to JSON without its decimal.
        $this->assertEquals(200, $body['tasks'][0]['planned_quantity']);
        $this->assertSame('m²', $body['tasks'][0]['quantity_unit']);
        // The vocabulary travels too, so a device validates before spending a bad connection.
        $this->assertContains('quantity.installed', $body['taxonomy']['types']);
    }

    public function test_a_later_sync_carries_only_what_moved(): void
    {
        $first = $this->asDevice()->getJson('/api/v1/pm/sync')->assertOk()->json();

        $this->assertCount(1, $first['tasks']);

        $this->travel(2)->minutes();

        $body = $this->asDevice()
            ->getJson('/api/v1/pm/sync?since=' . urlencode($first['synced_at']))
            ->assertOk()->json();

        $this->assertSame([], $body['tasks'], 'Nothing changed, so nothing comes back.');

        $this->asCompany($this->company, fn () => app(TaskService::class)
            ->update($this->task->fresh(), ['name' => 'Lay blockwork — revised']));

        $body = $this->asDevice()
            ->getJson('/api/v1/pm/sync?since=' . urlencode($first['synced_at']))
            ->assertOk()->json();

        $this->assertCount(1, $body['tasks']);
        $this->assertSame('Lay blockwork — revised', $body['tasks'][0]['name']);
    }

    public function test_the_bundle_says_when_it_had_to_truncate(): void
    {
        $body = $this->asDevice()->getJson('/api/v1/pm/sync')->assertOk()->json();

        // Honest about its own limits: a client that silently received part of the list would show
        // a foreman a day missing the work they came for.
        $this->assertFalse($body['limits']['truncated']);
        $this->assertSame(30, $body['limits']['evidence_days']);
    }

    public function test_another_tenants_work_is_not_in_the_bundle(): void
    {
        $bravo = Company::create(['name' => 'Bravo Civils']);

        $this->asCompany($bravo, function () use ($bravo) {
            $theirProject = $this->makeProject(['code' => 'PRJ-THEIRS']);
            app(TaskService::class)->create($theirProject, ['name' => 'Their work']);
        });

        $body = $this->asDevice()->getJson('/api/v1/pm/sync')->assertOk()->json();

        $this->assertSame(['PRJ-FIELD'], array_column($body['projects'], 'code'));
        $this->assertSame(['Lay blockwork'], array_column($body['tasks'], 'name'));
    }

    public function test_the_bundle_needs_a_token(): void
    {
        $this->getJson('/api/v1/pm/sync')->assertUnauthorized();
    }

    // ── Writing back ─────────────────────────────────────────────────────────

    public function test_a_task_can_be_completed_from_the_field(): void
    {
        $this->asDevice()
            ->patchJson("/api/v1/pm/tasks/{$this->task->uuid}", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('status', 'done');

        $this->assertSame('done', $this->task->fresh()->status);
    }

    /**
     * The property the whole sync design rests on: a retried queue changes nothing the second time.
     */
    public function test_replaying_the_same_write_changes_nothing(): void
    {
        $key = 'device-7f3a-queue-item-12';

        $first = $this->asDevice()->withHeader('Idempotency-Key', $key)
            ->patchJson("/api/v1/pm/tasks/{$this->task->uuid}", ['status' => 'in_progress'])
            ->assertOk();

        $second = $this->asDevice()->withHeader('Idempotency-Key', $key)
            ->patchJson("/api/v1/pm/tasks/{$this->task->uuid}", ['status' => 'in_progress'])
            ->assertOk();

        $this->assertSame($first->json(), $second->json());
        // One status change, so one piece of evidence — not two.
        $this->assertSame(1, \App\Modules\Operations\ProjectManagement\Models\EvidenceEvent::where(
            'event_type', 'task.started'
        )->count());
    }

    public function test_booking_the_same_hours_twice_does_not_double_them(): void
    {
        $key = 'device-7f3a-time-1';
        $payload = [
            'project_id' => $this->project->uuid,
            'task_id'    => $this->task->uuid,
            'worked_on'  => now()->subDay()->toDateString(),
            'hours'      => 8,
        ];

        $this->asDevice()->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/pm/time', $payload)->assertCreated();

        $this->asDevice()->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/pm/time', $payload)->assertCreated();

        $this->assertSame(1, TimeEntry::count());
        $this->assertSame('8.00', TimeEntry::first()->hours);
    }

    public function test_hours_from_the_field_arrive_submitted_not_approved(): void
    {
        $this->asDevice()->postJson('/api/v1/pm/time', [
            'project_id' => $this->project->uuid,
            'worked_on'  => now()->subDay()->toDateString(),
            'hours'      => 6,
        ])->assertCreated()->assertJsonPath('status', 'submitted');
    }

    public function test_a_checklist_item_can_be_ticked_offline_and_synced(): void
    {
        $item = $this->asCompany($this->company, fn () => app(CollaborationService::class)
            ->addChecklistItem($this->task, 'Check the mortar mix'));

        $this->asDevice()
            ->patchJson("/api/v1/pm/checklist/{$item->uuid}", ['is_done' => true])
            ->assertOk()
            ->assertJsonPath('is_done', true);

        $this->assertTrue(ChecklistItem::find($item->id)->is_done);
    }

    public function test_time_cannot_be_booked_against_a_project_you_are_not_on(): void
    {
        $other = $this->asCompany($this->company, fn () => $this->makeProject(['code' => 'PRJ-NOTMINE']));

        $this->asDevice()->postJson('/api/v1/pm/time', [
            'project_id' => $other->uuid,
            'worked_on'  => now()->subDay()->toDateString(),
            'hours'      => 8,
        ])->assertStatus(422)
          ->assertJsonPath('title', 'Unprocessable');

        $this->assertSame(0, TimeEntry::count());
    }

    public function test_a_write_to_another_tenants_task_is_a_404_not_a_403(): void
    {
        $bravo = Company::create(['name' => 'Bravo Civils']);

        $theirTask = $this->asCompany($bravo, function () {
            $theirProject = $this->makeProject(['code' => 'PRJ-THEIRS']);

            return app(TaskService::class)->create($theirProject, ['name' => 'Their work']);
        });

        // 404, never 403: a 403 would confirm the task exists (PM-1010).
        $this->asDevice()
            ->patchJson("/api/v1/pm/tasks/{$theirTask->uuid}", ['status' => 'done'])
            ->assertNotFound();

        $this->assertSame('not_started', $theirTask->fresh()->status);
    }

    public function test_a_write_with_nothing_in_it_says_so(): void
    {
        $this->asDevice()
            ->patchJson("/api/v1/pm/tasks/{$this->task->uuid}", [])
            ->assertStatus(422)
            ->assertJsonPath('type', 'https://mkhaya.app/problems/unprocessable');
    }

    public function test_evidence_recorded_on_site_comes_back_in_the_next_bundle(): void
    {
        $this->asCompany($this->company, fn () => app(EvidenceLedger::class)->append([
            'project_id'    => $this->project->id,
            'subject_type'  => 'task',
            'subject_id'    => $this->task->id,
            'subject_uuid'  => $this->task->uuid,
            'event_type'    => 'quantity.installed',
            'source_module' => 'mobile',
            'occurred_at'   => now()->subHours(2),
            'occurred_tz'   => 'Africa/Johannesburg',
            'actor_type'    => 'device',
            'quantity'      => 40,
            'payload'       => ['quantity' => 40],
        ]));

        $body = $this->asDevice()->getJson('/api/v1/pm/sync')->assertOk()->json();

        $this->assertSame('quantity.installed', $body['evidence'][0]['event_type']);
        $this->assertEquals(40, $body['evidence'][0]['quantity']);
    }
}
