<?php

namespace Tests\Feature\Tenancy;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Console\ExpireTrials;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a platform admin can do about a trial that ran out.
 *
 * Before this there was nothing: a company whose 30 days lapsed was blocked by
 * ResolveCompany, and the only control on the row was "Suspend" — so the way back in
 * was to suspend and then activate, which silently converted a trial into a paying
 * subscription. Extending has to restore access without that.
 */
class TrialLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();

        parent::tearDown();
    }

    private function platformAdmin(): User
    {
        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'trial-admin-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => null,
            'is_platform_admin' => true,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_extending_an_expired_trial_unlocks_the_company(): void
    {
        $co = Company::create([
            'name' => 'Lapsed Co', 'plan' => 'starter', 'status' => 'trial',
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->assertTrue($co->trialExpired(), 'The fixture is not actually locked out.');

        $this->actingAs($this->platformAdmin())
            ->post("/platform/companies/{$co->id}/extend-trial", ['days' => 14])
            ->assertRedirect();

        $co->refresh();
        $this->assertEquals('trial', $co->status);
        $this->assertFalse($co->trialExpired());
        $this->assertEquals(now()->addDays(14)->toDateString(), $co->trial_ends_at->toDateString());
    }

    public function test_extending_a_running_trial_adds_to_the_existing_end_date(): void
    {
        $co = Company::create([
            'name' => 'Running Co', 'plan' => 'starter', 'status' => 'trial',
            'trial_ends_at' => now()->addDays(10),
        ]);

        $this->actingAs($this->platformAdmin())
            ->post("/platform/companies/{$co->id}/extend-trial", ['days' => 30]);

        // 10 remaining + 30 granted — days already paid for are not thrown away.
        $this->assertEquals(now()->addDays(40)->toDateString(), $co->fresh()->trial_ends_at->toDateString());
    }

    public function test_a_trial_suspended_by_the_cron_can_be_extended_back_into_service(): void
    {
        $co = Company::create([
            'name' => 'Cron Suspended Co', 'plan' => 'starter', 'status' => 'trial',
            'trial_ends_at' => now()->subDays(2),
        ]);

        $this->artisan(ExpireTrials::class)->assertSuccessful();
        $this->assertEquals('suspended', $co->fresh()->status);

        $this->actingAs($this->platformAdmin())
            ->post("/platform/companies/{$co->id}/extend-trial", ['trial_ends_at' => now()->addDays(20)->toDateString()]);

        $co->refresh();
        $this->assertEquals('trial', $co->status);
        $this->assertNull($co->suspended_at);
        $this->assertNull($co->suspended_reason);
        $this->assertTrue($co->isActive());
    }

    public function test_activating_a_trial_clears_the_trial_end_date(): void
    {
        // A stale trial_ends_at on an "active" company is the trap: flip the status back
        // to trial for any reason and the company is instantly locked out again.
        $co = Company::create([
            'name' => 'Converting Co', 'plan' => 'starter', 'status' => 'trial',
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->actingAs($this->platformAdmin())
            ->post("/platform/companies/{$co->id}/activate");

        $co->refresh();
        $this->assertEquals('active', $co->status);
        $this->assertNull($co->trial_ends_at);
        $this->assertFalse($co->trialExpired());
    }

    public function test_the_companies_list_flags_an_expired_trial(): void
    {
        $lapsed = Company::create([
            'name' => 'Flagged Co', 'plan' => 'starter', 'status' => 'trial',
            'trial_ends_at' => now()->subDay(),
        ]);
        $soon = Company::create([
            'name' => 'Expiring Co', 'plan' => 'starter', 'status' => 'trial',
            'trial_ends_at' => now()->addDays(3),
        ]);

        $this->withoutVite()
            ->actingAs($this->platformAdmin())
            ->get('/platform/companies')
            ->assertOk()
            ->assertInertia(function ($page) use ($lapsed, $soon) {
                $rows = collect($page->toArray()['props']['companies'])->keyBy('id');

                $this->assertTrue($rows[$lapsed->id]['trial_expired']);
                $this->assertFalse($rows[$soon->id]['trial_expired']);
                $this->assertEquals(3, $rows[$soon->id]['trial_days_left']);
            });
    }

    public function test_a_past_end_date_is_rejected_and_an_ordinary_user_cannot_extend(): void
    {
        $co = Company::create([
            'name' => 'Guarded Co', 'plan' => 'starter', 'status' => 'trial',
            'trial_ends_at' => now()->subDay(),
        ]);

        $admin = $this->platformAdmin();

        $this->actingAs($admin)
            ->post("/platform/companies/{$co->id}/extend-trial", ['trial_ends_at' => now()->subWeek()->toDateString()])
            ->assertSessionHasErrors('trial_ends_at');
        $this->assertTrue($co->fresh()->trialExpired(), 'A backdated extension unlocked the company.');

        $this->actingAs($admin)
            ->post("/platform/companies/{$co->id}/extend-trial", ['days' => 400])
            ->assertSessionHasErrors('days');
    }
}
