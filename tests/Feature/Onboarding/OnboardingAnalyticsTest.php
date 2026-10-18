<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Models\OnboardingPlan;
use App\Modules\HR\Onboarding\Models\OnboardingTask;
use App\Modules\HR\Onboarding\Services\OnboardingAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActivatesBoardingModule;
use Tests\TestCase;

/**
 * Onboarding analytics — the numbers offboarding has always had and onboarding never did.
 *
 * The assertions are about the measures being *honest* rather than merely present: a plan finished
 * before its start date is a data artefact and must not be averaged in as a fast onboarding, and
 * overdue work is bucketed rather than averaged so one task stuck for a month is still visible.
 */
class OnboardingAnalyticsTest extends TestCase
{
    use RefreshDatabase, ActivatesBoardingModule;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateBoardingModule();
        $this->hr = User::factory()->create();
        $this->grantBoardingTier($this->hr);
        $this->hr = $this->hr->fresh();
    }

    public function test_the_page_renders(): void
    {
        $this->actingAs($this->hr)
            ->get(route('onboarding.analytics'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Modules/Onboarding/Analytics')->has('analytics.kpis'));
    }

    public function test_overdue_tasks_are_counted_and_completed_ones_are_not(): void
    {
        $plan = OnboardingPlan::factory()->create(['status' => 'active']);

        OnboardingTask::factory()->create([
            'onboarding_plan_id' => $plan->id, 'status' => 'pending',
            'due_date' => now()->subDays(3)->toDateString(),
        ]);
        OnboardingTask::factory()->create([
            'onboarding_plan_id' => $plan->id, 'status' => 'completed',
            'due_date' => now()->subDays(30)->toDateString(),
        ]);

        $kpis = app(OnboardingAnalyticsService::class)->summary()['kpis'];

        $this->assertSame(1, $kpis['overdue_tasks'], 'A completed task is not overdue however old it is.');
    }

    /** Bucketed, so one long-stuck task is not averaged away by thirty recent ones. */
    public function test_overdue_work_is_bucketed_by_age(): void
    {
        $plan = OnboardingPlan::factory()->create(['status' => 'active']);

        foreach ([2, 15, 60] as $daysLate) {
            OnboardingTask::factory()->create([
                'onboarding_plan_id' => $plan->id, 'status' => 'pending',
                'due_date' => now()->subDays($daysLate)->toDateString(),
            ]);
        }

        $ageing = app(OnboardingAnalyticsService::class)->summary()['task_ageing'];

        $this->assertSame(1, $ageing['1_7']);
        $this->assertSame(1, $ageing['8_30']);
        $this->assertSame(1, $ageing['over_30']);
    }

    /** A plan completed before its start date is an artefact, not a fast onboarding. */
    public function test_a_plan_completed_before_it_started_is_not_averaged_in(): void
    {
        OnboardingPlan::factory()->create([
            'status' => 'completed',
            'start_date' => now()->toDateString(),
            'completed_at' => now()->subDays(10),
        ]);

        $average = app(OnboardingAnalyticsService::class)->summary()['kpis']['average_days_to_complete'];

        $this->assertNull($average, 'The only sample was negative and should have been discarded.');
    }

    public function test_the_export_streams_a_row_per_plan(): void
    {
        OnboardingPlan::factory()->count(3)->create(['status' => 'active']);

        $response = $this->actingAs($this->hr)->get(route('onboarding.analytics.export'));

        $response->assertOk();

        $csv = $response->streamedContent();
        $lines = array_filter(explode("\n", trim($csv)));

        $this->assertCount(4, $lines, 'A header and one row per plan.');
        $this->assertStringContainsString('Completion %', $lines[0]);
    }
}
