<?php

namespace Tests\Feature\Sheq;

use App\Core\User\Models\User;
use App\Modules\Operations\Sheq\Services\CommandCentreService;
use App\Modules\Operations\Sheq\Services\SiteRiskScoreService;
use App\Modules\Operations\Sheq\Support\ReportProvenance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The live command centre (CMD-1 to CMD-8).
 *
 * The failure this screen is most likely to have is not a wrong number — it is a **stale number
 * presented as a live one**, which is read as the present and acted on as the present. So the
 * tests here are about provenance as much as arithmetic: that the tiles are live, that the
 * indicators carry the projection's own age, that an unrun projection says so instead of showing
 * eleven zeroes that read as a quiet month, and that an exported report carries its filters,
 * period, timestamp and owner.
 *
 * The other thing specific to this screen: the two scores on it run in opposite directions. A
 * contractor on 90 is doing well and a site on 90 is about to hurt somebody, and nothing in the
 * data stops somebody reading one as the other.
 */
class CommandCentreTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private User $user;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;
        $this->user = $this->userWith(['sheq.configure']);
        $this->siteId = $this->site('Tower block');
    }

    private function service(): CommandCentreService
    {
        return app(CommandCentreService::class);
    }

    // ── CMD-1 ─────────────────────────────────────────────────────────────────────────────────

    public function test_all_eleven_things_the_requirement_lists_are_on_the_board(): void
    {
        /*
         * Walked against CMD-1's own words. A tile dropped in a refactor fails here rather than
         * quietly disappearing from a dashboard nobody audits.
         */
        $required = [
            'critical risks' => 'critical_risks',
            'blocked contractors' => 'blocked_contractors',
            'non-compliant workers' => 'non_compliant_workers',
            'active permits' => 'active_permits',
            'overdue CAPA' => 'overdue_capa',
            'statutory deadlines' => 'statutory_deadlines',
            'incidents' => 'incidents',
            'site occupancy' => 'site_occupancy',
            'inspections' => 'inspections',
            'audits' => 'audits',
            'environmental breaches' => 'environmental_breaches',
        ];

        $board = $this->service()->board($this->companyId);
        $keys = array_column($board['tiles'], 'key');

        foreach ($required as $words => $key) {
            $this->assertArrayHasKey($key, CommandCentreService::TILES,
                "CMD-1 lists '{$words}' and the board has no tile for it.");
            $this->assertContains($key, $keys,
                "The {$key} tile is declared and not returned.");
        }

        $this->assertCount(11, CommandCentreService::TILES,
            'CMD-1 lists eleven things. A twelfth tile is a scope change, not a tidy-up.');
    }

    public function test_every_tile_knows_where_it_drills_to(): void
    {
        /*
         * CMD-2's requirement, expressed as a property of CMD-1: a number nobody can get under
         * is a number nobody can act on, and a tile with no destination is exactly that.
         */
        foreach ($this->service()->board($this->companyId)['tiles'] as $tile) {
            $this->assertNotEmpty($tile['drill'],
                "The {$tile['key']} tile has no destination, so the number is a dead end.");
            $this->assertStringStartsWith('/sheq/', $tile['drill']);
            $this->assertNotEmpty($tile['label']);
            $this->assertNotEmpty($tile['as_at'],
                "The {$tile['key']} tile does not say how old it is.");
        }
    }

    public function test_the_live_tiles_are_live(): void
    {
        $this->permit('ACTIVE');
        $this->attendance(3);

        $board = $this->service()->board($this->companyId, $this->siteId);
        $values = array_column($board['tiles'], 'value', 'key');

        $this->assertSame(1, $values['active_permits']);
        $this->assertSame(3, $values['site_occupancy'],
            'Occupancy is not read live, so the one tile whose entire value is being current is '
            .'showing a projection.');

        // One more person walks in. No cron, no recompute.
        $this->attendance(1);

        $this->assertSame(4, array_column(
            $this->service()->board($this->companyId, $this->siteId)['tiles'],
            'value', 'key')['site_occupancy']);
    }

    public function test_a_suspended_permit_still_counts_as_open_work(): void
    {
        $this->permit('SUSPENDED');

        $this->assertSame(1, array_column(
            $this->service()->board($this->companyId, $this->siteId)['tiles'],
            'value', 'key')['active_permits'],
            'A suspended permit is not counted, so the board says no work is open while the '
            .'scaffold and the open trench are still there.');
    }

    public function test_a_statutory_deadline_tile_says_it_is_not_filtered_by_site(): void
    {
        /*
         * Statutory obligations are company-wide and have no site column. Filtering a site view
         * to zero would read as "nothing is due", which is the most dangerous possible way for
         * this particular tile to be wrong.
         */
        $tiles = array_column(
            $this->service()->board($this->companyId, $this->siteId)['tiles'], null, 'key');

        $this->assertTrue($tiles['statutory_deadlines']['company_wide'],
            'Nothing tells the reader the deadline count ignores their site filter.');
        $this->assertFalse($tiles['incidents']['company_wide'],
            'A tile that IS filtered by site claims not to be.');
    }

    public function test_another_sites_events_do_not_appear_on_this_sites_board(): void
    {
        $other = $this->site('Yard');

        $this->permit('ACTIVE', $other);

        $this->assertSame(0, array_column(
            $this->service()->board($this->companyId, $this->siteId)['tiles'],
            'value', 'key')['active_permits'],
            'Another site\'s open permit appears on this site\'s board.');
    }

    // ── CMD-3 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_unrun_projection_says_so_rather_than_showing_zeroes(): void
    {
        /*
         * The most misleading state this screen can be in. Eleven empty indicator tiles read as
         * "a quiet month"; what they actually mean is "nobody has run the cron".
         */
        $indicators = $this->service()->indicators($this->companyId, null,
            Carbon::now()->addYears(5));

        $this->assertSame([], $indicators['leading']);
        $this->assertTrue($indicators['stale']);
        $this->assertNotNull($indicators['why_empty'],
            'An empty dashboard does not explain itself, so it reads as a safe month rather than '
            .'as a projection that has not run.');
        $this->assertStringContainsString('not that nothing happened', $indicators['why_empty']);
    }

    public function test_the_indicators_carry_the_projections_own_age_not_the_request_time(): void
    {
        $this->artisan('sheq:kpis:compute', ['--company' => $this->companyId, '--months' => 1])
            ->assertSuccessful();

        $indicators = $this->service()->indicators($this->companyId);

        $this->assertNotNull($indicators['computed_at'],
            'The dashboard cannot say when the figures were calculated.');
        $this->assertNotNull($indicators['lag_seconds']);
        $this->assertFalse($indicators['stale'],
            'A projection computed seconds ago is reported as stale.');

        /*
         * And the lag has to come from the database clock, not PHP's. This connection's session
         * runs Africa/Johannesburg while the application runs UTC, so a timestamp written by
         * Laravel and compared against PHP's now() is out by the offset — which is how a freshly
         * computed snapshot reads as two hours old.
         */
        $this->assertLessThan(600, $indicators['lag_seconds'],
            'The lag is computed against PHP\'s clock rather than the database\'s, so it is out '
            .'by the session timezone offset.');
    }

    // ── CMD-4, CMD-5 ──────────────────────────────────────────────────────────────────────────

    public function test_every_leading_indicator_the_requirement_lists_is_projected(): void
    {
        $this->artisan('sheq:kpis:compute', ['--company' => $this->companyId, '--months' => 1])
            ->assertSuccessful();

        $leading = $this->service()->indicators($this->companyId)['leading'];

        // CMD-4's eight. Four of them postdate the KPI projection, which is how they came to be
        // missing from it.
        $required = [
            'hazards' => 'hazards_reported',
            'near misses' => 'near_miss_ratio',
            'inspections' => 'inspections_completed',
            'safety observations' => 'observations_recorded',
            'contractor compliance' => 'contractor_compliance_percent',
            'competency expiry' => 'competencies_expiring_90d',
            'CAPA' => 'actions_open',
            'MOC' => 'changes_open',
        ];

        foreach ($required as $words => $key) {
            $this->assertArrayHasKey($key, $leading,
                "CMD-4 lists '{$words}' as a leading indicator and the projection does not "
                .'produce it, so the command centre would have to read the register directly and '
                .'the two screens would disagree.');
        }
    }

    public function test_every_lagging_indicator_the_requirement_lists_is_projected(): void
    {
        // Rates need a denominator. An injury frequency rate with no hours worked is not a rate,
        // so the projection correctly produces none until somebody has captured the exposure.
        $this->exposureHours(180000);

        $this->artisan('sheq:kpis:compute', ['--company' => $this->companyId, '--months' => 1])
            ->assertSuccessful();

        $indicators = $this->service()->indicators($this->companyId);

        // CMD-5's six.
        foreach (['fatalities', 'lost_time_injuries', 'recordable_cases', 'days_lost',
            'cost_minor'] as $key) {
            $this->assertArrayHasKey($key, $indicators['lagging'],
                "CMD-5 lists {$key} and the projection does not produce it.");
        }

        /*
         * CMD-5 asks for injury rates, and they are jurisdictional: LTIFR is per 1,000,000 hours
         * in most of the world while South African mining reports DIFR per 200,000. So the
         * definitions come from the active regulatory pack rather than from code, and a company
         * with no pack activated gets no rates at all — correctly, because inventing a multiplier
         * would misreport one of them by a factor of five.
         *
         * Asserted as "the mechanism is wired", because this database has no activation. That is
         * REG-FIX-2's gap — a fresh deployment needs packs installing by hand — and not a defect
         * in the projection.
         */
        $this->assertArrayHasKey('rates', $indicators,
            'The projection has no rates key at all, so no pack could ever populate it.');

        $activated = DB::table('sheq_regulatory_pack_activations')
            ->where('company_id', $this->companyId)->where('status', 'ACTIVE')->exists();

        if ($activated) {
            $this->assertNotEmpty($indicators['rates'],
                'A pack is active and declares metric definitions, and no rate was projected.');
        }
    }

    public function test_an_implemented_change_awaiting_review_still_counts_as_open(): void
    {
        /*
         * The whole of MOC-5. The window between a change going live and somebody confirming it
         * worked is precisely what this indicator exists to show, and counting IMPLEMENTED as
         * finished would hide every change sitting in it.
         */
        $this->change('IMPLEMENTED');
        $this->change('REVIEWED');

        $this->artisan('sheq:kpis:compute', ['--company' => $this->companyId, '--months' => 1])
            ->assertSuccessful();

        $leading = $this->service()->indicators($this->companyId)['leading'];

        $this->assertSame(1, $leading['changes_open'],
            'An implemented-but-unreviewed change is counted as finished, so the one state MOC-5 '
            .'exists to make visible is invisible.');
    }

    public function test_safe_observations_are_not_counted_as_unsafe_ones(): void
    {
        $this->observation('POSITIVE');
        $this->observation('POSITIVE');
        $this->observation('UNSAFE');

        $this->artisan('sheq:kpis:compute', ['--company' => $this->companyId, '--months' => 1])
            ->assertSuccessful();

        $leading = $this->service()->indicators($this->companyId)['leading'];

        $this->assertSame(3, $leading['observations_recorded']);
        $this->assertSame(1, $leading['unsafe_observations'],
            'Positive observations counted as unsafe, so a site running a good behavioural '
            .'programme looks worse than one running none.');
    }

    // ── CMD-6 ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_heatmap_has_a_cell_for_every_signal_on_every_row(): void
    {
        /*
         * Ragged rows cannot be read down a column, and reading down a column is how somebody
         * notices that four sites all have the same problem — which is the only thing a heatmap
         * does that a list does not.
         */
        $this->attendance(60);
        $this->incident(3);

        app(SiteRiskScoreService::class)->computeAll($this->companyId);

        $heatmap = $this->service()->heatmap($this->companyId);

        $this->assertNotEmpty($heatmap['rows']);
        $this->assertCount(count(SiteRiskScoreService::signalKeys()), $heatmap['signals']);

        foreach ($heatmap['rows'] as $row) {
            $this->assertCount(count($heatmap['signals']), $row['cells'],
                "The row for {$row['site_name']} has fewer cells than there are signals, so the "
                .'heatmap cannot be read down a column.');
        }
    }

    public function test_a_site_with_no_score_shows_as_no_data_on_the_heatmap(): void
    {
        $this->site('Nobody here');

        app(SiteRiskScoreService::class)->computeAll($this->companyId);

        $rows = $this->service()->heatmap($this->companyId)['rows'];
        $quiet = collect($rows)->firstWhere('site_name', 'Nobody here');

        $this->assertNotNull($quiet);
        $this->assertNull($quiet['risk_score'],
            'An empty site shows a risk score of 0, which is the safest possible figure.');
    }

    // ── CMD-7 ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_executive_dashboard_has_the_eight_panels_the_requirement_names(): void
    {
        $executive = $this->service()->executive($this->companyId);

        $required = [
            'top risks' => 'top_risks',
            'worst-performing contractors' => 'worst_contractors',
            'compliance' => 'compliance',
            'incidents' => 'incidents',
            'cost' => 'cost',
            'CAPA' => 'capa',
            'environmental performance' => 'environmental',
            'quality' => 'quality',
        ];

        foreach ($required as $words => $key) {
            $this->assertArrayHasKey($key, $executive,
                "CMD-7 lists '{$words}' and the executive dashboard does not provide it.");
        }
    }

    public function test_cost_is_named_in_a_currency(): void
    {
        /*
         * Every cost on this system is in minor units. A figure in minor units with no currency
         * on it is read as rands by one person and cents by the next, and the difference reaches
         * a board pack.
         */
        $cost = $this->service()->executive($this->companyId)['cost'];

        $this->assertNotEmpty($cost['currency'],
            'The cost panel has no currency, so a number in cents will be read as rands.');
        $this->assertArrayHasKey('total_minor', $cost);
        $this->assertSame(
            $cost['incidents_minor'] + $cost['corrective_actions_minor'] + $cost['rework_minor'],
            $cost['total_minor'],
            'The total does not reconcile with its parts, which is the first thing somebody '
            .'checks and the last thing they forgive.');
    }

    // ── CMD-2 ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_drill_down_covers_every_level_the_requirement_names(): void
    {
        foreach (['GROUP', 'REGION', 'SITE', 'DEPARTMENT', 'CONTRACTOR', 'WORKER',
            'EVIDENCE'] as $level) {
            $this->assertContains($level, CommandCentreService::LEVELS,
                "CMD-2 names {$level} and the drill-down has no case for it.");

            // Every level has to answer without an exception even with no filters, because a
            // dashboard that throws when somebody clicks twice is a dashboard they stop using.
            $result = $this->service()->drill($this->companyId, $level, []);

            $this->assertArrayHasKey('rows', $result,
                "Drilling to {$level} with no filters returned no rows key.");
        }
    }

    public function test_an_estate_with_no_regions_collapses_to_its_sites(): void
    {
        /*
         * `sheq_sites` is a tree, so a region is a site somebody has put other sites under — not
         * a separate concept. A flat estate therefore has no regions, and an empty regions screen
         * would read as a configuration error rather than as a flat estate.
         */
        $result = $this->service()->drill($this->companyId, 'GROUP');

        $this->assertTrue($result['collapsed'],
            'A flat estate shows an empty region list rather than skipping to the sites.');
        $this->assertNotEmpty($result['rows']);
        $this->assertSame('REGION', $result['level']);
    }

    public function test_a_region_is_recognised_when_one_exists(): void
    {
        $region = $this->site('Gauteng');

        DB::table('sheq_sites')->where('id', $this->siteId)->update(['parent_id' => $region]);

        $result = $this->service()->drill($this->companyId, 'GROUP');

        $this->assertFalse($result['collapsed']);
        $this->assertSame('GROUP', $result['level']);

        $names = array_column($result['rows'], 'name');

        $this->assertContains('Gauteng', $names);
        $this->assertNotContains('Tower block', $names,
            'A child site is listed as a region alongside its own parent.');
    }

    public function test_every_drill_row_carries_what_the_next_level_needs(): void
    {
        $region = $this->site('Gauteng');
        DB::table('sheq_sites')->where('id', $this->siteId)->update(['parent_id' => $region]);

        $group = $this->service()->drill($this->companyId, 'GROUP');

        foreach ($group['rows'] as $row) {
            $this->assertArrayHasKey('next', $row,
                'A drill row has no next step, so the screen has to know the hierarchy itself — '
                .'which is how the two come to disagree about what a region is.');
            $this->assertArrayHasKey('level', $row['next']);
        }

        // And the next step actually resolves.
        $next = $group['rows'][0]['next'];
        $sites = $this->service()->drill($this->companyId, $next['level'], $next);

        $this->assertNotEmpty($sites['rows']);
    }

    public function test_the_bottom_of_the_drill_down_returns_records_not_aggregates(): void
    {
        $contractorId = $this->contractor('Acme Scaffolding');
        $workerId = $this->worker($contractorId, 'Johan Pretorius');

        $evidence = $this->service()->drill($this->companyId, 'EVIDENCE',
            ['worker_id' => $workerId]);

        $this->assertFalse($evidence['collapsed']);
        $this->assertSame('Johan Pretorius', $evidence['worker']['name']);

        // The authoritative answer, from the engine — not a count.
        $this->assertArrayHasKey('decision', $evidence);
        $this->assertArrayHasKey('outcome', $evidence['decision']);
        $this->assertArrayHasKey('credentials', $evidence);
        $this->assertArrayHasKey('access_events', $evidence);
    }

    public function test_drilling_to_evidence_without_a_worker_does_not_explode(): void
    {
        $evidence = $this->service()->drill($this->companyId, 'EVIDENCE', []);

        $this->assertTrue($evidence['collapsed']);
        $this->assertSame([], $evidence['rows']);
    }

    public function test_an_unknown_level_falls_back_rather_than_failing(): void
    {
        $result = $this->service()->drill($this->companyId, 'PLANET');

        $this->assertArrayHasKey('rows', $result);
    }

    // ── CMD-8 ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_export_carries_all_four_things_the_requirement_asks_for(): void
    {
        $provenance = new ReportProvenance(
            title: 'Incidents detail',
            from: Carbon::parse('2026-10-01'),
            to: Carbon::parse('2026-10-31'),
            filters: ['Site' => 'Tower block'],
            ownerName: 'Nomsa Dlamini',
            ownerEmail: 'nomsa@example.test',
        );

        $flat = implode("\n", array_map(
            fn (array $row) => implode(' ', $row), $provenance->headerRows()));

        // CMD-8's four.
        $this->assertStringContainsString('Tower block', $flat, 'The filters are not on the export.');
        $this->assertStringContainsString('2026-10-01 to 2026-10-31', $flat,
            'The date period is not on the export.');
        $this->assertStringContainsString($provenance->generated()->toDateTimeString(), $flat,
            'The generated timestamp is not on the export.');
        $this->assertStringContainsString('Nomsa Dlamini', $flat,
            'The report owner is not on the export.');
    }

    public function test_an_unfiltered_export_says_all_rather_than_leaving_it_blank(): void
    {
        $provenance = new ReportProvenance(
            title: 'Incidents detail',
            from: Carbon::parse('2026-10-01'), to: Carbon::parse('2026-10-31'),
            filters: ['Site' => null],
        );

        $flat = implode(' ', array_map(
            fn (array $row) => implode(' ', $row), $provenance->headerRows()));

        $this->assertStringContainsString('All', $flat,
            'A blank filter value is ambiguous — it reads as "the filter was lost" as easily as '
            .'"no filter was applied".');
    }

    public function test_an_unattributed_export_says_so(): void
    {
        $provenance = new ReportProvenance(
            title: 'Incidents detail',
            from: Carbon::parse('2026-10-01'), to: Carbon::parse('2026-10-31'),
        );

        $this->assertStringContainsString('Unattributed', $provenance->owner(),
            'An export with no signed-in user leaves the owner blank, which reads as a missing '
            .'field rather than as a fact about the document.');
    }

    public function test_the_filename_carries_the_period_so_two_downloads_do_not_collide(): void
    {
        $provenance = new ReportProvenance(
            title: 'Incidents detail',
            from: Carbon::parse('2026-09-01'), to: Carbon::parse('2026-09-30'),
        );

        $this->assertSame('sheq-incidents-detail-2026-09-01-to-2026-09-30.csv',
            $provenance->filename('incidents_detail', 'csv'));
    }

    public function test_all_three_formats_the_requirement_names_are_served(): void
    {
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $response = $this->actingAs($this->user)->get(
                "/sheq/insights/reports/export?report=incidents_detail&format={$format}");

            $response->assertOk();

            $disposition = $response->headers->get('content-disposition');

            $this->assertStringContainsString(".{$format}", (string) $disposition,
                "CMD-8 names {$format} and the export did not produce one.");
        }
    }

    public function test_the_csv_export_puts_its_provenance_above_the_headings(): void
    {
        $response = $this->actingAs($this->user)->get(
            '/sheq/insights/reports/export?report=incidents_detail&format=csv'
            ."&site_id={$this->siteId}");

        $response->assertOk();

        $body = $response->streamedContent();

        $this->assertStringContainsString('Report owner', $body);
        $this->assertStringContainsString('Tower block', $body,
            'The site filter is not named on the export, so two people comparing exports cannot '
            .'tell whether they filtered differently.');

        // The heading row has to come after the preamble, not before it.
        $this->assertGreaterThan(strpos($body, 'Report owner'), strpos($body, 'reference'),
            'The headings are above the provenance block, so a reader sees the data first and '
            .'the context last.');
    }

    // ── The page ──────────────────────────────────────────────────────────────────────────────

    public function test_the_page_sends_everything_the_eight_requirements_need(): void
    {
        $page = $this->actingAs($this->user)->get('/sheq/command-centre')
            ->assertOk()->viewData('page')['props'];

        $this->assertArrayHasKey('tiles', $page['board']);          // CMD-1
        $this->assertNotEmpty($page['levels']);                     // CMD-2
        $this->assertArrayHasKey('as_at', $page['board']);          // CMD-3
        $this->assertArrayHasKey('leading', $page['indicators']);   // CMD-4
        $this->assertArrayHasKey('lagging', $page['indicators']);   // CMD-5
        $this->assertArrayHasKey('rows', $page['heatmap']);         // CMD-6
        $this->assertArrayHasKey('top_risks', $page['executive']);  // CMD-7
        $this->assertNotEmpty($page['signals']);
        $this->assertNotEmpty($page['weights']);
        $this->assertTrue($page['canConfigure']);
    }

    public function test_reweighting_the_score_needs_the_configure_entitlement(): void
    {
        $reader = $this->userWith([]);

        $this->actingAs($reader)->post('/sheq/command-centre/weights',
            ['weights' => ['incidents' => 1]])->assertForbidden();

        $this->actingAs($this->user)->post('/sheq/command-centre/weights',
            ['weights' => ['incidents' => 20]])->assertRedirect();

        $this->assertSame(20,
            app(SiteRiskScoreService::class)->weightsFor($this->companyId)['incidents']);
    }

    public function test_the_drill_endpoint_refuses_a_level_it_does_not_know(): void
    {
        $this->actingAs($this->user)
            ->getJson('/sheq/command-centre/drill?level=PLANET')
            ->assertStatus(422);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function site(string $name): int
    {
        return (int) DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'C'.Str::upper(Str::random(5)), 'name' => $name,
            'site_type' => 'CONSTRUCTION', 'timezone' => 'Africa/Johannesburg',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function permit(string $status, ?int $siteId = null): int
    {
        return (int) DB::table('sheq_permits')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'PTW-'.Str::upper(Str::random(6)), 'permit_type' => 'HOT_WORK',
            'site_id' => $siteId ?? $this->siteId, 'work_description' => 'Welding',
            'status' => $status, 'valid_from' => now()->subHour(),
            'valid_to' => now()->addHours(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attendance(int $people): void
    {
        for ($i = 0; $i < $people; $i++) {
            DB::table('sheq_site_attendance')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'site_id' => $this->siteId, 'subject_type' => 'EMPLOYEE',
                'subject_id' => random_int(1, 999999999),
                'person_name' => 'Person '.$i,
                'entered_at' => Carbon::now()->startOfMonth()->addDay(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function incident(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('sheq_incidents')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'INC-'.Str::upper(Str::random(8)), 'site_id' => $this->siteId,
                'incident_type' => 'INJURY', 'actual_severity' => 2, 'potential_severity' => 3,
                'occurred_at' => Carbon::now()->startOfMonth()->addDays(2),
                'reported_at' => Carbon::now()->startOfMonth()->addDays(2),
                'description' => 'Test', 'status' => 'REPORTED',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function observation(string $type): void
    {
        DB::table('sheq_observations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'OBS-'.Str::upper(Str::random(8)), 'observation_type' => $type,
            'site_id' => $this->siteId, 'behaviour' => 'Test', 'risk_rating' => 2,
            'observed_at' => Carbon::now()->startOfMonth()->addDays(3),
            'observed_by' => $this->user->id, 'status' => 'OPEN',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function change(string $status): void
    {
        DB::table('sheq_changes')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'MOC-'.Str::upper(Str::random(6)), 'title' => 'Test change',
            'change_category' => 'PLANT', 'site_id' => $this->siteId,
            'risk_rating' => 'HIGH', 'status' => $status,
            'requested_by' => $this->user->id, 'requested_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function contractor(string $name): int
    {
        return (int) DB::table('sheq_contractors')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId, 'name' => $name,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function worker(int $contractorId, string $name): int
    {
        return (int) DB::table('sheq_contractor_workers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'contractor_id' => $contractorId, 'name' => $name, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function exposureHours(int $hours): void
    {
        DB::table('sheq_exposure_hours')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => null,
            'period_year' => (int) Carbon::now()->year,
            'period_month' => (int) Carbon::now()->month,
            'employee_hours' => $hours, 'contractor_hours' => 0,
            'employee_headcount' => (int) round($hours / 160), 'contractor_headcount' => 0,
            'source' => 'MANUAL', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userWith(array $permissions): User
    {
        $userId = DB::table('users')->insertGetId([
            'uuid' => (string) Str::uuid(), 'email' => 'cmd-'.Str::random(8).'@example.test',
            'password' => bcrypt('secret'), 'company_id' => $this->companyId, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $moduleId = DB::table('security_modules')->where('code_name', 'sheq')->value('id');

        if ($moduleId === null) {
            $this->markTestSkipped('SHEQ module is not registered.');
        }

        DB::table('security_module_access')->insert([
            'module_id' => $moduleId, 'user_id' => $userId, 'access_level' => 4,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('security_modules')->where('id', $moduleId)->update(['active' => 1]);
        Cache::forget('module_active_sheq');

        $user = User::withoutGlobalScopes()->findOrFail($userId);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        $user->forgetCachedPermissions();

        return $user;
    }
}
