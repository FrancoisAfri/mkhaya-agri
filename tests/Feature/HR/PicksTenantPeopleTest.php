<?php

namespace Tests\Feature\HR;

use App\Modules\HR\Support\PicksTenantPeople;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The source every people dropdown now reads from.
 *
 * It replaces thirteen hand-rolled lists in Performance Appraisal which were wrong in two
 * opposite directions: the ones reading `hr_people` offered only employees who happened to
 * have a personnel record, so a tenant with thirty-five accounts and four records was given
 * four people and read the screen as empty; the ones reading `users` raw had no scope at all
 * and `users` carries no row-level security to catch it, so they offered every tenant's staff
 * to every other tenant.
 *
 * Both halves are asserted here, because fixing either one alone reintroduces the other.
 */
class PicksTenantPeopleTest extends TestCase
{
    use DatabaseTransactions;
    use PicksTenantPeople;

    private function company(string $name): int
    {
        return DB::table('companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'slug' => Str::slug($name).'-'.Str::random(5),
            'name' => $name, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function account(?int $companyId, string $email, ?int $status = 1): int
    {
        return DB::table('users')->insertGetId([
            'email' => $email, 'password' => bcrypt('x'), 'company_id' => $companyId,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function personnelRecord(int $companyId, int $userId, string $first, string $last, ?int $status = 1): void
    {
        DB::table('hr_people')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'user_id' => $userId,
            'first_name' => $first, 'surname' => $last, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The list as a controller would get it, for a tenant whose user is signed in. */
    private function offeredTo(int $companyId, int $userId): array
    {
        app(CurrentCompany::class)->set($companyId);

        $request = Request::create('/');
        $request->setUserResolver(fn () => \App\Core\User\Models\User::withoutGlobalScopes()->find($userId));

        try {
            // The trait resolves the company once per instance, so each call starts fresh.
            $picker = new class
            {
                use PicksTenantPeople {
                    tenantPeople as public;
                }
            };

            return $picker->tenantPeople($request);
        } finally {
            app(CurrentCompany::class)->set(null);
        }
    }

    /**
     * The same list with no active company set.
     *
     * `CompanyScope` goes inactive the moment `CurrentCompany` is unset, which is the state a
     * platform administrator is in, and is also when `ResolvesTenant` falls back to the acting
     * user's own `company_id`. In that state the trait's explicit clause is the only thing
     * scoping the query, and `users` carries no row-level security to catch it if it is wrong.
     */
    private function offeredWithNoActiveCompany(int $userId): array
    {
        app(CurrentCompany::class)->set(null);

        $request = Request::create('/');
        $request->setUserResolver(fn () => \App\Core\User\Models\User::withoutGlobalScopes()->find($userId));

        $picker = new class
        {
            use PicksTenantPeople {
                tenantPeople as public;
            }
        };

        return $picker->tenantPeople($request);
    }

    public function test_with_no_active_company_the_acting_users_own_company_still_scopes_the_list(): void
    {
        config(['edition.edition' => 'saas']);

        $mine = $this->company('Mine, No Active Company');
        $theirs = $this->company('Theirs, No Active Company');

        $myUser = $this->account($mine, 'mine-'.Str::random(6).'@example.test');
        $this->personnelRecord($mine, $myUser, 'My', 'Person');

        $theirUser = $this->account($theirs, 'theirs-'.Str::random(6).'@example.test');
        $this->personnelRecord($theirs, $theirUser, 'Their', 'Person');

        $ids = array_column($this->offeredWithNoActiveCompany($myUser), 'id');

        $this->assertContains($myUser, $ids);
        $this->assertNotContains(
            $theirUser,
            $ids,
            'with no active company nothing else scopes this query, so another tenant leaked',
        );
    }

    public function test_an_account_with_no_personnel_record_is_still_offered(): void
    {
        $company = $this->company('Needs A Dropdown');
        $owner = $this->account($company, 'owner-'.Str::random(6).'@example.test');
        $this->personnelRecord($company, $owner, 'Primary', 'User');

        // Created through manage-users, which does not create a personnel record.
        $fresh = $this->account($company, 'fresh-'.Str::random(6).'@example.test');

        $offered = $this->offeredTo($company, $owner);
        $ids = array_column($offered, 'id');

        $this->assertContains($owner, $ids, 'the primary user must always be offered');
        $this->assertContains($fresh, $ids, 'an account without a personnel record was dropped');

        $byId = collect($offered)->keyBy('id');
        $this->assertSame('Primary User', $byId[$owner]['name']);
        $this->assertTrue($byId[$owner]['has_record']);

        // Named by email until somebody fills the record in, rather than appearing blank.
        $this->assertStringContainsString('@example.test', $byId[$fresh]['name']);
        $this->assertFalse($byId[$fresh]['has_record']);
    }

    public function test_one_tenant_is_never_offered_another_tenants_people(): void
    {
        config(['edition.edition' => 'saas']);

        $mine = $this->company('Mine');
        $theirs = $this->company('Theirs');

        $myUser = $this->account($mine, 'mine-'.Str::random(6).'@example.test');
        $this->personnelRecord($mine, $myUser, 'My', 'Person');

        $theirUser = $this->account($theirs, 'theirs-'.Str::random(6).'@example.test');
        $this->personnelRecord($theirs, $theirUser, 'Their', 'Person');

        $ids = array_column($this->offeredTo($mine, $myUser), 'id');

        $this->assertContains($myUser, $ids);
        $this->assertNotContains($theirUser, $ids, 'another tenant’s person was offered');
    }

    public function test_a_personnel_record_with_no_status_is_treated_as_active(): void
    {
        $company = $this->company('Status Was Never Set');
        $owner = $this->account($company, 'owner-'.Str::random(6).'@example.test');
        $this->personnelRecord($company, $owner, 'Primary', 'User');

        // Most paths that create an employee never mention `status`, so it came back NULL.
        // Filtering on `status = 1` hid those people from every dropdown in the system.
        $quiet = $this->account($company, 'quiet-'.Str::random(6).'@example.test');
        $this->personnelRecord($company, $quiet, 'No', 'Status', null);

        $this->assertContains($quiet, array_column($this->offeredTo($company, $owner), 'id'));
    }

    public function test_a_deactivated_account_is_not_offered(): void
    {
        $company = $this->company('Has A Leaver');
        $owner = $this->account($company, 'owner-'.Str::random(6).'@example.test');
        $this->personnelRecord($company, $owner, 'Primary', 'User');

        $gone = $this->account($company, 'gone-'.Str::random(6).'@example.test', 0);
        $this->personnelRecord($company, $gone, 'Switched', 'Off');

        $ids = array_column($this->offeredTo($company, $owner), 'id');

        $this->assertContains($owner, $ids);
        $this->assertNotContains($gone, $ids, 'a deactivated account should not be selectable');
    }

    public function test_a_deactivated_employee_is_not_offered_even_with_a_live_account(): void
    {
        $company = $this->company('Has A Deactivated Employee');
        $owner = $this->account($company, 'owner-'.Str::random(6).'@example.test');
        $this->personnelRecord($company, $owner, 'Primary', 'User');

        $left = $this->account($company, 'left-'.Str::random(6).'@example.test');
        $this->personnelRecord($company, $left, 'Has', 'Left', 0);

        $this->assertNotContains($left, array_column($this->offeredTo($company, $owner), 'id'));
    }

    public function test_the_list_is_sorted_by_name_so_a_picker_is_navigable(): void
    {
        $company = $this->company('Sorted');
        $owner = $this->account($company, 'owner-'.Str::random(6).'@example.test');
        $this->personnelRecord($company, $owner, 'Mandla', 'Ndlovu');

        foreach ([['Anele', 'Botha'], ['zanele', 'Adams'], ['Bongani', 'Khumalo']] as [$first, $last]) {
            $this->personnelRecord($company, $this->account($company, strtolower($first).'-'.Str::random(6).'@example.test'), $first, $last);
        }

        $names = array_column($this->offeredTo($company, $owner), 'name');
        $sorted = $names;
        usort($sorted, fn ($a, $b) => strnatcasecmp($a, $b));

        $this->assertSame($sorted, $names, 'the list came back unsorted');
    }

    public function test_an_options_list_carries_only_what_a_picker_needs(): void
    {
        $company = $this->company('Options');
        $owner = $this->account($company, 'owner-'.Str::random(6).'@example.test');
        $this->personnelRecord($company, $owner, 'Primary', 'User');

        app(CurrentCompany::class)->set($company);
        $request = Request::create('/');
        $request->setUserResolver(fn () => \App\Core\User\Models\User::withoutGlobalScopes()->find($owner));

        try {
            $picker = new class
            {
                use PicksTenantPeople {
                    tenantPeopleOptions as public;
                }
            };
            $options = $picker->tenantPeopleOptions($request);
        } finally {
            app(CurrentCompany::class)->set(null);
        }

        $this->assertNotEmpty($options);
        $this->assertSame(['id', 'name'], array_keys($options[0]));
    }
}
