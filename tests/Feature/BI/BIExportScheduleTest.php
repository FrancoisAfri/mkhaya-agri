<?php

namespace Tests\Feature\BI;

use App\Core\User\Models\User;
use App\Modules\BI\Mail\BIDashboardMail;
use App\Modules\BI\Services\BIScheduler;
use App\Modules\BI\Support\BIAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Workflow\WorkflowTestCase;

/** CSV/Excel export of visuals and dashboards, and the email schedules that send them. */
class BIExportScheduleTest extends WorkflowTestCase
{
    private User $viewer;
    private User $designer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->biUser('viewer', 1);
        $this->designer = $this->biUser('designer', 5);
        DB::table('company_modules')->updateOrInsert(['company_id' => $this->company->id, 'module_code' => BIAccess::MODULE],
            ['source' => 'addon', 'price_monthly' => 0, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('module_active_' . BIAccess::MODULE);
    }

    private function biUser(string $name, int $level): User
    {
        $u = $this->user($name, []);
        $id = DB::table('security_modules')->where('code_name', BIAccess::MODULE)->value('id');
        DB::table('security_module_access')->updateOrInsert(['module_id' => $id, 'user_id' => $u->id], ['active' => 1, 'access_level' => $level]);

        return $u;
    }

    private function visualUrl(string $format): string
    {
        $w = ['type' => 'column_clustered', 'title' => 'Cases by status', 'config' => ['dataset' => 'wf_instances']];

        return '/bi/export/visual?format=' . $format . '&widget=' . urlencode(json_encode($w)) . '&filters=' . urlencode('{}');
    }

    public function test_one_visual_exports_to_csv_and_excel(): void
    {
        $csv = $this->actingAs($this->viewer)->get($this->visualUrl('csv'))->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv->getContent());

        $xlsx = $this->actingAs($this->viewer)->get($this->visualUrl('xlsx'))->assertOk();
        $this->assertStringContainsString('Cases-by-status', $xlsx->headers->get('Content-Disposition'));
    }

    public function test_a_template_exports_as_a_workbook_and_locked_visuals_say_why(): void
    {
        // farm_overview mixes finance (not subscribed here) with other modules: it must still export.
        $r = $this->actingAs($this->viewer)->get('/bi/export/t:farm_overview')->assertOk();
        $this->assertStringContainsString('.xlsx', $r->headers->get('Content-Disposition'));

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($r->getFile()->getPathname());
        $this->assertGreaterThan(1, $book->getSheetCount());
        $this->assertSame('Not available: outside your subscription', $book->getSheet(0)->getCell('A2')->getValue());
    }

    public function test_someone_elses_private_dashboard_cannot_be_exported(): void
    {
        $this->actingAs($this->designer)->post('/bi', ['name' => 'Mine', 'template' => 'farm_overview']);
        $uuid = DB::table('bi_dashboards')->where('company_id', $this->company->id)->value('uuid');

        $this->actingAs($this->designer)->get("/bi/export/{$uuid}")->assertOk();
        $this->actingAs($this->viewer)->get("/bi/export/{$uuid}")->assertNotFound();
    }

    public function test_only_designers_schedule_and_the_email_is_built_as_the_owner(): void
    {
        Mail::fake();
        $form = ['dashboard' => 't:farm_overview', 'name' => 'Monday numbers', 'format' => 'xlsx', 'period' => 'last_month', 'frequency' => 'weekly',
            'weekday' => 1, 'send_time' => '07:00', 'recipients' => ['ceo@example.com', 'cfo@example.com']];

        $this->actingAs($this->viewer)->post('/bi/schedules', $form)->assertForbidden();
        $this->actingAs($this->designer)->post('/bi/schedules', ['recipients' => ['not-an-email']] + $form)->assertSessionHasErrors('recipients.0');
        $this->actingAs($this->designer)->post('/bi/schedules', $form)->assertSessionHas('success');

        $s = DB::table('bi_schedules')->where('company_id', $this->company->id)->first();
        $this->assertSame($this->designer->id, $s->user_id);

        $this->actingAs($this->designer)->post("/bi/schedules/{$s->id}/send")->assertSessionHas('success');
        Mail::assertSent(BIDashboardMail::class, fn ($m) => $m->hasTo('ceo@example.com') && $m->hasTo('cfo@example.com')
            && str_ends_with($m->file['name'], '.xlsx') && str_contains($m->link, '/bi/t/farm_overview'));
        $this->assertSame('sent', DB::table('bi_schedules')->where('id', $s->id)->value('last_status'));

        // The viewer may not run or delete it.
        $this->actingAs($this->viewer)->post("/bi/schedules/{$s->id}/send")->assertForbidden();
        $this->actingAs($this->viewer)->delete("/bi/schedules/{$s->id}")->assertForbidden();
    }

    public function test_a_schedule_fails_and_says_why_when_bi_is_switched_off(): void
    {
        Mail::fake();
        $this->actingAs($this->designer)->post('/bi/schedules', ['dashboard' => 't:farm_overview', 'name' => 'X', 'weekday' => null, 'month_day' => null, 'format' => 'csv', 'period' => 'mtd',
            'frequency' => 'daily', 'send_time' => '07:00', 'recipients' => ['a@example.com']]);
        $s = DB::table('bi_schedules')->where('company_id', $this->company->id)->first();
        \App\Modules\BI\Support\BISettings::set(false); // sold as an add-on, then taken away
        DB::table('company_modules')->where('company_id', $this->company->id)->where('module_code', BIAccess::MODULE)->delete();
        Cache::forget('module_active_' . BIAccess::MODULE);

        $r = app(BIScheduler::class)->send($s);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('no longer switched on', $r['error']);
        Mail::assertNothingSent();
    }

    public function test_schedules_are_due_only_at_their_time_and_day(): void
    {
        $monday7 = CarbonImmutable::parse('2026-11-09 07:00', 'Africa/Johannesburg');
        $weekly = (object) ['is_active' => true, 'send_time' => '07:00', 'last_sent_at' => null, 'frequency' => 'weekly', 'weekday' => 1, 'month_day' => null];

        $this->assertTrue(BIScheduler::due($weekly, $monday7));
        $this->assertFalse(BIScheduler::due($weekly, $monday7->addDay()));
        $this->assertFalse(BIScheduler::due($weekly, $monday7->addMinute()));
        $this->assertFalse(BIScheduler::due((object) (['last_sent_at' => $monday7->subMinutes(5)] + (array) $weekly), $monday7));
        $this->assertTrue(BIScheduler::due((object) (['frequency' => 'monthly', 'month_day' => 31] + (array) $weekly), CarbonImmutable::parse('2026-11-30 07:00')));
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-31', 'override' => true], BIScheduler::filters('last_month', $monday7));
        $this->assertSame([], BIScheduler::filters('own', $monday7));
    }
}
