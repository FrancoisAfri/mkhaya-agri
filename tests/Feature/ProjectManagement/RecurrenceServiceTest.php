<?php

namespace Tests\Feature\ProjectManagement;

use App\Modules\Operations\ProjectManagement\Models\Project;
use App\Modules\Operations\ProjectManagement\Models\Task;
use App\Modules\Operations\ProjectManagement\Models\TaskRecurrence;
use App\Modules\Operations\ProjectManagement\Services\RecurrenceService;
use App\Modules\Operations\ProjectManagement\Services\TaskService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Recurring tasks (PM-1122).
 *
 * The expander itself is proved in Tests\Unit\ProjectManagement\RruleTest; this is about what the
 * module does with those dates — that occurrences are real tasks, that running the generator twice
 * does not double the site's work, and that stopping a series does not erase what already happened.
 */
class RecurrenceServiceTest extends ProjectManagementTestCase
{
    private Project $project;

    private RecurrenceService $recurrences;

    private TaskService $tasks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject(['timezone' => 'Africa/Johannesburg']);
        $this->recurrences = app(RecurrenceService::class);
        $this->tasks = app(TaskService::class);
    }

    private function template(array $attributes = []): Task
    {
        return $this->tasks->create($this->project, array_merge([
            'name'             => 'Inspect the scaffold',
            'duration_minutes' => 60,
        ], $attributes));
    }

    private function at(string $when): CarbonImmutable
    {
        return CarbonImmutable::parse($when, 'Africa/Johannesburg');
    }

    public function test_a_weekly_rule_generates_its_occurrences_to_the_horizon(): void
    {
        $template = $this->template();

        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=WEEKLY;BYDAY=MO', [
            'starts_at'    => '2026-03-02 07:00',
            'horizon_days' => 21,
        ]);

        $created = $this->recurrences->generate($recurrence, $this->at('2026-03-02 05:00'));

        // Mondays 2, 9 and 16 March. The 23rd is outside by two hours: the horizon is counted from
        // the moment of the run, not to the end of that day, so a 07:00 occurrence on the last day
        // of a window opened at 05:00 belongs to the next run.
        $this->assertSame(
            ['2026-03-02', '2026-03-09', '2026-03-16'],
            array_map(fn (Task $t) => $t->occurrence_date->toDateString(), $created)
        );
    }

    public function test_an_occurrence_is_an_ordinary_task(): void
    {
        $template = $this->template(['description' => 'Check ties and boards.', 'priority' => 'high']);
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY;COUNT=1', [
            'starts_at' => '2026-03-02 07:00',
        ]);

        $task = $this->recurrences->generate($recurrence, $this->at('2026-03-01'))[0];

        $this->assertSame('Inspect the scaffold', $task->name);
        $this->assertSame('Check ties and boards.', $task->description);
        $this->assertSame('high', $task->priority);
        $this->assertSame('not_started', $task->status);
        $this->assertSame($recurrence->id, $task->occurrence_of_id);
        // Its own code, not the template's: it is a distinct piece of work with its own history.
        $this->assertNotSame($template->code, $task->code);
        $this->assertSame('07:00', $task->planned_start->format('H:i'));
        $this->assertSame('08:00', $task->planned_end->format('H:i'));
    }

    /**
     * The guarantee that makes a nightly command safe: a second run in the same window inserts
     * nothing. Without it an overlapping cron, or a manual re-run after a failure, doubles every
     * inspection on the list.
     */
    public function test_running_the_generator_twice_creates_nothing_the_second_time(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY', [
            'starts_at' => '2026-03-02 07:00', 'horizon_days' => 5,
        ]);

        $first = $this->recurrences->generate($recurrence, $this->at('2026-03-02 05:00'));
        $second = $this->recurrences->generate($recurrence->fresh(), $this->at('2026-03-02 05:00'));

        $this->assertNotEmpty($first);
        $this->assertSame([], $second);
        $this->assertSame(count($first), Task::where('occurrence_of_id', $recurrence->id)->count());
    }

    public function test_a_later_run_extends_the_series_without_repeating_it(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY', [
            'starts_at' => '2026-03-02 07:00', 'horizon_days' => 3,
        ]);

        $this->recurrences->generate($recurrence, $this->at('2026-03-02 05:00'));
        $later = $this->recurrences->generate($recurrence->fresh(), $this->at('2026-03-05 05:00'));

        // The first run reached 4 March; the second picks up from there rather than from its own
        // window, so nothing is repeated and nothing is skipped.
        $this->assertSame(
            ['2026-03-05', '2026-03-06', '2026-03-07'],
            array_map(fn (Task $t) => $t->occurrence_date->toDateString(), $later)
        );
    }

    public function test_the_template_is_kept_out_of_the_task_list(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY;COUNT=2', [
            'starts_at' => '2026-03-02 07:00',
        ]);

        $this->recurrences->generate($recurrence, $this->at('2026-03-01'));

        $this->assertTrue($template->fresh()->is_recurrence_template);
        // Three tasks exist; two are work.
        $this->assertSame(3, Task::count());
        $this->assertSame(2, Task::real()->count());
    }

    public function test_count_is_honoured_across_separate_runs(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY;COUNT=3', [
            'starts_at' => '2026-03-02 07:00', 'horizon_days' => 2,
        ]);

        $this->recurrences->generate($recurrence, $this->at('2026-03-02 05:00'));
        $this->recurrences->generate($recurrence->fresh(), $this->at('2026-03-20 05:00'));

        $this->assertSame(3, Task::where('occurrence_of_id', $recurrence->id)->count());
    }

    public function test_an_end_date_stops_generation(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY', [
            'starts_at' => '2026-03-02 07:00', 'ends_at' => '2026-03-04 23:59', 'horizon_days' => 30,
        ]);

        $created = $this->recurrences->generate($recurrence, $this->at('2026-03-02 05:00'));

        $this->assertCount(3, $created);
    }

    public function test_a_paused_rule_generates_nothing(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY', [
            'starts_at' => '2026-03-02 07:00',
        ]);

        $this->recurrences->pause($recurrence);

        $this->assertSame([], $this->recurrences->generate($recurrence->fresh(), $this->at('2026-03-02 05:00')));
    }

    /**
     * Stopping cancels the future and leaves the past alone. Deleting a fortnight of completed
     * inspections because someone ended the rule would destroy the record of work that was done.
     */
    public function test_stopping_a_series_cancels_only_untouched_future_occurrences(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY', [
            'starts_at' => '2026-03-02 07:00', 'horizon_days' => 10,
        ]);

        $this->travelTo($this->at('2026-03-04 12:00'));
        $this->recurrences->generate($recurrence, $this->at('2026-03-02 05:00'));

        $done = Task::where('occurrence_of_id', $recurrence->id)->orderBy('occurrence_date')->first();
        $this->tasks->update($done, ['status' => 'done']);

        $cancelled = $this->recurrences->stop($recurrence->fresh());

        $this->assertSame('done', $done->fresh()->status);
        $this->assertGreaterThan(0, $cancelled);
        $this->assertSame(0, Task::where('occurrence_of_id', $recurrence->id)
            ->where('occurrence_date', '>', now())
            ->where('status', 'not_started')
            ->count());
    }

    public function test_a_deleted_template_deactivates_the_rule_instead_of_failing_nightly(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY', [
            'starts_at' => '2026-03-02 07:00',
        ]);

        $this->tasks->delete($template);

        $this->assertSame([], $this->recurrences->generate($recurrence->fresh(), $this->at('2026-03-02 05:00')));
        $this->assertFalse($recurrence->fresh()->is_active);
    }

    // ── What it refuses ──────────────────────────────────────────────────────

    public function test_an_unsupported_rule_is_refused_when_it_is_set_not_at_02_00(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BYSETPOS is not supported');

        $this->recurrences->create($this->project, $this->template(), 'FREQ=MONTHLY;BYDAY=FR;BYSETPOS=-1');
    }

    public function test_a_rule_cannot_be_put_on_an_occurrence(): void
    {
        $template = $this->template();
        $recurrence = $this->recurrences->create($this->project, $template, 'FREQ=DAILY;COUNT=1', [
            'starts_at' => '2026-03-02 07:00',
        ]);

        $occurrence = $this->recurrences->generate($recurrence, $this->at('2026-03-01'))[0];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('put the rule on the original');

        $this->recurrences->create($this->project, $occurrence, 'FREQ=WEEKLY');
    }

    public function test_an_unreasonable_horizon_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 1 and ' . TaskRecurrence::MAX_HORIZON_DAYS);

        $this->recurrences->create($this->project, $this->template(), 'FREQ=DAILY', ['horizon_days' => 4000]);
    }
}
