<?php

namespace Tests\Feature\Agri;

use App\Shared\Insights\ReportSchedule;
use App\Shared\Insights\ScheduledReports;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Mail\Events\MessageSent;

/**
 * Insights per menu category: Farm Operations' Insights page lists the farm's reports and analytics
 * (only those the person may open), and a farm report can be emailed on a schedule — run as the
 * person who set it up, so it holds only what they may see.
 */
class CategoryInsightsTest extends AgriTestCase
{
    private function inCategory($user): void
    {
        $cat = DB::table('security_categories')->where('name', 'Farm Operations')->value('id');
        DB::table('security_category_access')->updateOrInsert(['category_id' => $cat, 'user_id' => $user->id],
            ['access_level' => 5, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_category_page_lists_what_the_person_may_open_and_a_farm_report_is_sent_on_schedule(): void
    {
        $farm = $this->farm('NORTH');
        $this->member($this->manager, $farm);
        $this->inCategory($this->manager);
        $this->inCategory($this->worker);

        $page = $this->actingAs($this->manager)->get('/insights/farm-operations')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Shared/CategoryInsights')->where('category.name', 'Farm Operations'))->viewData('page')['props'];
        $paths = collect($page['groups'])->flatten(1)->pluck('path');
        $this->assertContains('/agri/reports', $paths->all());
        $this->assertContains('/agri/insights', $paths->all());
        $this->assertNotContains('/agri/work-orders', $paths->all(), 'Working screens are not insights.');
        $this->assertContains('agri:yield', array_column($page['catalogue'], 'key'));
        $this->assertContains('agri', collect($page['bi']['datasets'])->pluck('module')->all());
        // A worker sees no farm reports.
        $worker = $this->actingAs($this->worker)->get('/insights/farm-operations')->assertOk()->viewData('page')['props'];
        $this->assertNotContains('/agri/reports', collect($worker['groups'])->flatten(1)->pluck('path')->all());
        $this->actingAs($this->outsider)->get('/insights/farm-operations')->assertForbidden();

        // A weekly schedule; a report the person may not run, and a bad address, are refused.
        $body = ['report_key' => 'agri:yield', 'title' => 'Weekly yield', 'params' => ['farm' => $farm->uuid], 'period' => 'last_7_days',
            'format' => 'xlsx', 'frequency' => 'weekly', 'weekday' => 1, 'send_at' => '07:00', 'recipients' => ['owner@boplaas.test']];
        $this->actingAs($this->worker)->post('/insights/farm-operations/schedules', $body)->assertSessionHasErrors('report_key');
        $this->actingAs($this->manager)->post('/insights/farm-operations/schedules', ['recipients' => ['not-an-email']] + $body)->assertSessionHasErrors();
        $this->actingAs($this->manager)->post('/insights/farm-operations/schedules', $body)->assertSessionHasNoErrors();
        $s = ReportSchedule::withoutGlobalScopes()->sole();
        $this->assertTrue($s->next_run_at->isFuture());
        $this->assertSame(1, $s->next_run_at->setTimezone('Africa/Johannesburg')->dayOfWeekIso);

        // Due: it is built as the manager, attached as Excel, and sent; the next run moves on a week.
        $sent = [];
        Event::listen(MessageSent::class, function (MessageSent $e) use (&$sent) { $sent[] = $e->message; });
        $s->forceFill(['next_run_at' => now()->subMinute()])->save();
        $this->assertSame(1, app(ScheduledReports::class)->runDue());
        $this->assertCount(1, $sent);
        $this->assertSame('owner@boplaas.test', $sent[0]->getTo()[0]->getAddress());
        $this->assertStringEndsWith('.xlsx', $sent[0]->getAttachments()[0]->getFilename());
        $s->refresh();
        $this->assertSame('sent', $s->last_status);
        // On to the following Monday (how many days away depends on today), never the run just made.
        $this->assertTrue($s->next_run_at->isFuture());
        $this->assertSame(1, $s->next_run_at->setTimezone('Africa/Johannesburg')->dayOfWeekIso);

        // Someone else cannot change it; pausing it stops it.
        $this->actingAs($this->worker)->delete("/insights/farm-operations/schedules/{$s->uuid}")->assertForbidden();
        $this->actingAs($this->manager)->patch("/insights/farm-operations/schedules/{$s->uuid}", ['active' => false] + $body)->assertSessionHasNoErrors();
        $s->forceFill(['next_run_at' => now()->subMinute()])->save();
        $this->assertSame(0, app(ScheduledReports::class)->runDue());
    }

    public function test_the_farm_has_an_insights_ribbon_and_its_business_screens_are_under_operations(): void
    {
        $hubs = \App\Modules\Agri\Support\AgriHubs::hubs();
        $this->assertArrayNotHasKey('business', $hubs);
        $this->assertContains('agri/finance', array_column($hubs['operations']['cards'], 'path'));
        $this->assertContains('insights/farm-operations', array_column($hubs['insights']['cards'], 'path'));
        $this->assertLessThanOrEqual(5, count($hubs) + count(\App\Modules\Agri\Support\AgriHubs::direct()));
        $this->actingAs($this->manager)->get('/agri/insights-hub')->assertOk();
    }
}
