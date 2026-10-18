<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Services\InspectionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Performing a scheduled inspection has to move its schedule on.
 *
 * `recordScheduleCompletion()` stamps `last_completed` and advances `next_due`, and nothing ever
 * called it — because nothing could. An inspection carried no reference to the schedule it was
 * performed against, so the link did not exist. The controller had been passing `schedule_id` all
 * along and the column was not there to receive it.
 *
 * The consequence was not a tidy unused method. A fixed-frequency schedule's `next_due` never
 * moved, so once the first one passed the inspection read as overdue permanently, however many
 * times it was actually carried out. SHEQ-INS-4 puts overdue inspections on the dashboard as a
 * leading indicator and the hourly sweep escalates them, so both reported work that had been done
 * — which is how people learn to ignore a figure.
 */
class ScheduleAdvancesTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $userId;

    private int $templateId;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'sch-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'S'.Str::random(5), 'name' => 'Workshop', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->templateId = DB::table('sheq_inspection_templates')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'T'.Str::random(5), 'title' => 'Monthly workshop check',
            'version' => 1, 'status' => 'PUBLISHED', 'scoring_mode' => 'PERCENTAGE',
            'pass_threshold' => 80,
            'schema' => json_encode(['sections' => [[
                'title' => 'General',
                'questions' => [['id' => 'q1', 'text' => 'Housekeeping acceptable?',
                    'type' => 'PASS_FAIL', 'weight' => 1, 'critical' => false]],
            ]]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function schedule(int $frequencyDays, string $nextDue): int
    {
        return DB::table('sheq_inspection_schedules')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'template_id' => $this->templateId, 'site_id' => $this->siteId,
            'trigger_type' => 'FIXED', 'frequency_days' => $frequencyDays,
            'next_due' => $nextDue, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function perform(?int $scheduleId): array
    {
        return app(InspectionService::class)->complete($this->companyId, array_filter([
            'template_id' => $this->templateId,
            'schedule_id' => $scheduleId,
            'site_id' => $this->siteId,
            'started_at' => Carbon::now()->toDateTimeString(),
            'completed_at' => Carbon::now()->toDateTimeString(),
        ]), ['q1' => ['answer' => 'PASS']], $this->userId);
    }

    public function test_performing_a_scheduled_inspection_moves_the_schedule_on(): void
    {
        $overdueSince = Carbon::today()->subDays(5)->toDateString();
        $scheduleId = $this->schedule(frequencyDays: 30, nextDue: $overdueSince);

        $this->perform($scheduleId);

        $schedule = DB::table('sheq_inspection_schedules')->where('id', $scheduleId)
            ->first(['next_due', 'last_completed']);

        $this->assertSame(Carbon::today()->toDateString(),
            Carbon::parse($schedule->last_completed)->toDateString());

        $this->assertSame(Carbon::today()->addDays(30)->toDateString(),
            Carbon::parse($schedule->next_due)->toDateString(),
            'The schedule never advanced, so the inspection read as overdue for ever.');
    }

    public function test_the_inspection_remembers_which_schedule_it_satisfied(): void
    {
        $scheduleId = $this->schedule(frequencyDays: 7, nextDue: Carbon::today()->toDateString());

        $result = $this->perform($scheduleId);

        $this->assertSame($scheduleId, (int) DB::table('sheq_inspections')
            ->where('id', $result['id'])->value('schedule_id'));
    }

    public function test_an_ad_hoc_inspection_belongs_to_no_schedule_and_that_is_allowed(): void
    {
        // Somebody who sees a problem must be able to inspect without one being scheduled first.
        $result = $this->perform(null);

        $this->assertNull(DB::table('sheq_inspections')
            ->where('id', $result['id'])->value('schedule_id'));
    }

    public function test_an_overdue_schedule_stops_being_overdue_once_it_is_done(): void
    {
        $scheduleId = $this->schedule(frequencyDays: 30,
            nextDue: Carbon::today()->subDays(10)->toDateString());

        $overdue = fn () => DB::table('sheq_inspection_schedules')
            ->where('company_id', $this->companyId)->where('is_active', true)
            ->whereNotNull('next_due')->where('next_due', '<', Carbon::today()->toDateString())
            ->where('id', $scheduleId)->exists();

        $this->assertTrue($overdue(), 'The fixture is not actually overdue.');

        $this->perform($scheduleId);

        // This is the figure the dashboard shows and the hourly sweep escalates.
        $this->assertFalse($overdue());
    }
}
