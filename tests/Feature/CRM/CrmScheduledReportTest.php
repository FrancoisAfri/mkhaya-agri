<?php

namespace Tests\Feature\CRM;

use App\Modules\CRM\Jobs\GenerateCrmReportJob;
use App\Modules\CRM\Models\CrmScheduledReport;
use App\Modules\CRM\Services\CrmReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CrmScheduledReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_compute_next_run_daily_is_in_the_future(): void
    {
        $r = new CrmScheduledReport(['cadence' => 'daily', 'run_time' => '07:00']);
        $this->assertTrue($r->computeNextRun()->isFuture());
    }

    public function test_compute_next_run_weekly_lands_on_target_day(): void
    {
        $r = new CrmScheduledReport(['cadence' => 'weekly', 'run_time' => '07:00', 'day_of_week' => 3]); // Wednesday
        $next = $r->computeNextRun();
        $this->assertSame(3, $next->isoWeekday());
        $this->assertTrue($next->isFuture());
    }

    public function test_recipient_list_parsing(): void
    {
        $r = new CrmScheduledReport(['recipients' => ' a@x.test , b@y.test ,, c@z.test ']);
        $this->assertSame(['a@x.test', 'b@y.test', 'c@z.test'], $r->recipientList());
    }

    public function test_due_reports_dispatch_the_queued_job_and_roll_forward(): void
    {
        Queue::fake();

        $report = CrmScheduledReport::create([
            'name' => 'Weekly funnel', 'report_key' => 'funnel', 'cadence' => 'daily', 'run_time' => '07:00',
            'recipients' => 'sales@x.test', 'active' => true, 'next_run_at' => now()->subMinute(),
        ]);

        $this->artisan('crm:run-scheduled-reports')->assertSuccessful();

        Queue::assertPushed(GenerateCrmReportJob::class);
        // next_run rolled into the future, last_run stamped.
        $this->assertTrue($report->fresh()->next_run_at->isFuture());
        $this->assertNotNull($report->fresh()->last_run_at);
    }

    public function test_inactive_or_not_due_reports_are_skipped(): void
    {
        Queue::fake();

        CrmScheduledReport::create([
            'name' => 'Paused', 'report_key' => 'funnel', 'cadence' => 'daily', 'run_time' => '07:00',
            'recipients' => 'x@x.test', 'active' => false, 'next_run_at' => now()->subMinute(),
        ]);
        CrmScheduledReport::create([
            'name' => 'Future', 'report_key' => 'funnel', 'cadence' => 'daily', 'run_time' => '07:00',
            'recipients' => 'x@x.test', 'active' => true, 'next_run_at' => now()->addDay(),
        ]);

        $this->artisan('crm:run-scheduled-reports')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    public function test_report_service_exposes_keys_and_datasets(): void
    {
        $keys = CrmReportService::reportKeys();
        $this->assertArrayHasKey('open_deals', $keys);
        $this->assertArrayHasKey('leads', $keys);

        [$headers] = (new CrmReportService())->dataset('leads');
        $this->assertcontains('email', $headers);

        $this->assertCount(12, (new CrmReportService())->dealsCreatedTrend());
    }
}
