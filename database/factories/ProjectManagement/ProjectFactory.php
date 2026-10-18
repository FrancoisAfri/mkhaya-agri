<?php

namespace Database\Factories\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\WorkCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The first Project Management factory.
 *
 * Until now every PM test built its fixtures by hand — ProjectManagementTestCase::makeProject()
 * creates a fresh WorkCalendar on every call, and the larger suites carry thirty-line private
 * helpers each. That is tolerable for a handful of tests and expensive once a feature needs a
 * project *plus* a fee schedule, several accounts and a chain of prior claims.
 *
 * Money is deliberately not set by default. A project with a budget is a specific case worth
 * stating in the test that relies on it (PM-ADR-006 keeps money as a decimal string; a factory
 * quietly inventing amounts is how a rounding assertion ends up passing for the wrong reason).
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'code'        => 'PRJ-' . fake()->unique()->numberBetween(1000, 9999),
            'name'        => fake()->streetName() . ' works',
            'currency'    => 'ZAR',
            'timezone'    => 'Africa/Johannesburg',
            // Shared per test run rather than one per project: calendars are configuration, and a
            // calendar per project made large fixtures noticeably slower for no coverage.
            'calendar_id' => fn () => WorkCalendar::query()->value('id')
                ?? WorkCalendarFactory::new()->create()->id,
            'status'      => 'active',
            'stage'       => 'execution',
        ];
    }

    public function withBudget(string $amount, string $currency = 'ZAR'): static
    {
        return $this->state(fn () => [
            'budget_amount' => $amount,
            'currency'      => $currency,
            'is_billable'   => true,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => 'draft', 'stage' => 'initiation']);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => 'completed', 'stage' => 'closing']);
    }
}
