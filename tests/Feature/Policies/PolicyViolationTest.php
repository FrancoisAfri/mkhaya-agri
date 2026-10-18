<?php

namespace Tests\Feature\Policies;

use App\Core\User\Models\User;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyCategory;
use App\Modules\Policies\Models\PolicyRule;
use App\Modules\Policies\Models\PolicyViolation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;
use Tests\TestCase;

class PolicyViolationTest extends TestCase
{
    use RefreshDatabase;

    private User           $adminUser;
    private User           $regularUser;
    private PolicyViolation $violation;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view',   'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'modify', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'delete', 'guard_name' => 'web']);

        $adminRole = Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $adminRole->syncPermissions(['view', 'modify', 'delete']);

        $userRole = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
        $userRole->syncPermissions(['view']);

        $this->adminUser   = User::factory()->create(['status' => 1]);
        $this->adminUser->assignRole('administrator');

        $this->regularUser = User::factory()->create(['status' => 1]);
        $this->regularUser->assignRole('user');

        $category = PolicyCategory::create(['name' => 'HR', 'status' => 1]);
        $policy   = Policy::factory()->create([
            'category_id' => $category->id,
            'owner_id'    => $this->adminUser->id,
        ]);
        $rule = PolicyRule::factory()->create(['policy_id' => $policy->id]);

        $this->violation = PolicyViolation::factory()->create([
            'policy_id'      => $policy->id,
            'rule_id'        => $rule->id,
            'user_id'        => $this->regularUser->id,
            'severity'       => 'high',
            'outcome'        => 'blocked',
            'status'         => 'open',
            'event_snapshot' => ['action' => 'leave_request.submitted', 'days' => 20],
        ]);
    }

    // ── Listing ──────────────────────────────────────────────────────────────

    public function test_authenticated_user_can_list_violations(): void
    {
        $this->actingAs($this->regularUser)
            ->get(route('policy-violations.index'))
            ->assertOk();
    }

    public function test_unauthenticated_user_cannot_list_violations(): void
    {
        $this->get(route('policy-violations.index'))
            ->assertRedirect(route('login'));
    }

    // ── Status updates ───────────────────────────────────────────────────────

    public function test_admin_can_update_violation_status(): void
    {
        $this->actingAs($this->adminUser)
            ->patch(route('policy-violations.update', $this->violation), [
                'status' => 'under_investigation',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('policy_violations', [
            'id'     => $this->violation->id,
            'status' => 'under_investigation',
        ]);
    }

    public function test_view_only_user_cannot_update_violation(): void
    {
        $this->actingAs($this->regularUser)
            ->patch(route('policy-violations.update', $this->violation), [
                'status' => 'resolved',
            ])
            ->assertForbidden();
    }

    public function test_cannot_transition_resolved_violation_to_open(): void
    {
        $this->violation->update(['status' => 'resolved']);

        $this->actingAs($this->adminUser)
            ->patch(route('policy-violations.update', $this->violation), [
                'status' => 'open',
            ])
            ->assertSessionHasErrors(['status']);
    }

    public function test_resolved_status_sets_resolved_at_timestamp(): void
    {
        $this->actingAs($this->adminUser)
            ->patch(route('policy-violations.update', $this->violation), [
                'status' => 'resolved',
            ]);

        $this->violation->refresh();
        $this->assertSame('resolved', $this->violation->status);
        $this->assertNotNull($this->violation->resolved_at);
    }

    // ── Adding notes ─────────────────────────────────────────────────────────

    public function test_admin_can_add_note_to_violation(): void
    {
        $this->actingAs($this->adminUser)
            ->post(route('policy-violations.notes.store', $this->violation), [
                'content' => 'Initial investigation started.',
                'type'    => 'comment',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('violation_notes', [
            'violation_id' => $this->violation->id,
            'content'      => 'Initial investigation started.',
            'type'         => 'comment',
            'author_id'    => $this->adminUser->id,
        ]);
    }

    public function test_view_only_user_cannot_add_note(): void
    {
        $this->actingAs($this->regularUser)
            ->post(route('policy-violations.notes.store', $this->violation), [
                'content' => 'Trying to add a note.',
                'type'    => 'comment',
            ])
            ->assertForbidden();
    }
}
