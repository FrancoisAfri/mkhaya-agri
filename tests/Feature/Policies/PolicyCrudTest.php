<?php

namespace Tests\Feature\Policies;

use App\Core\User\Models\User;
use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use App\Core\Role\Models\Role;
use Tests\TestCase;

class PolicyCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $viewOnlyUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed required permissions
        Permission::firstOrCreate(['name' => 'view',   'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'modify', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'delete', 'guard_name' => 'web']);

        $adminRole = Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $adminRole->syncPermissions(['view', 'modify', 'delete']);

        $viewRole = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
        $viewRole->syncPermissions(['view']);

        $this->adminUser = User::factory()->create(['status' => 1]);
        $this->adminUser->assignRole('administrator');

        $this->viewOnlyUser = User::factory()->create(['status' => 1]);
        $this->viewOnlyUser->assignRole('user');

        PolicyCategory::create(['name' => 'General', 'status' => 1]);
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_create_policy(): void
    {
        $response = $this->post(route('policies.store'), []);
        $response->assertRedirect(route('login'));
    }

    public function test_view_only_user_cannot_store_policy(): void
    {
        $category = PolicyCategory::first();

        $this->actingAs($this->viewOnlyUser)
            ->post(route('policies.store'), [
                'name'           => 'Test Policy',
                'category_id'    => $category->id,
                'owner_id'       => $this->adminUser->id,
                'effective_date' => now()->toDateString(),
                'status'         => 'draft',
            ])
            ->assertForbidden();
    }

    // ── Successful CRUD ──────────────────────────────────────────────────────

    public function test_admin_can_create_policy(): void
    {
        $category = PolicyCategory::first();

        $response = $this->actingAs($this->adminUser)
            ->post(route('policies.store'), [
                'name'           => 'Expense Reimbursement Policy',
                'description'    => 'Governs expense claims.',
                'category_id'    => $category->id,
                'owner_id'       => $this->adminUser->id,
                'effective_date' => now()->toDateString(),
                'status'         => 'draft',
                'refresh_month'  => 6,
            ]);

        $response->assertRedirect(route('policies.index'));
        $this->assertDatabaseHas('policy', [
            'name'   => 'Expense Reimbursement Policy',
            'status' => 'draft',
        ]);
    }

    public function test_admin_can_update_policy(): void
    {
        $policy = Policy::factory()->create([
            'status'      => 'draft',
            'category_id' => PolicyCategory::first()->id,
            'owner_id'    => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->patch(route('policies.update', $policy), [
                'status' => 'active',
            ]);

        $response->assertRedirect(route('policies.index'));
        $this->assertDatabaseHas('policy', ['id' => $policy->id, 'status' => 'active']);
    }

    public function test_admin_can_delete_policy(): void
    {
        $policy = Policy::factory()->create([
            'category_id' => PolicyCategory::first()->id,
            'owner_id'    => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser)
            ->delete(route('policies.destroy', $policy));

        $response->assertRedirect(route('policies.index'));
        $this->assertSoftDeleted('policy', ['id' => $policy->id]);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_store_requires_name_and_category(): void
    {
        $this->actingAs($this->adminUser)
            ->post(route('policies.store'), [])
            ->assertSessionHasErrors(['name', 'category_id']);
    }

    public function test_refresh_month_must_be_between_1_and_12(): void
    {
        $category = PolicyCategory::first();

        $this->actingAs($this->adminUser)
            ->post(route('policies.store'), [
                'name'           => 'Test',
                'category_id'    => $category->id,
                'owner_id'       => $this->adminUser->id,
                'effective_date' => now()->toDateString(),
                'status'         => 'draft',
                'refresh_month'  => 13,
            ])
            ->assertSessionHasErrors(['refresh_month']);
    }

    public function test_review_date_must_be_after_effective_date(): void
    {
        $category = PolicyCategory::first();

        $this->actingAs($this->adminUser)
            ->post(route('policies.store'), [
                'name'           => 'Test',
                'category_id'    => $category->id,
                'owner_id'       => $this->adminUser->id,
                'effective_date' => '2026-06-01',
                'review_date'    => '2026-05-01',
                'status'         => 'draft',
            ])
            ->assertSessionHasErrors(['review_date']);
    }
}
