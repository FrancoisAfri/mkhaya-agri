<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Http\Controllers\ApprovalController;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Who can be named as an approver.
 *
 * A platform administrator has `users.company_id = null` — that is precisely what makes them
 * platform-level — so every list filtered on company alone leaves them out. The symptom is not an
 * error: the person setting a customer up simply cannot find their own name in a dropdown they are
 * looking straight at, and nothing on screen says why.
 *
 * The same defect was fixed in Procurement Governance earlier for committee appointments. This
 * guards the SHEQ copy, and the boundary that keeps the fix honest: the *viewer* is added, never
 * every platform admin, because that would put a directory of the vendor's staff into each
 * customer's dropdowns to solve a problem nobody has.
 */
class ApproverPickerTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        Cache::flush();
        app(CurrentCompany::class)->set($this->companyId);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    private function makeUser(?int $companyId, bool $platformAdmin = false): int
    {
        return (int) DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'picker-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => $companyId,
            'is_platform_admin' => $platformAdmin,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<array{id:int,name:string}> */
    private function pick(): array
    {
        $method = new ReflectionMethod(ApprovalController::class, 'selectableUsers');
        $method->setAccessible(true);

        return $method->invoke(app(ApprovalController::class), $this->companyId);
    }

    private function ids(array $list): array
    {
        return array_map(fn ($r) => (int) $r['id'], $list);
    }

    public function test_a_platform_admin_can_name_themselves_as_an_approver(): void
    {
        $adminId = $this->makeUser(null, platformAdmin: true);
        $this->actingAs(User::withoutGlobalScopes()->findOrFail($adminId));

        $this->assertContains($adminId, $this->ids($this->pick()),
            'The signed-in platform admin is missing from the approver picker.');
    }

    public function test_other_platform_admins_are_not_listed_in_this_tenants_picker(): void
    {
        // The boundary. Fixing the above by listing every company-less user would leak the vendor's
        // staff directory into every customer's dropdown.
        $otherAdminId = $this->makeUser(null, platformAdmin: true);
        $viewerId = $this->makeUser(null, platformAdmin: true);

        $this->actingAs(User::withoutGlobalScopes()->findOrFail($viewerId));

        $ids = $this->ids($this->pick());

        $this->assertContains($viewerId, $ids);
        $this->assertNotContains($otherAdminId, $ids);
    }

    public function test_the_tenants_own_users_are_still_listed(): void
    {
        // The fix must widen the list, not replace it.
        $memberId = $this->makeUser($this->companyId);
        $this->actingAs(User::withoutGlobalScopes()->findOrFail($memberId));

        $this->assertContains($memberId, $this->ids($this->pick()));
    }

    public function test_another_companys_users_are_never_listed(): void
    {
        $other = (int) DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Picker Fixture Co',
            'slug' => 'picker-'.Str::random(6), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $theirsId = $this->makeUser($other);
        $mineId = $this->makeUser($this->companyId);

        $this->actingAs(User::withoutGlobalScopes()->findOrFail($mineId));

        $this->assertNotContains($theirsId, $this->ids($this->pick()));
    }

    public function test_the_picker_shows_names_rather_than_email_addresses(): void
    {
        /*
         * There is no `users.name` column on this platform, so a picker that forgets to join
         * hr_people renders a column of addresses — and choosing between eleven
         * name.surname@company.co.za strings is choosing badly.
         */
        $userId = $this->makeUser($this->companyId);

        DB::table('hr_people')->insert([
            'company_id' => $this->companyId, 'user_id' => $userId,
            'first_name' => 'Thandiwe', 'surname' => 'Mokoena',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::withoutGlobalScopes()->findOrFail($userId));

        $row = array_values(array_filter($this->pick(), fn ($r) => (int) $r['id'] === $userId));

        $this->assertNotEmpty($row);
        $this->assertSame('Thandiwe Mokoena', $row[0]['name']);
    }
}
