<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Ai\SafetyAnalytics;
use App\Modules\Operations\Sheq\Bridges\ModuleBridge;
use App\Modules\Operations\Sheq\Services\ContractorPerformanceService;
use App\Modules\Operations\Sheq\Services\ToolboxTalkService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Safety analytics, toolbox talks and contractor performance.
 *
 * The analytics tests are mostly about *restraint* — that the module declines to call three
 * incidents a trend, and declines to compare sites it has no exposure hours for. An analytics
 * feature that always finds something is worse than none, because the first false alarm teaches
 * everybody to ignore the next one.
 */
class AnalyticsAndTalksTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'A'.Str::random(6), 'name' => 'Analytics site', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'an-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function incident(array $overrides = []): int
    {
        return DB::table('sheq_incidents')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(6), 'site_id' => $this->siteId,
            'occurred_at' => now()->subDays(10), 'reported_at' => now()->subDays(10),
            'incident_type' => 'INJURY', 'description' => 'Operator struck by grinder disc.',
            'actual_severity' => 2, 'potential_severity' => 3, 'status' => 'CLOSED',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    // ── Restraint ─────────────────────────────────────────────────────────────────────────────

    public function test_three_incidents_are_not_called_a_trend(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->incident();
        }

        // Two becoming three is not a 50% increase worth anybody's attention, and an analytics
        // feature that always finds something teaches people to ignore it.
        $this->assertSame([], app(SafetyAnalytics::class)->accidentTrends($this->companyId));
    }

    public function test_a_real_increase_is_reported_with_its_counts(): void
    {
        // Six this quarter against one in the quarter before.
        for ($i = 0; $i < 6; $i++) {
            $this->incident(['occurred_at' => now()->subDays(20)]);
        }

        $this->incident(['occurred_at' => now()->subDays(120)]);

        $trends = app(SafetyAnalytics::class)->accidentTrends($this->companyId);

        $this->assertNotEmpty($trends);
        $this->assertSame(6, $trends[0]['sample_size']);
        // Counts, not just a percentage — "up 500%" alone reads as a catastrophe.
        $this->assertStringContainsString('6', $trends[0]['finding']);
        $this->assertNotEmpty($trends[0]['evidence']);
    }

    public function test_a_site_with_no_exposure_hours_is_reported_as_a_count_not_a_rate(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->incident();
        }

        $hotspots = app(SafetyAnalytics::class)->highRiskAreas($this->companyId);

        $this->assertNotEmpty($hotspots);
        // A rate computed against no exposure is a number that cannot be compared to anything,
        // so the finding says so instead of inventing one.
        $this->assertStringContainsString('No exposure hours', $hotspots[0]['finding']);
    }

    public function test_repeated_incidents_are_surfaced_as_a_recurrence(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->incident(['description' => 'Operator hand caught in the guillotine guard.']);
        }

        $found = app(SafetyAnalytics::class)->recurringIncidents($this->companyId);

        $this->assertNotEmpty($found, 'Four near-identical incidents must be flagged.');
        $this->assertSame('RECURRENCE', $found[0]['insight_type']);
        // The point of the finding: the earlier corrective actions did not work.
        $this->assertStringContainsString('recurrence', strtolower($found[0]['finding']));
    }

    public function test_an_insight_can_be_dismissed_and_stops_resurfacing(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->incident(['description' => 'Slip on wet floor near the wash bay.']);
        }

        $service = app(SafetyAnalytics::class);
        $found = $service->recurringIncidents($this->companyId);

        $this->assertNotEmpty($found);
        $this->assertNotEmpty($service->open($this->companyId));

        $service->dismiss($this->companyId, $found[0]['uuid'], $this->userId, 'Already addressed.');

        $titles = array_column($service->open($this->companyId), 'title');
        $this->assertNotContains($found[0]['title'], $titles);
    }

    public function test_rerunning_does_not_stack_duplicate_insights(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->incident(['description' => 'Forklift struck racking in aisle four.']);
        }

        $service = app(SafetyAnalytics::class);
        $service->recurringIncidents($this->companyId);
        $service->recurringIncidents($this->companyId);
        $service->recurringIncidents($this->companyId);

        // A nightly job that appended would bury the new findings under copies of the old ones.
        $this->assertCount(1, $service->open($this->companyId));
    }

    public function test_the_compliance_forecast_counts_what_lapses(): void
    {
        DB::table('sheq_equipment')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'asset_tag' => 'FC-'.Str::random(5), 'name' => 'Extinguisher',
            'equipment_type' => 'FIRE_EXTINGUISHER', 'status' => 'IN_SERVICE',
            'certificate_expires_on' => Carbon::today()->addDays(20)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $forecast = app(SafetyAnalytics::class)->complianceForecast($this->companyId);

        $this->assertNotEmpty($forecast);
        $this->assertStringContainsString('equipment certificate', $forecast[0]['finding']);
    }

    // ── Toolbox talks ─────────────────────────────────────────────────────────────────────────

    public function test_a_talk_without_attendance_is_refused(): void
    {
        // The whole evidentiary value of a toolbox talk is who was there.
        $this->expectExceptionMessageMatches('/Record who attended/');

        app(ToolboxTalkService::class)->record($this->companyId, [
            'topic' => 'Hand safety', 'attendees' => [],
        ], $this->userId);
    }

    public function test_a_concern_raised_becomes_an_action(): void
    {
        $result = app(ToolboxTalkService::class)->record($this->companyId, [
            'topic' => 'Machine guarding', 'site_id' => $this->siteId,
            'attendees' => [['hr_person_id' => null, 'name' => 'Crew member']],
            'concerns_raised' => 'The guard on the number two press has been loose for weeks.',
        ], $this->userId);

        // Somebody says it, everyone nods, and the talk ends — that is the failure this prevents.
        $this->assertTrue($result['action_raised']);

        $talk = DB::table('sheq_toolbox_talks')->where('uuid', $result['uuid'])
            ->first(['action_id']);

        $action = DB::table('sheq_actions')->where('id', $talk->action_id)
            ->first(['source_type', 'source_id']);

        $this->assertSame('TOOLBOX_TALK', $action->source_type);
        $this->assertSame($result['id'], (int) $action->source_id);
    }

    public function test_coverage_counts_distinct_people_not_attendances(): void
    {
        $service = app(ToolboxTalkService::class);

        // One person in two talks is one person reached, not two.
        foreach (['Hand safety', 'Housekeeping'] as $topic) {
            $service->record($this->companyId, [
                'topic' => $topic, 'attendees' => [['hr_person_id' => 4242, 'name' => 'Same Person']],
            ], $this->userId);
        }

        $coverage = $service->coverage($this->companyId);

        $this->assertSame(2, $coverage['talks_held']);
        $this->assertSame(1, $coverage['people_reached']);
    }

    // ── Contractor performance ────────────────────────────────────────────────────────────────

    public function test_a_contractor_with_nobody_on_site_scores_nothing_rather_than_full_marks(): void
    {
        $contractorId = DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => 'Absent Co', 'access_status' => 'ALLOWED', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(ContractorPerformanceService::class)
            ->computeMonth($this->companyId, $contractorId);

        // An absent contractor is not a safe one, they are simply absent.
        $this->assertNull($result['score']);
        $this->assertNull($result['rating']);
    }

    public function test_incidents_reduce_the_score_and_high_potential_ones_reduce_it_more(): void
    {
        $service = app(ContractorPerformanceService::class);

        $quiet = $this->contractorWithWorkers('Quiet Co', 10);
        $risky = $this->contractorWithWorkers('Risky Co', 10);

        $this->incident(['contractor_id' => $risky, 'potential_severity' => 5,
            'occurred_at' => now()]);

        $quietScore = $service->computeMonth($this->companyId, $quiet)['score'];
        $riskyScore = $service->computeMonth($this->companyId, $risky)['score'];

        $this->assertGreaterThan($riskyScore, $quietScore,
            'A high-potential incident is the strongest available signal about what comes next.');
    }

    public function test_the_leaderboard_puts_the_worst_first(): void
    {
        $service = app(ContractorPerformanceService::class);

        $good = $this->contractorWithWorkers('Good Co', 20);
        $bad = $this->contractorWithWorkers('Bad Co', 20);

        for ($i = 0; $i < 3; $i++) {
            $this->incident(['contractor_id' => $bad, 'potential_severity' => 5,
                'occurred_at' => now()]);
        }

        $service->computeMonth($this->companyId, $good);
        $service->computeMonth($this->companyId, $bad);

        $board = $service->leaderboard($this->companyId);
        $names = array_column($board, 'name');

        // Worst first is the useful direction — nobody scrolls to find the problem.
        $this->assertLessThan(array_search('Good Co', $names, true),
            array_search('Bad Co', $names, true));
    }

    private function contractorWithWorkers(string $name, int $workers): int
    {
        $id = DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'name' => $name, 'access_status' => 'ALLOWED', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        for ($i = 0; $i < $workers; $i++) {
            DB::table('sheq_contractor_workers')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'contractor_id' => $id, 'name' => "Worker {$i}",
                'id_number_hash' => hash('sha256', Str::random(20)),
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    // ── Module bridges ────────────────────────────────────────────────────────────────────────

    public function test_every_bridge_degrades_rather_than_throwing_when_a_module_is_absent(): void
    {
        $bridge = app(ModuleBridge::class);

        // A customer who bought SHEQ without Fleet must see "no vehicles", not a stack trace.
        $this->assertIsArray($bridge->vehicles($this->companyId));
        $this->assertIsArray($bridge->assets($this->companyId));
        $this->assertIsArray($bridge->projects($this->companyId));
        $this->assertIsArray($bridge->qualityComplaints($this->companyId));
        $this->assertIsArray($bridge->expiringDriverLicences($this->companyId));
    }

    public function test_the_bridge_status_reports_every_link(): void
    {
        $status = app(ModuleBridge::class)->status();

        $modules = array_column($status, 'module');

        foreach (['HR', 'Fleet', 'Asset Management', 'Help Desk', 'CRM',
            'Project Management', 'Procurement', 'Learning Management'] as $expected) {
            $this->assertContains($expected, $modules);
        }
    }

    public function test_a_help_desk_ticket_becomes_a_hazard(): void
    {
        $result = app(ModuleBridge::class)->hazardFromTicket($this->companyId, [
            'description' => 'Handrail missing on the gantry stairs.',
            'site_id' => $this->siteId, 'category' => 'STRUCTURAL',
        ], $this->userId);

        // The real path: reported to the service desk because that is the number on the wall,
        // then sits in an IT queue until it hurts somebody.
        $this->assertNotNull($result);
        $this->assertTrue(DB::table('sheq_hazards')->where('id', $result['hazard_id'])->exists());
    }
}
