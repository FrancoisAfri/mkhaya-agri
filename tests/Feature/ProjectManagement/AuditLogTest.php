<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\AuditEntry;
use App\Modules\Operations\ProjectManagement\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The PM audit trail (PM-1024): every mutation recorded with actor, diff, IP and request id, and
 * the record itself immutable.
 */
class AuditLogTest extends ProjectManagementTestCase
{
    public function test_creating_a_project_writes_an_audit_entry(): void
    {
        $this->actingAs($this->manager);

        $project = $this->makeProject(['code' => 'PRJ-AUD']);

        $entry = AuditEntry::forEntity('pm_projects', $project->id)->where('action', 'created')->firstOrFail();

        $this->assertSame($this->manager->id, $entry->actor_id);
        $this->assertSame('user', $entry->actor_type);
        $this->assertSame($project->uuid, $entry->entity_uuid);
        $this->assertSame('PRJ-AUD', $entry->after['code']);
        $this->assertNotNull($entry->request_id);
    }

    public function test_an_update_records_only_what_changed_with_its_previous_value(): void
    {
        $this->actingAs($this->manager);

        $project = $this->makeProject(['name' => 'Original name', 'code' => 'PRJ-DIFF']);
        $project->update(['name' => 'Renamed']);

        $entry = AuditEntry::forEntity('pm_projects', $project->id)->where('action', 'updated')->firstOrFail();

        $this->assertSame('Renamed', $entry->after['name']);
        $this->assertSame('Original name', $entry->before['name']);
        // A diff, not a snapshot: untouched fields are absent.
        $this->assertArrayNotHasKey('code', $entry->after);
        $this->assertArrayNotHasKey('updated_at', $entry->after);
    }

    public function test_a_delete_is_recorded_as_a_soft_delete(): void
    {
        $this->actingAs($this->manager);

        $project = $this->makeProject();
        $project->delete();

        $this->assertTrue(
            AuditEntry::forEntity('pm_projects', $project->id)->where('action', 'deleted')->exists()
        );
    }

    public function test_the_actor_label_is_stamped_at_the_time(): void
    {
        $this->actingAs($this->manager);

        $project = $this->makeProject();

        $entry = AuditEntry::forEntity('pm_projects', $project->id)->firstOrFail();

        // The label must survive the person being renamed or removed later, so it is stored, not
        // joined. There is no users.name column here, so it falls back to the email.
        $this->assertSame($this->manager->email, $entry->actor_label);
    }

    public function test_entries_are_searchable_by_entity_and_by_actor(): void
    {
        $this->actingAs($this->manager);

        $a = $this->makeProject(['code' => 'PRJ-A']);
        $this->makeProject(['code' => 'PRJ-B']);

        $this->assertSame(1, AuditEntry::forEntity('pm_projects', $a->id)->count());
        $this->assertSame(2, AuditEntry::byActor($this->manager->id)->where('entity_type', 'pm_projects')->count());
    }

    // ── Immutability ─────────────────────────────────────────────────────────

    public function test_the_database_refuses_to_update_an_audit_entry(): void
    {
        $this->actingAs($this->manager);
        $project = $this->makeProject();
        $entry = AuditEntry::forEntity('pm_projects', $project->id)->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::statement('UPDATE pm_audit_log SET action = ? WHERE id = ?', ['tampered', $entry->id]);
    }

    public function test_the_database_refuses_to_delete_an_audit_entry(): void
    {
        $this->actingAs($this->manager);
        $project = $this->makeProject();
        $entry = AuditEntry::forEntity('pm_projects', $project->id)->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::statement('DELETE FROM pm_audit_log WHERE id = ?', [$entry->id]);
    }

    public function test_the_model_refuses_before_reaching_the_database(): void
    {
        $this->actingAs($this->manager);
        $project = $this->makeProject();
        $entry = AuditEntry::forEntity('pm_projects', $project->id)->firstOrFail();

        try {
            $entry->update(['action' => 'tampered']);
            $this->fail('Updating an audit entry should throw.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    // ── Request correlation ──────────────────────────────────────────────────

    public function test_entries_from_one_request_share_a_request_id(): void
    {
        $this->actingAs($this->manager)
            ->post(route('project-management.projects.store'), [
                'name' => 'Correlated', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            ])
            ->assertRedirect();

        $project = Project::firstOrFail();
        $ids = AuditEntry::forEntity('pm_projects', $project->id)->pluck('request_id')->unique();

        $this->assertCount(1, $ids);
        $this->assertNotNull($ids->first());
    }

    public function test_an_inbound_request_id_is_adopted_so_a_trace_stays_one_trace(): void
    {
        $this->actingAs($this->manager)
            ->withHeaders(['X-Request-Id' => 'gateway-trace-42'])
            ->post(route('project-management.projects.store'), [
                'name' => 'Traced', 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg',
            ])
            ->assertRedirect()
            ->assertHeader('X-Request-Id', 'gateway-trace-42');

        $this->assertTrue(
            AuditEntry::where('request_id', 'gateway-trace-42')->exists()
        );
    }
}
