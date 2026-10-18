<?php

namespace Tests\Feature\Tenancy;

use App\Core\User\Models\User;
use App\Modules\Tenancy\Console\ExpireTrials;
use App\Modules\Tenancy\Console\TrialReminders;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Notifications\TrialEndingSoon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Platform admins are warned while a trial is still running, not after the client is locked out.
 *
 * The ladder has to fire once per threshold: a daily cron that re-sent the same warning every
 * morning would be ignored inside a week, which is the same as having no warning at all.
 */
class TrialReminderTest extends TestCase
{
    use DatabaseTransactions;

    private function platformAdmin(): User
    {
        $id = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'email' => 'trial-watch-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'),
            'company_id' => null,
            'is_platform_admin' => true,
            'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    private function trial(string $name, int $daysOut): Company
    {
        return Company::create([
            'name' => $name, 'plan' => 'starter', 'status' => 'trial',
            'trial_ends_at' => now()->addDays($daysOut),
        ]);
    }

    public function test_a_trial_inside_a_threshold_alerts_once_and_then_stays_quiet(): void
    {
        $this->platformAdmin();
        $co = $this->trial('Six Days Left Co', 6);
        Notification::fake();

        $this->artisan(TrialReminders::class)->assertSuccessful();
        Notification::assertSentTimes(TrialEndingSoon::class, 1);
        $this->assertEquals(7, $co->fresh()->trial_alert_stage, 'The 7-day rung should be the one that fired.');

        // Same day, next morning, whatever — the same rung must not fire twice.
        $this->artisan(TrialReminders::class)->assertSuccessful();
        Notification::assertSentTimes(TrialEndingSoon::class, 1);
    }

    public function test_each_tighter_threshold_fires_again_as_the_trial_closes(): void
    {
        $this->platformAdmin();
        $co = $this->trial('Closing Co', 6);
        Notification::fake();

        $this->artisan(TrialReminders::class);                       // 7-day rung
        $co->forceFill(['trial_ends_at' => now()->addDays(2)])->save();
        $this->artisan(TrialReminders::class);                       // 3-day rung
        $co->forceFill(['trial_ends_at' => now()])->save();
        $this->artisan(TrialReminders::class);                       // last day

        Notification::assertSentTimes(TrialEndingSoon::class, 3);
        $this->assertEquals(0, $co->fresh()->trial_alert_stage);
    }

    public function test_a_trial_further_out_than_the_widest_threshold_is_left_alone(): void
    {
        $this->platformAdmin();
        $this->trial('Plenty Of Time Co', 40);
        Notification::fake();

        $this->artisan(TrialReminders::class)->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_extending_a_trial_re_arms_the_warnings(): void
    {
        $this->platformAdmin();
        $co = $this->trial('Extended Co', 2);
        Notification::fake();

        $this->artisan(TrialReminders::class);
        Notification::assertSentTimes(TrialEndingSoon::class, 1);

        // Extended by a month: the recorded stage now describes an end date that no longer
        // exists, so the client must be warned again when the new date approaches.
        $co->forceFill(['trial_ends_at' => now()->addDays(32)])->save();
        $this->artisan(TrialReminders::class);
        Notification::assertSentTimes(TrialEndingSoon::class, 1);   // too far out to warn yet

        $co->forceFill(['trial_ends_at' => now()->addDays(5)])->save();
        $this->artisan(TrialReminders::class);
        Notification::assertSentTimes(TrialEndingSoon::class, 2);
    }

    public function test_a_lapsed_trial_reports_once_even_after_the_cron_suspends_it(): void
    {
        $this->platformAdmin();
        $co = $this->trial('Lapsed Co', -2);
        $this->artisan(ExpireTrials::class);
        $this->assertEquals('suspended', $co->fresh()->status);

        Notification::fake();
        $this->artisan(TrialReminders::class)->assertSuccessful();

        Notification::assertSentTimes(TrialEndingSoon::class, 1);
        $this->assertEquals(-1, $co->fresh()->trial_alert_stage);

        // Tomorrow's run must not nag about the same lapsed trial.
        $this->artisan(TrialReminders::class);
        Notification::assertSentTimes(TrialEndingSoon::class, 1);
    }

    public function test_a_company_suspended_for_non_payment_is_not_treated_as_a_trial(): void
    {
        $this->platformAdmin();
        Company::create([
            'name' => 'Unpaid Co', 'plan' => 'starter', 'status' => 'suspended',
            'trial_ends_at' => now()->subDays(3),
            'suspended_reason' => 'Invoice overdue',
        ]);
        Notification::fake();

        $this->artisan(TrialReminders::class)->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_the_alert_carries_the_days_remaining_and_a_dry_run_sends_nothing(): void
    {
        $admin = $this->platformAdmin();
        $co = $this->trial('Payload Co', 3);
        Notification::fake();

        $this->artisan('tenancy:trial-reminders --dry-run')->assertSuccessful();
        Notification::assertNothingSent();
        $this->assertNull($co->fresh()->trial_alert_stage, 'A dry run must not record that it alerted.');

        $this->artisan(TrialReminders::class);
        Notification::assertSentTo($admin, TrialEndingSoon::class, function (TrialEndingSoon $n) use ($co) {
            $payload = $n->toArray($n->company);

            $this->assertEquals(3, $n->daysLeft);
            $this->assertEquals($co->id, $payload['company_id']);
            $this->assertEquals('high', $payload['severity']);
            $this->assertStringContainsString('Payload Co', $payload['title']);

            return true;
        });
    }
}
