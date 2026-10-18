<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\HR\Onboarding\Notifications\OnboardingReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\MakesCompanies;
use Tests\TestCase;

/**
 * The reminders that make onboarding happen.
 *
 * The module had no scheduled work whatsoever: plans were created with due dates and nothing ever
 * looked at them again. Onboarding is deadline-driven and largely performed by people for whom it
 * is not the main job, so an unchased plan is discovered at the 90-day review.
 */
class OnboardingRemindersTest extends TestCase
{
    use RefreshDatabase, MakesCompanies;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        // The fan-out sets app.company_id on the connection and the value outlives the test, so a
        // later test creates a company with a new id while the session still points at the old one
        // — and RLS then hides its own fixtures. Cheap to reset, invisible when it goes wrong.
        DB::statement('RESET app.company_id');

        // RunsPerTenant deliberately does nothing when there are no companies — the fan-out has no
        // tenant to enter, and doing the work untenanted would write rows belonging to nobody. So
        // the reminders need a tenant to exist before they can be about anything.
        $this->companyId = $this->makeCompany('Reminder Tenant');
    }

    private function planWithTask(array $taskAttributes = [], array $planAttributes = []): OnboardingTask
    {
        $plan = OnboardingPlan::factory()->create(array_merge([
            'status' => 'active',
            'company_id' => $this->companyId,
        ], $planAttributes));

        return OnboardingTask::factory()->create(array_merge([
            'onboarding_plan_id' => $plan->id,
            'status' => 'pending',
            'company_id' => $this->companyId,
        ], $taskAttributes));
    }

    public function test_an_overdue_task_reminds_the_person_it_is_assigned_to(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['company_id' => $this->companyId]);

        $this->planWithTask([
            'assigned_to' => $owner->id,
            'due_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->artisan('boarding:reminders')->assertSuccessful();

        Notification::assertSentTo(
            $owner,
            fn (OnboardingReminderNotification $n) => $n->kind === 'overdue_tasks' && $n->count === 1
        );
    }

    /**
     * Many overdue items produce one message, not many.
     *
     * Eleven separate emails teach a person to filter the sender, which costs every future reminder
     * as well as this one.
     */
    public function test_several_overdue_tasks_produce_a_single_grouped_reminder(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['company_id' => $this->companyId]);

        foreach (range(1, 4) as $i) {
            $this->planWithTask([
                'assigned_to' => $owner->id,
                'due_date' => now()->subDays($i)->toDateString(),
            ]);
        }

        $this->artisan('boarding:reminders')->assertSuccessful();

        Notification::assertSentToTimes($owner, OnboardingReminderNotification::class, 1);
        Notification::assertSentTo($owner, fn (OnboardingReminderNotification $n) => $n->count === 4);
    }

    public function test_a_task_that_is_not_yet_due_is_left_alone(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['company_id' => $this->companyId]);

        $this->planWithTask([
            'assigned_to' => $owner->id,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->artisan('boarding:reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** A task already done is not chased, however far past its date it is. */
    public function test_a_completed_task_is_not_chased(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['company_id' => $this->companyId]);

        $this->planWithTask([
            'assigned_to' => $owner->id,
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => 'completed',
        ]);

        $this->artisan('boarding:reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** Work on a cancelled or completed plan is not somebody's problem any more. */
    public function test_tasks_on_an_inactive_plan_are_not_chased(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['company_id' => $this->companyId]);

        $this->planWithTask(
            ['assigned_to' => $owner->id, 'due_date' => now()->subDays(4)->toDateString()],
            ['status' => 'cancelled']
        );

        $this->artisan('boarding:reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_an_imminent_start_date_reminds_whoever_created_the_plan(): void
    {
        Notification::fake();

        $creator = User::factory()->create(['company_id' => $this->companyId]);

        OnboardingPlan::factory()->create([
            'status' => 'active',
            'created_by' => $creator->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'company_id' => $this->companyId,
        ]);

        $this->artisan('boarding:reminders')->assertSuccessful();

        Notification::assertSentTo(
            $creator,
            fn (OnboardingReminderNotification $n) => $n->kind === 'upcoming_starts'
        );
    }

    /** --days controls the horizon, so a start beyond it is not yet anybody's problem. */
    public function test_a_start_beyond_the_horizon_is_not_reminded(): void
    {
        Notification::fake();

        OnboardingPlan::factory()->create([
            'status' => 'active',
            'start_date' => now()->addDays(30)->toDateString(),
            'company_id' => $this->companyId,
        ]);

        $this->artisan('boarding:reminders', ['--days' => 7])->assertSuccessful();

        Notification::assertNothingSent();
    }

    /**
     * The command enters a tenant before it reads anything.
     *
     * With no company on the session the RLS policy `session IS NULL OR company_id = session`
     * matches every row on the platform, so a cron that forgets to fan out does not fail — it
     * silently emails one tenant's managers about another tenant's new hires.
     */
    public function test_the_command_fans_out_per_tenant(): void
    {
        $source = file_get_contents(
            app_path('Modules/HR/Onboarding/Console/SendOnboardingRemindersCommand.php')
        );

        $this->assertStringContainsString('RunsPerTenant', $source);
        $this->assertStringContainsString('eachTenant(', $source);
    }
}
