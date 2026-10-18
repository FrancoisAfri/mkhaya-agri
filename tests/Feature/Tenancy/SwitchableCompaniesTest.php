<?php

namespace Tests\Feature\Tenancy;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The company switcher offered on the "no active company" notices.
 *
 * It replaced prose that told people to use a control in the user menu, where the control is not.
 * The list has to be shared to be usable, and must not be shared to anyone who cannot act on it —
 * an ordinary user seeing a roster of every company on the installation would be a tenant leak.
 */
class SwitchableCompaniesTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();

        parent::tearDown();
    }

    private function user(?int $companyId, bool $platformAdmin): User
    {
        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'switch-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $companyId,
            'is_platform_admin' => $platformAdmin,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_a_platform_admin_with_no_tenant_is_offered_the_companies(): void
    {
        $this->actingAs($this->user(null, true))
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(function ($page) {
                $companies = $page->toArray()['props']['switchableCompanies'] ?? null;

                $this->assertIsArray($companies);
                $this->assertNotEmpty($companies, 'A stuck admin was offered nothing to switch into.');
                $this->assertArrayHasKey('id', $companies[0]);
                $this->assertArrayHasKey('name', $companies[0]);
            });
    }

    public function test_an_ordinary_user_is_never_handed_the_company_list(): void
    {
        $companyId = (int) DB::table('companies')->min('id');

        $this->actingAs($this->user($companyId, false))
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('switchableCompanies', null));
    }

    public function test_suspended_companies_are_not_offered(): void
    {
        $suspended = DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => 'Suspended '.Str::random(6),
            'slug' => 'suspended-'.Str::random(6),
            'status' => 'suspended',
            'suspended_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user(null, true))
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(function ($page) use ($suspended) {
                $ids = array_column($page->toArray()['props']['switchableCompanies'] ?? [], 'id');

                // Switching into one of these is refused by ResolveCompany with a 403, so offering
                // it would only walk the admin into a dead end with extra steps.
                $this->assertNotContains($suspended, $ids);
            });
    }
}
