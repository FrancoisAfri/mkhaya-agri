<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Console\ReclassifyIncidentsCommand;
use App\Modules\Operations\Sheq\Console\SweepEventsCommand;
use App\Modules\Operations\Sheq\Events\EventRegistry;
use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\KpiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The three events that were registered but never fired.
 *
 * `sheq.incident.classified`, `sheq.competency.required` and `sheq.risk.threshold_breached` were
 * declared in the registry, documented in the OpenAPI description, and offered to subscribers — and
 * nothing published them. A consumer could subscribe, see the subscription accepted, and wait
 * forever with no error anywhere to explain the silence. That is worse than not offering them.
 */
class RemainingEventsTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests read what a page hands its component, not the compiled front end. Without
        // this the suite depends on `public/build/manifest.json`, so an unrelated `vite build`
        // running in another checkout turns every page assertion below into a 500.
        $this->withoutVite();

        $companyId = DB::table('companies')->min('id');

        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }

        $this->companyId = (int) $companyId;

        $this->siteId = DB::table('sheq_sites')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'E'.Str::random(6), 'name' => 'Plant', 'site_type' => 'SITE',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function published(string $type): array
    {
        return DB::table('sheq_outbox_messages')
            ->where('company_id', $this->companyId)->where('event_type', $type)
            ->get()->map(fn ($r) => json_decode((string) $r->payload, true))->all();
    }

    // ── sheq.risk.threshold_breached ──────────────────────────────────────────────────────────

    private function threshold(array $overrides = []): void
    {
        DB::table('sheq_kpi_thresholds')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'site_id' => null, 'metric' => 'total_incidents', 'direction' => 'ABOVE',
            'threshold_value' => 1, 'severity' => 'WARNING', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function incident(array $overrides = []): int
    {
        return DB::table('sheq_incidents')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(6), 'site_id' => $this->siteId,
            'occurred_at' => now()->startOfMonth()->addDays(2),
            'reported_at' => now()->startOfMonth()->addDays(2),
            'incident_type' => 'INJURY', 'description' => 'Slip on wet floor.',
            'actual_severity' => 3, 'potential_severity' => 4, 'status' => 'REPORTED',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_a_lagging_indicator_above_its_threshold_is_announced(): void
    {
        $this->threshold();
        $this->incident();
        $this->incident();

        app(KpiService::class)->computePeriod(
            $this->companyId, (int) now()->year, (int) now()->month
        );

        $events = $this->published('sheq.risk.threshold_breached');

        $this->assertNotEmpty($events, 'Two incidents against a threshold of one must breach.');
        $this->assertSame('total_incidents', $events[0]['metric']);
        $this->assertGreaterThan($events[0]['threshold'], $events[0]['value']);
    }

    public function test_a_leading_indicator_breaches_downwards(): void
    {
        // The case a naive "breach = above" implementation silently never fires — and leading
        // indicators are the ones that give early warning, so they are exactly what thresholds
        // are worth setting on.
        $this->threshold(['metric' => 'total_incidents', 'direction' => 'BELOW',
            'threshold_value' => 5]);

        $this->incident();

        app(KpiService::class)->computePeriod(
            $this->companyId, (int) now()->year, (int) now()->month
        );

        $events = $this->published('sheq.risk.threshold_breached');

        $this->assertNotEmpty($events, 'A value below a BELOW threshold must breach.');
        $this->assertSame('BELOW', $events[0]['direction']);
        $this->assertLessThan($events[0]['threshold'], $events[0]['value']);
    }

    public function test_a_metric_within_its_threshold_is_silent(): void
    {
        $this->threshold(['threshold_value' => 99]);
        $this->incident();

        app(KpiService::class)->computePeriod(
            $this->companyId, (int) now()->year, (int) now()->month
        );

        $this->assertSame([], $this->published('sheq.risk.threshold_breached'));
    }

    public function test_recomputing_a_period_does_not_re_announce_the_same_breach(): void
    {
        $this->threshold();
        $this->incident();
        $this->incident();

        $kpis = app(KpiService::class);
        $year = (int) now()->year;
        $month = (int) now()->month;

        $kpis->computePeriod($this->companyId, $year, $month);
        // Recomputation happens whenever a late incident is filed against a closed month. The
        // escalation chain has already acted on the first announcement.
        $kpis->computePeriod($this->companyId, $year, $month);
        $kpis->computePeriod($this->companyId, $year, $month);

        $this->assertCount(1, $this->published('sheq.risk.threshold_breached'));
    }

    public function test_a_site_threshold_overrides_the_company_default(): void
    {
        $this->threshold(['metric' => 'total_incidents', 'threshold_value' => 99]);
        $this->threshold(['metric' => 'total_incidents', 'threshold_value' => 1,
            'site_id' => $this->siteId]);

        $this->incident();
        $this->incident();

        app(KpiService::class)->computePeriod(
            $this->companyId, (int) now()->year, (int) now()->month, $this->siteId
        );

        // A shaft and a head office have no business sharing an injury-rate target.
        $this->assertNotEmpty($this->published('sheq.risk.threshold_breached'));
    }

    // ── sheq.competency.required ──────────────────────────────────────────────────────────────

    private function competency(): int
    {
        return DB::table('sheq_competencies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'code' => 'C'.Str::random(6), 'title' => 'Working at heights',
            'competency_type' => 'TRAINING', 'validity_months' => 24,
            'blocks_work_when_expired' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function person(): int
    {
        return DB::table('hr_people')->insertGetId([
            'company_id' => $this->companyId, 'first_name' => 'Test',
            'surname' => 'Person'.Str::random(4),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_person_missing_a_required_competency_is_announced(): void
    {
        $competencyId = $this->competency();
        $personId = $this->person();

        DB::table('sheq_competency_requirements')->insert([
            'company_id' => $this->companyId, 'competency_id' => $competencyId,
            'applies_to_type' => 'EMPLOYEE', 'applies_to_id' => (string) $personId,
            'grace_days' => 0, 'is_exempt' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan(SweepEventsCommand::class, ['--company' => $this->companyId])
            ->assertSuccessful();

        $events = $this->published('sheq.competency.required');

        $this->assertNotEmpty($events, 'The LMS consumes this to enrol the person.');
        $this->assertSame($personId, $events[0]['hr_person_id']);
        $this->assertSame($competencyId, $events[0]['competency_id']);
    }

    public function test_someone_who_already_holds_the_competency_is_not_announced(): void
    {
        $competencyId = $this->competency();
        $personId = $this->person();

        DB::table('sheq_competency_requirements')->insert([
            'company_id' => $this->companyId, 'competency_id' => $competencyId,
            'applies_to_type' => 'EMPLOYEE', 'applies_to_id' => (string) $personId,
            'grace_days' => 0, 'is_exempt' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_competency_records')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'hr_person_id' => $personId, 'competency_id' => $competencyId,
            'achieved_at' => now()->subMonths(2), 'expires_at' => now()->addYear(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan(SweepEventsCommand::class, ['--company' => $this->companyId])
            ->assertSuccessful();

        $this->assertSame([], $this->published('sheq.competency.required'));
    }

    public function test_the_sweep_does_not_re_announce_the_same_gap(): void
    {
        $competencyId = $this->competency();
        $personId = $this->person();

        DB::table('sheq_competency_requirements')->insert([
            'company_id' => $this->companyId, 'competency_id' => $competencyId,
            'applies_to_type' => 'EMPLOYEE', 'applies_to_id' => (string) $personId,
            'grace_days' => 0, 'is_exempt' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The sweep runs on a schedule. Re-announcing every hour would train the LMS — and every
        // human recipient — to filter SHEQ out entirely.
        $this->artisan(SweepEventsCommand::class, ['--company' => $this->companyId]);
        $this->artisan(SweepEventsCommand::class, ['--company' => $this->companyId]);

        $this->assertCount(1, $this->published('sheq.competency.required'));
    }

    // ── sheq.incident.classified ──────────────────────────────────────────────────────────────

    public function test_reclassification_after_a_pack_change_announces_the_new_duties(): void
    {
        $manifest = (new PackLoader)->load(base_path('resources/regulatory-packs/za-ohsa'));

        $packId = DB::table('sheq_regulatory_packs')
            ->where('pack_id', $manifest->packId)->value('id');

        if ($packId === null) {
            $this->markTestSkipped('The OHSA pack is not installed in the test database.');
        }

        $versionId = DB::table('sheq_regulatory_pack_versions')
            ->where('pack_id', $packId)->where('version', $manifest->version)->value('id');

        DB::table('sheq_regulatory_pack_activations')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'pack_version_id' => $versionId, 'status' => 'ACTIVE',
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Assessed against an older version, and serious enough that the current rules classify it.
        $this->incident([
            'incident_type' => 'INJURY', 'outcome' => 'FATALITY',
            'actual_severity' => 5, 'potential_severity' => 5,
            'regulatory_pack_id' => $manifest->packId,
            'regulatory_pack_version' => '2019.0.0',
            'statutory_category' => null, 'is_reportable' => false,
        ]);

        $this->artisan(ReclassifyIncidentsCommand::class, ['--company' => $this->companyId])
            ->assertSuccessful();

        $events = $this->published('sheq.incident.classified');

        $this->assertNotEmpty($events,
            'A pack update that changes an incident’s duties must announce it — otherwise the '
            .'first anyone hears is an inspector asking why nothing was reported.');
        $this->assertArrayHasKey('previous_category', $events[0]);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $incidentId = $this->incident([
            'regulatory_pack_id' => 'za-ohsa', 'regulatory_pack_version' => '2019.0.0',
        ]);

        $this->artisan(ReclassifyIncidentsCommand::class,
            ['--company' => $this->companyId, '--dry-run' => true])->assertSuccessful();

        $this->assertSame('2019.0.0', DB::table('sheq_incidents')->where('id', $incidentId)
            ->value('regulatory_pack_version'));
        $this->assertSame([], $this->published('sheq.incident.classified'));
    }

    public function test_a_closed_incident_is_not_reassessed(): void
    {
        $incidentId = $this->incident([
            'status' => 'CLOSED',
            'regulatory_pack_id' => 'za-ohsa', 'regulatory_pack_version' => '2019.0.0',
        ]);

        $this->artisan(ReclassifyIncidentsCommand::class, ['--company' => $this->companyId])
            ->assertSuccessful();

        // A closed record's classification is history. Re-assessing it would rewrite what the
        // employer believed, and reported, at the time.
        $this->assertSame('2019.0.0', DB::table('sheq_incidents')->where('id', $incidentId)
            ->value('regulatory_pack_version'));
    }

    // ── The registry as a whole ───────────────────────────────────────────────────────────────

    public function test_every_registered_event_has_something_that_publishes_it(): void
    {
        $base = app_path('Modules/Operations/Sheq');
        $sources = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->getExtension() === 'php' && $file->getFilename() !== 'EventRegistry.php') {
                $sources[] = file_get_contents($file->getPathname());
            }
        }

        $haystack = implode("\n", $sources);
        $orphans = [];

        foreach (app(EventRegistry::class)->types() as $type) {
            if (! str_contains($haystack, "'{$type}'")) {
                $orphans[] = $type;
            }
        }

        // Offering an event nobody publishes is worse than not offering it: a consumer subscribes,
        // sees the subscription accepted, and waits forever with nothing to explain the silence.
        $this->assertSame([], $orphans,
            'These events are registered and offered to subscribers but never published: '
            .implode(', ', $orphans));
    }
}
