<?php

namespace Tests\Feature\SupplierManagement;

use App\Modules\SupplierManagement\DueDiligence\Contracts\VerificationProvider;
use App\Modules\SupplierManagement\DueDiligence\Data\ProviderHealth;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;
use App\Modules\SupplierManagement\DueDiligence\Services\ChangeDetector;
use App\Modules\SupplierManagement\DueDiligence\Services\ContinuousMonitor;
use App\Modules\SupplierManagement\DueDiligence\Services\MonitoringReport;
use App\Modules\SupplierManagement\DueDiligence\Services\ProviderRegistry;
use App\Modules\SupplierManagement\DueDiligence\Services\RiskEngine;
use App\Modules\SupplierManagement\DueDiligence\Services\VerificationEngine;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\ProviderCatalogue;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierChangeEvent;
use App\Modules\SupplierManagement\Models\SupplierCreditReport;
use App\Modules\SupplierManagement\Models\SupplierRiskFlag;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;

/**
 * Phase 5: has anything changed since we approved them?
 *
 * The tests here are mostly about restraint again, for the same reason as reputation monitoring: a
 * change detector that reports formatting differences, or reports every first check as a change,
 * produces a queue nobody reads — and then the new director nobody disclosed arrives into it.
 */
class ContinuousMonitoringTest extends SupplierTestCase
{
    private ContinuousMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        ProviderCatalogue::install();
        $this->monitor = app(ContinuousMonitor::class);
    }

    private function supplier(array $attributes = []): Supplier
    {
        return Supplier::create(array_merge([
            'company_name' => 'Zolani Civils (Pty) Ltd',
            'company_registration_number' => '2019/'.random_int(100000, 999999).'/07',
            'account_holder_name' => 'Zolani Civils',
            'bank_account_number' => '62012345678',
            'is_active' => 1,
        ], $attributes));
    }

    /** A registry provider whose answer the test controls. */
    private function stubRegistry(array $observed, string $checkType = CheckType::COMPANY_DIRECTORS): void
    {
        TenantVerificationProvider::query()->firstOrCreate(
            ['provider_code' => 'cipc'],
            ['is_enabled' => true, 'credentials' => ['api_key' => 'k'], 'enabled_checks' => [$checkType]]
        );

        app(ProviderRegistry::class)->fake('cipc', new class($checkType, $observed) implements VerificationProvider
        {
            public function __construct(private string $checkType, private array $observed) {}

            public function code(): string
            {
                return 'cipc';
            }

            public function capabilities(): array
            {
                return [$this->checkType];
            }

            public function supports(string $checkType): bool
            {
                return $checkType === $this->checkType;
            }

            public function verify(VerificationRequest $request): VerificationResult
            {
                return VerificationResult::verified($this->observed, 'Registry answered.');
            }

            public function health(): ProviderHealth
            {
                return ProviderHealth::ok();
            }
        });
    }

    // ── Detecting change ────────────────────────────────────────────────────

    public function test_a_new_director_is_detected_and_reported(): void
    {
        $supplier = $this->supplier();

        $this->stubRegistry(['directors' => ['John Smith', 'Mary Jones']]);
        $this->monitor->sweep($supplier, 'scheduled', true);

        // Next month the registry lists somebody new.
        $this->stubRegistry(['directors' => ['John Smith', 'Mary Jones', 'David Ndlovu']]);
        $run = $this->monitor->sweep($supplier->fresh(), 'scheduled', true);

        $change = SupplierChangeEvent::where('supplier_id', $supplier->id)->first();

        $this->assertNotNull($change, 'A new director on the registry was not reported.');
        $this->assertEquals('directors', $change->category);
        $this->assertStringContainsString('David Ndlovu', $change->current_value);
        $this->assertEquals('high', $change->severity);
        $this->assertGreaterThan(0, $run->findings_new);
    }

    public function test_the_first_check_is_a_baseline_not_a_change(): void
    {
        $supplier = $this->supplier();
        $this->stubRegistry(['directors' => ['John Smith']]);

        $this->monitor->sweep($supplier, 'scheduled', true);

        // Reporting every initial check as "changed" would bury the real ones on day one.
        $this->assertEquals(0, SupplierChangeEvent::where('supplier_id', $supplier->id)->count());
    }

    public function test_the_same_fact_written_differently_is_not_a_change(): void
    {
        $supplier = $this->supplier();

        $this->stubRegistry(['registration_number' => '2019/445566/07', 'company_name' => 'Zolani Civils (Pty) Ltd'],
            CheckType::COMPANY_REGISTRY);
        $this->monitor->sweep($supplier, 'scheduled', true);

        // Same company, formatted the way the other endpoint writes it.
        $this->stubRegistry(['registration_number' => '201944556607', 'company_name' => 'ZOLANI CIVILS PROPRIETARY LIMITED'],
            CheckType::COMPANY_REGISTRY);
        $this->monitor->sweep($supplier->fresh(), 'scheduled', true);

        // A monitor that reports formatting teaches people to ignore it.
        $this->assertEquals(0, SupplierChangeEvent::where('supplier_id', $supplier->id)->count());
    }

    public function test_a_check_that_stops_passing_is_itself_the_change(): void
    {
        $supplier = $this->supplier();
        $engine = app(VerificationEngine::class);

        $engine->recordManual($supplier, CheckType::TAX_COMPLIANCE, VerificationStatus::VERIFIED,
            observed: ['compliance_status' => 'Compliant']);

        $lapsed = $engine->recordManual($supplier, CheckType::TAX_COMPLIANCE, VerificationStatus::NOT_FOUND,
            note: 'SARS no longer returns a compliant status.');

        $changes = app(ChangeDetector::class)->forVerification($lapsed);

        $this->assertNotEmpty($changes);
        $this->assertStringContainsString('no longer passes', $changes[0]->summary);
        $this->assertEquals('high', $changes[0]->severity);
    }

    public function test_a_serious_change_reassesses_the_supplier_and_records_the_movement(): void
    {
        $supplier = $this->supplier();
        app(RiskEngine::class)->assess($supplier);
        $before = (float) $supplier->fresh()->risk_score;

        $this->stubRegistry(['company_status' => 'In business'], CheckType::COMPANY_REGISTRY);
        $this->monitor->sweep($supplier->fresh(), 'scheduled', true);

        $this->stubRegistry(['company_status' => 'Deregistration in process'], CheckType::COMPANY_REGISTRY);
        $this->monitor->sweep($supplier->fresh(), 'scheduled', true);

        $change = SupplierChangeEvent::where('supplier_id', $supplier->id)
            ->where('category', 'company_status')->first();

        $this->assertNotNull($change);
        $this->assertTrue($change->triggered_reassessment);
        // Recorded rather than inferred: "the score went up that week" is not proof of a cause.
        $this->assertNotNull($change->risk_after);
        $this->assertEquals($before, (float) $change->risk_before);
    }

    public function test_one_flag_is_raised_for_a_sweep_however_many_changes_it_found(): void
    {
        $supplier = $this->supplier();

        $this->stubRegistry(['directors' => ['A'], 'company_status' => 'In business'], CheckType::COMPANY_REGISTRY);
        $this->monitor->sweep($supplier, 'scheduled', true);

        $this->stubRegistry(['directors' => ['A', 'B'], 'company_status' => 'Deregistered'], CheckType::COMPANY_REGISTRY);
        $this->monitor->sweep($supplier->fresh(), 'scheduled', true);

        $flags = SupplierRiskFlag::where('supplier_id', $supplier->id)->where('type', 'supplier_changed')->get();

        // A flag per change puts four rows on a supplier for one afternoon.
        $this->assertCount(1, $flags);
        $this->assertEquals('critical', $flags->first()->severity);
    }

    // ── Credit deterioration ────────────────────────────────────────────────

    public function test_a_falling_credit_score_is_reported_and_a_wobble_is_not(): void
    {
        $supplier = $this->supplier();
        $detector = app(ChangeDetector::class);

        $first = SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'manual',
            'score' => 80, 'score_max' => 100, 'report_date' => now()->subMonths(2)->toDateString(),
        ]);
        $this->assertNull($detector->forCreditReport($first), 'A first report has nothing to compare to.');

        // Two points is bureau noise.
        $wobble = SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'manual',
            'score' => 78, 'score_max' => 100, 'report_date' => now()->subMonth()->toDateString(),
        ]);
        $this->assertNull($detector->forCreditReport($wobble));

        $drop = SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'manual',
            'score' => 40, 'score_max' => 100, 'report_date' => now()->toDateString(),
        ]);
        $change = $detector->forCreditReport($drop);

        $this->assertNotNull($change);
        $this->assertEquals('credit', $change->category);
        $this->assertStringContainsString('worsened', $change->summary);
        $this->assertEquals('high', $change->severity);
    }

    public function test_a_new_judgment_outranks_the_score_movement(): void
    {
        $supplier = $this->supplier();

        SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'manual',
            'score' => 80, 'score_max' => 100, 'judgments_count' => 0,
            'report_date' => now()->subMonth()->toDateString(),
        ]);
        $withJudgment = SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'manual',
            'score' => 79, 'score_max' => 100, 'judgments_count' => 2,
            'report_date' => now()->toDateString(),
        ]);

        $change = app(ChangeDetector::class)->forCreditReport($withJudgment);

        $this->assertEquals('judgments', $change->field);
        $this->assertStringContainsString('2 new judgment', $change->summary);
    }

    public function test_an_improving_score_is_recorded_quietly(): void
    {
        $supplier = $this->supplier();

        SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'manual',
            'score' => 40, 'score_max' => 100, 'report_date' => now()->subMonth()->toDateString(),
        ]);
        $better = SupplierCreditReport::create([
            'supplier_id' => $supplier->id, 'provider_code' => 'manual',
            'score' => 85, 'score_max' => 100, 'report_date' => now()->toDateString(),
        ]);

        $change = app(ChangeDetector::class)->forCreditReport($better);

        // A supplier recovering is worth knowing; a monitor that only reports bad news is a charge
        // sheet.
        $this->assertNotNull($change);
        $this->assertEquals('low', $change->severity);
        $this->assertStringContainsString('improved', $change->summary);
    }

    // ── Changes that arrive by somebody typing them ─────────────────────────

    public function test_editing_banking_details_raises_a_change_by_itself(): void
    {
        $supplier = $this->supplier();

        $supplier->update(['bank_account_number' => '99988877766']);

        $change = SupplierChangeEvent::where('supplier_id', $supplier->id)
            ->where('category', 'banking')->first();

        // No external source will ever report this, and it is the most consequential field on the
        // record — the edit has to be the event.
        $this->assertNotNull($change, 'Banking details were changed with no trace.');
        $this->assertEquals('critical', $change->severity);
        $this->assertEquals('edit', $change->detected_by);
        $this->assertStringContainsString('Verify the account', $change->summary);
    }

    public function test_editing_a_field_nobody_watches_raises_nothing(): void
    {
        $supplier = $this->supplier();

        $supplier->update(['notes' => 'Called them about the delivery.']);

        $this->assertEquals(0, SupplierChangeEvent::where('supplier_id', $supplier->id)->count());
    }

    public function test_the_edit_observer_is_silent_without_the_addon(): void
    {
        \Illuminate\Support\Facades\DB::table('company_modules')
            ->where('company_id', $this->companyId)
            ->where('module_code', 'supplier_due_diligence')->delete();

        $supplier = $this->supplier();
        $supplier->update(['bank_account_number' => '99988877766']);

        $this->assertEquals(0, SupplierChangeEvent::where('supplier_id', $supplier->id)->count());
    }

    // ── What is due ─────────────────────────────────────────────────────────

    public function test_a_check_is_due_again_once_its_own_freshness_horizon_passes(): void
    {
        $supplier = $this->supplier(['risk_band' => 'low']);
        $engine = app(VerificationEngine::class);

        $engine->recordManual($supplier, CheckType::TAX_COMPLIANCE, VerificationStatus::VERIFIED);
        $engine->recordManual($supplier, CheckType::COMPANY_ADDRESS, VerificationStatus::VERIFIED);

        // Tax is a statement about a moment (30 days); an address is not (180).
        SupplierVerification::query()
            ->where('supplier_id', $supplier->id)
            ->where('check_type', CheckType::TAX_COMPLIANCE)
            ->update(['checked_at' => now()->subDays(45)]);

        $due = $this->monitor->dueChecks($supplier->fresh());

        $this->assertContains(CheckType::TAX_COMPLIANCE, $due);
        $this->assertNotContains(CheckType::COMPANY_ADDRESS, $due);
    }

    public function test_a_critical_supplier_comes_round_sooner_than_a_low_risk_one(): void
    {
        $engine = app(VerificationEngine::class);

        $critical = $this->supplier(['risk_band' => 'critical']);
        $low = $this->supplier(['risk_band' => 'low']);

        foreach ([$critical, $low] as $supplier) {
            $engine->recordManual($supplier, CheckType::COMPANY_REGISTRY, VerificationStatus::VERIFIED);
            SupplierVerification::query()
                ->where('supplier_id', $supplier->id)
                ->update(['checked_at' => now()->subDays(30)]);
        }

        // 90-day horizon: a quarter of that for a critical supplier, all of it for a low-risk one.
        $this->assertContains(CheckType::COMPANY_REGISTRY, $this->monitor->dueChecks($critical->fresh()));
        $this->assertNotContains(CheckType::COMPANY_REGISTRY, $this->monitor->dueChecks($low->fresh()));
    }

    public function test_a_check_nobody_has_ever_run_is_due_immediately(): void
    {
        $supplier = $this->supplier();

        // An unanswered question does not become less urgent by being ignored.
        $this->assertContains(CheckType::COMPANY_REGISTRY, $this->monitor->dueChecks($supplier));
        $this->assertTrue($this->monitor->isDue($supplier));
    }

    public function test_a_sweep_with_no_configured_source_records_the_gap(): void
    {
        $supplier = $this->supplier();

        $run = $this->monitor->sweep($supplier, 'scheduled');

        $this->assertGreaterThan(0, $run->sources_unavailable);
        $this->assertDatabaseHas('supplier_verifications', [
            'supplier_id' => $supplier->id,
            'status' => VerificationStatus::UNAVAILABLE,
        ]);
        // And it does not silently look monitored afterwards.
        $this->assertNotNull($supplier->fresh()->last_monitored_at);
    }

    // ── The report ──────────────────────────────────────────────────────────

    public function test_the_report_counts_suppliers_actually_swept_not_suppliers_on_the_books(): void
    {
        $swept = $this->supplier();
        $this->supplier();      // never touched
        $this->supplier();

        $this->monitor->sweep($swept, 'scheduled');

        $report = app(MonitoringReport::class)->forPeriod(now()->subDay(), now()->addDay());

        $this->assertEquals(3, $report['coverage']['active_suppliers']);
        // A supplier nobody looked at is not a supplier being monitored, however many rows the
        // directory has.
        $this->assertEquals(1, $report['coverage']['monitored']);
        $this->assertEquals(2, $report['coverage']['never_monitored']);
    }

    public function test_the_report_separates_alerts_nobody_has_read_from_the_rest(): void
    {
        $supplier = $this->supplier();

        SupplierChangeEvent::create([
            'supplier_id' => $supplier->id, 'category' => 'directors', 'severity' => 'high',
            'summary' => 'A new director appeared.', 'status' => SupplierChangeEvent::STATUS_OPEN,
            'detected_at' => now(),
        ]);
        SupplierChangeEvent::create([
            'supplier_id' => $supplier->id, 'category' => 'address', 'severity' => 'low',
            'summary' => 'Address changed.', 'status' => SupplierChangeEvent::STATUS_ACKNOWLEDGED,
            'detected_at' => now(),
        ]);

        $report = app(MonitoringReport::class)->forPeriod(now()->subDay(), now()->addDay());

        $this->assertEquals(2, $report['alerts']['total']);
        // The number that matters, and the one that gets buried.
        $this->assertEquals(1, $report['alerts']['unreviewed']);
        $this->assertEquals(1, $report['alerts']['high']);
        $this->assertEquals(1, $report['changes']['by_category']['directors']);
    }

    public function test_the_report_names_the_suppliers_needing_attention(): void
    {
        $supplier = $this->supplier(['company_name' => 'Needs Looking At (Pty) Ltd']);

        SupplierChangeEvent::create([
            'supplier_id' => $supplier->id, 'category' => 'company_status', 'severity' => 'critical',
            'summary' => 'The company is being deregistered.', 'status' => SupplierChangeEvent::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $report = app(MonitoringReport::class)->forPeriod(now()->subDay(), now()->addDay());

        // A count of nine tells nobody which nine.
        $this->assertCount(1, $report['attention']['suppliers']);
        $this->assertEquals('Needs Looking At (Pty) Ltd', $report['attention']['suppliers'][0]['name']);
        $this->assertEquals('critical', $report['attention']['suppliers'][0]['worst']);
    }

    public function test_change_events_are_tenant_scoped(): void
    {
        $supplier = $this->supplier();
        $supplier->update(['bank_account_number' => '11122233344']);

        $this->assertEquals(1, SupplierChangeEvent::count());

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->makeCompany('Other Tenant'));

        $this->assertEquals(0, SupplierChangeEvent::count());
    }

    // ── The inbox ───────────────────────────────────────────────────────────

    public function test_the_inbox_gathers_everything_nobody_has_looked_at(): void
    {
        $supplier = $this->supplier(['company_name' => 'Watched Co']);
        $this->withoutVite();

        SupplierChangeEvent::create([
            'supplier_id' => $supplier->id, 'category' => 'directors', 'severity' => 'high',
            'summary' => 'A new director appeared.', 'status' => SupplierChangeEvent::STATUS_OPEN,
            'detected_at' => now()->subDays(3),
        ]);

        \App\Modules\SupplierManagement\Models\SupplierFinding::create([
            'supplier_id' => $supplier->id, 'channel' => 'news', 'title' => 'Investigation reported',
            'url' => 'https://news24.com/'.uniqid(), 'category' => 'regulatory', 'severity' => 'high',
            'entity_confidence' => 90, 'status' => 'open',
        ]);

        \App\Modules\SupplierManagement\Models\ScreeningHit::create([
            'supplier_id' => $supplier->id, 'subject_type' => 'supplier', 'subject_name' => 'Watched Co',
            'list_type' => 'sanctions', 'matched_name' => 'Watched Co', 'match_score' => 88,
            'provider_code' => 'manual', 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        $this->get('/suppliers/monitoring')
            ->assertOk()
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];

                // One place for all three, because they are one job.
                $this->assertCount(1, $props['changes']);
                $this->assertCount(1, $props['findings']);
                $this->assertCount(1, $props['hits']);
                $this->assertEquals(3, $props['summary']['changes'] + $props['summary']['findings'] + $props['summary']['hits']);
                // The item quietly ageing at the bottom gets its own number.
                $this->assertEquals(3, $props['summary']['oldest_days']);
            });
    }

    public function test_the_inbox_lists_suppliers_whose_checks_have_gone_stale(): void
    {
        $neverSwept = $this->supplier(['company_name' => 'Never Swept Co']);
        $this->withoutVite();

        $this->get('/suppliers/monitoring')->assertInertia(function ($page) use ($neverSwept) {
            $due = collect($page->toArray()['props']['due']);

            // Work nobody has been alerted to yet.
            $row = $due->firstWhere('id', $neverSwept->id);
            $this->assertNotNull($row);
            $this->assertTrue($row['never']);
            $this->assertGreaterThan(0, $row['due_checks']);
        });
    }

    public function test_dismissing_a_change_from_the_inbox_requires_a_reason(): void
    {
        $supplier = $this->supplier();
        $change = SupplierChangeEvent::create([
            'supplier_id' => $supplier->id, 'category' => 'banking', 'severity' => 'critical',
            'summary' => 'Banking details changed.', 'status' => SupplierChangeEvent::STATUS_OPEN,
            'detected_at' => now(),
        ]);

        $this->patch("/suppliers/monitoring/changes/{$change->id}", ['status' => 'dismissed'])
            ->assertSessionHasErrors('note');
        $this->assertEquals(SupplierChangeEvent::STATUS_OPEN, $change->fresh()->status);

        $this->patch("/suppliers/monitoring/changes/{$change->id}", [
            'status' => 'dismissed',
            'note' => 'Confirmed by phone with the finance director on a previously held number.',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(SupplierChangeEvent::STATUS_DISMISSED, $change->fresh()->status);
    }

    public function test_acknowledging_a_change_takes_it_out_of_the_inbox(): void
    {
        $supplier = $this->supplier();
        $change = SupplierChangeEvent::create([
            'supplier_id' => $supplier->id, 'category' => 'address', 'severity' => 'low',
            'summary' => 'Registered address changed.', 'status' => SupplierChangeEvent::STATUS_OPEN,
            'detected_at' => now(),
        ]);
        $this->withoutVite();

        $this->patch("/suppliers/monitoring/changes/{$change->id}", ['status' => 'acknowledged'])
            ->assertSessionHasNoErrors();

        $this->get('/suppliers/monitoring')->assertInertia(function ($page) {
            $this->assertCount(0, $page->toArray()['props']['changes']);
        });
    }

    public function test_a_supplier_can_be_swept_on_demand_from_the_inbox(): void
    {
        $supplier = $this->supplier();

        $this->post("/suppliers/{$supplier->uuid}/monitoring/sweep")
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($supplier->fresh()->last_monitored_at);
    }

    public function test_the_monthly_report_screen_reports_the_period(): void
    {
        $supplier = $this->supplier();
        $this->monitor->sweep($supplier, 'scheduled');
        $this->withoutVite();

        $this->get('/suppliers/monitoring/report')
            ->assertOk()
            ->assertInertia(function ($page) {
                $report = $page->toArray()['props']['report'];

                $this->assertArrayHasKey('coverage', $report);
                $this->assertEquals(1, $report['coverage']['monitored']);
                $this->assertArrayHasKey('unreviewed', $report['alerts']);
                $this->assertIsArray($report['attention']['suppliers']);
            });
    }
}
