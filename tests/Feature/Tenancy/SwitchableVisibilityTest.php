<?php

namespace Tests\Feature\Tenancy;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who is offered the company switcher, across every kind of account.
 *
 * The switcher is fed a list of every company on the installation, so the question is not merely
 * whether the control renders — it is who receives that list in their page props at all. A tenant
 * user who could read it would learn the name of every other customer on the platform, which is a
 * disclosure even if no control is ever drawn.
 */
class SwitchableVisibilityTest extends TestCase
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
            'email' => 'vis-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $companyId,
            'is_platform_admin' => $platformAdmin,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_an_ordinary_company_user_never_receives_the_list(): void
    {
        $companyId = (int) DB::table('companies')->min('id');

        $this->actingAs($this->user($companyId, false))
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('switchableCompanies', null));
    }

    public function test_a_company_user_with_no_company_still_never_receives_the_list(): void
    {
        // The account that would otherwise slip through: not an admin, but with nothing resolved,
        // so it reaches the same "no active company" screen a platform admin does.
        $this->actingAs($this->user(null, false))
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('switchableCompanies', null));
    }

    public function test_a_platform_admin_already_inside_a_company_is_not_offered_it(): void
    {
        $companyId = (int) DB::table('companies')->min('id');

        // Impersonating: a tenant is resolved, so there is nothing to choose and no list is built.
        $this->actingAs($this->user(null, true))
            ->withSession(['impersonate_company_id' => $companyId])
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('switchableCompanies', null));
    }

    public function test_only_a_stuck_platform_admin_receives_it(): void
    {
        $this->actingAs($this->user(null, true))
            ->get('/sheq/settings')
            ->assertOk()
            ->assertInertia(function ($page) {
                $list = $page->toArray()['props']['switchableCompanies'] ?? null;
                $this->assertIsArray($list);
                $this->assertNotEmpty($list);
            });
    }
}
