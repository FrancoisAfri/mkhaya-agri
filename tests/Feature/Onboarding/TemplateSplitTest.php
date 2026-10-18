<?php

namespace Tests\Feature\Onboarding;

use App\Core\User\Models\User;
use App\Modules\HR\Onboarding\Models\OnboardingTemplate;
use App\Modules\HR\Onboarding\Services\TemplateSplitService;
use App\Modules\HR\PreBoarding\Models\HrPerson;
use Database\Seeders\OnboardingTemplateCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pre-boarding plans built from the onboarding catalogue.
 *
 * Pre-boarding had no templates, so every plan was assembled by hand. A second catalogue would mean
 * two lists that drift — and the drift shows up as a contract chased twice or not at all. Instead
 * the existing one is split at day zero: a task with a negative offset is by definition work that
 * has to happen before the person arrives.
 */
class TemplateSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OnboardingTemplateCatalogueSeeder::class);
    }

    private function template(string $key = 'standard'): OnboardingTemplate
    {
        return OnboardingTemplate::where('catalogue_key', $key)->firstOrFail();
    }

    public function test_only_tasks_before_day_zero_become_pre_boarding(): void
    {
        $template = $this->template();
        $tasks = app(TemplateSplitService::class)
            ->preBoardingTasks($template, Carbon::parse('2026-09-01'));

        $this->assertNotEmpty($tasks, 'The standard template has real pre-arrival work.');

        $onOrAfterStart = $template->templateTasks->filter(fn ($t) => $t->day_offset >= 0)->count();

        $this->assertGreaterThan(0, $onOrAfterStart, 'The template should also have day-zero-onwards work.');
        $this->assertCount(
            $template->templateTasks->filter(fn ($t) => $t->day_offset < 0)->count(),
            $tasks,
            'Exactly the negative-offset tasks, no more and no less.'
        );
    }

    /** Dated from the person's first day, not from when somebody built the plan. */
    public function test_due_dates_are_relative_to_the_start_date(): void
    {
        $tasks = app(TemplateSplitService::class)
            ->preBoardingTasks($this->template(), Carbon::parse('2026-09-01'));

        foreach ($tasks as $task) {
            $this->assertLessThan(
                '2026-09-01',
                $task['due_date'],
                'Pre-boarding work is due before the start date by definition.'
            );
        }
    }

    /** The two sides use different task-type enums, so the vocabulary has to be mapped. */
    public function test_task_types_are_mapped_into_the_pre_boarding_vocabulary(): void
    {
        $valid = array_column(\App\Modules\HR\PreBoarding\Enums\PreBoardingTaskType::cases(), 'value');

        $tasks = app(TemplateSplitService::class)
            ->preBoardingTasks($this->template(), Carbon::parse('2026-09-01'));

        foreach ($tasks as $task) {
            $this->assertContains($task['type'], $valid, "'{$task['type']}' is not a pre-boarding task type.");
        }
    }

    public function test_it_creates_a_plan_with_its_tasks(): void
    {
        $person = HrPerson::factory()->create();
        $creator = User::factory()->create();

        $plan = app(TemplateSplitService::class)->createPreBoardingPlan(
            $this->template(),
            $person->id,
            Carbon::parse('2026-09-01'),
            $creator->id,
        );

        $this->assertSame($person->id, $plan->hr_person_id);
        $this->assertSame('2026-09-01', $plan->due_date->toDateString(), 'The plan is due when the person arrives.');
        $this->assertGreaterThan(0, $plan->tasks()->count());
    }

    /** An industry template carries its own pre-arrival work, not just the shared base. */
    public function test_an_industry_template_brings_its_own_pre_arrival_tasks(): void
    {
        $service = app(TemplateSplitService::class);
        $start = Carbon::parse('2026-09-01');

        $standard = count($service->preBoardingTasks($this->template('standard'), $start));
        $mining = count($service->preBoardingTasks($this->template('mining'), $start));

        $this->assertGreaterThan(
            $standard,
            $mining,
            'Mining adds medical surveillance before day one; that is the point of the industry templates.'
        );
    }
}
