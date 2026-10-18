<?php

namespace Tests\Feature\Core;

use App\Core\User\Models\User;
use App\Modules\HR\Models\HRPerson;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every account in Manage Users can be opened, employee or not.
 *
 * Identity lives in `hr_people`, and a profile was addressed by that record's uuid and nothing
 * else. An account created outside the employee flow — a client, a subcontractor, a portal login —
 * has no such row, so it had no profile URL in existence. It appeared in the list like everybody
 * else and the button answered "no profile for this user", because the front end had nothing to
 * put in the URL but the literal "undefined".
 *
 * Both uuids resolve now. The employee sections come back empty for somebody who is not an
 * employee, which is the truth rather than a gap.
 */
class ProfileWithoutAPersonnelRecordTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $admin = User::withoutGlobalScopes()->whereHas('person')->first()
            ?? User::factory()->create();

        $admin->forceFill(['status' => 1])->saveQuietly();

        return $admin;
    }

    private function accountWithoutAPersonnelRecord(): User
    {
        $user = User::withoutGlobalScopes()->create([
            'uuid'       => (string) Str::uuid(),
            'email'      => 'client-'.Str::lower(Str::random(8)).'@mkhaya.test',
            'password'   => Hash::make(Str::random(16)),
            'company_id' => DB::table('companies')->min('id'),
            'status'     => 1,
        ]);

        DB::table('hr_people')->where('user_id', $user->id)->delete();

        return $user;
    }

    /** A minimal personnel record, for the half of this that covers employees. */
    private function personnelRecordFor(User $user): HRPerson
    {
        $id = DB::table('hr_people')->insertGetId([
            'uuid'              => (string) Str::uuid(),
            'user_id'           => $user->id,
            'company_id'        => $user->company_id,
            'first_name'        => 'Thandi',
            'surname'           => 'Nkosi',
            'email'             => $user->email,
            'second_manager_id' => 0,
            'eligible_to_drive' => 0,
            'eti_opt_in'        => 0,
            'pay_frequency'     => 'monthly',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        return HRPerson::withoutGlobalScopes()->findOrFail($id);
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin())->withoutMiddleware([
            \App\Http\Middleware\EnforceCrudPermissions::class,
        ]);
    }

    public function test_an_account_with_no_personnel_record_has_a_profile(): void
    {
        $client = $this->accountWithoutAPersonnelRecord();

        $props = $this->asAdmin()
            ->get(route('profile.user', ['uuid' => $client->uuid]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame($client->email, $props['user']['email'] ?? null);
        $this->assertArrayHasKey('person', $props['user']);
        $this->assertNull($props['user']['person'], 'A non-employee was given a personnel record.');
    }

    /** The old address still works, so nothing that already links by personnel uuid breaks. */
    public function test_a_personnel_record_still_addresses_the_same_profile(): void
    {
        // Made rather than found: a seeded test database may hold no personnel records at all,
        // and skipping would quietly stop covering the address that every existing link uses.
        $person = HRPerson::withoutGlobalScopes()->whereNotNull('user_id')->first()
            ?? $this->personnelRecordFor($this->accountWithoutAPersonnelRecord());

        $props = $this->asAdmin()
            ->get(route('profile.user', ['uuid' => $person->uuid]))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame((int) $person->user_id, (int) ($props['user']['id'] ?? 0));
        $this->assertNotNull($props['user']['person'] ?? null);
    }

    /** A uuid belonging to neither is still a 404, not a 500 on the Postgres cast. */
    public function test_an_unknown_uuid_is_not_found_rather_than_an_error(): void
    {
        $this->asAdmin()
            ->get(route('profile.user', ['uuid' => (string) Str::uuid()]))
            ->assertNotFound();
    }

    public function test_a_malformed_uuid_is_still_refused(): void
    {
        $this->asAdmin()
            ->get('/user-profile/undefined')
            ->assertNotFound();
    }
}
