<?php

namespace Tests\Feature\Sheq;

use App\Modules\Operations\Sheq\Regulatory\PackLoader;
use App\Modules\Operations\Sheq\Services\KpiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SHEQ KPIs (§5.11).
 *
 * These are the numbers that reach a board pack and a regulator, so the tests are about arithmetic
 * honesty: no double counting, no zero standing in for "unknown", multipliers from the pack, and
 * an estimated denominator declared as estimated.
 */
class KpiTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;

    private int $siteId;

    private int $year = 2026;

    private int $month = 5;

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
            'code' => 'K'.Str::random(5), 'name' => 'Plant', 'site_type' => 'PLANT',
            'timezone' => 'Africa/Johannesburg', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->activatePack();
    }

    private function activatePack(): void
    {
        $manifest = (new PackLoader)->load(base_path('resources/regulatory-packs/za-ohsa'));

        $packId = DB::table('sheq_regulatory_packs')->insertGetId([
            'pack_id' => 'kpi-'.Str::random(6), 'jurisdiction' => 'ZA', 'name' => 'KPI test pack',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $versionId = DB::table('sheq_regulatory_pack_versions')->insertGetId([
            'pack_id' => $packId, 'version' => $manifest->version, 'effective_from' => '2020-01-01',
            'manifest' => json_encode($manifest->manifest), 'checksum' => $manifest->checksum(),
            'installed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('sheq_regulatory_pack_activations')->insert([
            'company_id' => $this->companyId, 'uuid' => (string) Str::uuid(), 'site_id' => null,
            'pack_version_id' => $versionId, 'status' => 'ACCEPTED',
            'activated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function service(): KpiService
    {
        return app(KpiService::class);
    }

    private function incident(array $attributes = []): void
    {
        DB::table('sheq_incidents')->insert(array_merge([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'INC-'.Str::random(8), 'site_id' => $this->siteId,
            'occurred_at' => Carbon::create($this->year, $this->month, 10, 9),
            'reported_at' => Carbon::create($this->year, $this->month, 10, 10),
            'incident_type' => 'INJURY', 'description' => 'Test incident',
            'status' => 'REPORTED', 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function hours(float $employeeHours, string $source = 'PAYROLL', int $headcount = 100): void
    {
        $this->service()->recordExposure($this->companyId, [
            'site_id' => $this->siteId, 'period_year' => $this->year, 'period_month' => $this->month,
            'employee_hours' => $employeeHours, 'employee_headcount' => $headcount, 'source' => $source,
        ]);
    }

    // ── Rates come from the pack (SHEQ-REG-7) ───────────────────────────────────────────────────

    public function test_ltifr_uses_the_packs_million_hour_multiplier(): void
    {
        $this->hours(500000);
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME', 'days_lost' => 5]);
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME', 'days_lost' => 3]);

        $result = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId);

        // 2 LTIs × 1,000,000 ÷ 500,000 = 4.0
        $this->assertSame(4.0, $result['metrics']['rates']['LTIFR']['value']);
        $this->assertSame(2, $result['metrics']['rates']['LTIFR']['numerator']);
        $this->assertSame(1000000, $result['metrics']['rates']['LTIFR']['multiplier']);
    }

    public function test_difr_uses_the_two_hundred_thousand_multiplier_on_the_same_data(): void
    {
        // The two rates differ by a factor of five; a single hard-coded multiplier misreports one.
        $this->hours(500000);
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME']);

        $result = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId);

        // 1 LTI: ×1,000,000 ÷ 500,000 = 2.0 for LTIFR, ×200,000 ÷ 500,000 = 0.4 for DIFR.
        $this->assertSame(2.0, $result['metrics']['rates']['LTIFR']['value']);
        $this->assertSame(0.4, $result['metrics']['rates']['DIFR']['value']);
    }

    public function test_a_period_with_no_exposure_hours_reports_null_rates_not_zero(): void
    {
        // Zero would render as flawless safety in the very month payroll hours were not uploaded.
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME']);

        $result = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId);

        $this->assertNull($result['metrics']['rates']['LTIFR']['value']);
        $this->assertSame(1, $result['metrics']['lagging']['lost_time_injuries'], 'The count is still real.');
    }

    // ── Counting (no double counting) ───────────────────────────────────────────────────────────

    public function test_an_incident_lands_in_exactly_one_severity_bucket(): void
    {
        // Outcome and treatment level both populated must not count the incident twice.
        $this->hours(200000);
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME']);

        $lagging = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['lagging'];

        $this->assertSame(1, $lagging['total_incidents']);
        $this->assertSame(1, $lagging['lost_time_injuries']);
        $this->assertSame(0, $lagging['medical_treatment']);
        $this->assertSame(1, $lagging['recordable_cases']);
    }

    public function test_disabling_injuries_include_fatalities_and_permanent_disability(): void
    {
        // Excluding a fatality would flatter DIFR in the worst possible month.
        $this->hours(200000);
        $this->incident(['outcome' => 'FATALITY']);
        $this->incident(['outcome' => 'PERMANENT_DISABILITY']);
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME']);

        $lagging = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['lagging'];

        $this->assertSame(1, $lagging['fatalities']);
        $this->assertSame(3, $lagging['disabling_injuries']);
        $this->assertSame(3, $lagging['recordable_cases']);
    }

    public function test_first_aid_is_not_recordable_under_the_south_african_pack(): void
    {
        $this->hours(200000);
        $this->incident(['outcome' => 'FIRST_AID', 'treatment_level' => 'FIRST_AID']);
        $this->incident(['outcome' => 'MEDICAL_TREATMENT', 'treatment_level' => 'MEDICAL_TREATMENT']);

        $lagging = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['lagging'];

        $this->assertSame(1, $lagging['first_aid']);
        $this->assertSame(1, $lagging['recordable_cases'], 'First aid only does not count as recordable.');
    }

    public function test_incidents_outside_the_period_are_excluded(): void
    {
        $this->hours(200000);
        $this->incident(['occurred_at' => Carbon::create($this->year, $this->month, 15)]);
        $this->incident(['occurred_at' => Carbon::create($this->year, $this->month + 1, 2)]);

        $lagging = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['lagging'];

        $this->assertSame(1, $lagging['total_incidents']);
    }

    // ── Leading indicators ──────────────────────────────────────────────────────────────────────

    public function test_the_near_miss_ratio_and_hazard_rate_are_reported(): void
    {
        $this->hours(200000, 'PAYROLL', headcount: 50);

        $this->incident(['incident_type' => 'NEAR_MISS', 'outcome' => 'NO_INJURY']);
        $this->incident(['incident_type' => 'NEAR_MISS', 'outcome' => 'NO_INJURY']);
        $this->incident(['incident_type' => 'NEAR_MISS', 'outcome' => 'NO_INJURY']);
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME']);

        DB::table('sheq_hazards')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
            'reference' => 'HAZ-'.Str::random(6), 'site_id' => $this->siteId,
            'description' => 'Loose handrail', 'status' => 'OPEN',
            'reported_at' => Carbon::create($this->year, $this->month, 5),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $leading = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['leading'];

        $this->assertSame(3.0, $leading['near_miss_ratio'], '3 near misses per recordable case.');
        $this->assertSame(1, $leading['hazards_reported']);
        $this->assertSame(2.0, $leading['hazard_reporting_rate_per_100'], '1 hazard from 50 people.');
    }

    public function test_overdue_actions_and_capa_effectiveness_are_reported(): void
    {
        $this->hours(200000);

        foreach ([['OPEN', '-10 days', null], ['OPEN', '+10 days', null],
            ['VERIFIED_CLOSED', '-30 days', 'EFFECTIVE'],
            ['VERIFIED_CLOSED', '-30 days', 'NOT_EFFECTIVE']] as [$status, $due, $effectiveness]) {
            DB::table('sheq_actions')->insert([
                'uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'reference' => 'ACT-'.Str::random(8), 'title' => 'Fix it', 'site_id' => $this->siteId,
                'status' => $status, 'priority' => 'MEDIUM',
                'due_date' => Carbon::parse($due)->toDateString(),
                'effectiveness_result' => $effectiveness,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $leading = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['leading'];

        $this->assertSame(2, $leading['actions_open']);
        $this->assertSame(1, $leading['actions_overdue']);
        $this->assertSame(50.0, $leading['actions_overdue_percent']);
        $this->assertSame(50.0, $leading['capa_effectiveness_percent'], '1 of 2 reviewed was effective.');
    }

    public function test_statutory_obligations_met_on_time_is_reported(): void
    {
        $this->hours(200000);

        DB::table('sheq_statutory_obligations')->insert([
            ['uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'obligation_def_id' => 'wcl_submission_7d', 'label' => 'W.Cl.2',
                'subject_type' => 'INCIDENT', 'subject_id' => 1,
                'regulatory_pack_id' => 'za-ohsa', 'regulatory_pack_version' => '2026.1.0',
                'anchor_at' => Carbon::create($this->year, $this->month, 1),
                'due_at' => Carbon::create($this->year, $this->month, 8),
                'submitted_at' => Carbon::create($this->year, $this->month, 6),   // on time
                'status' => 'SUBMITTED', 'created_at' => now(), 'updated_at' => now()],
            ['uuid' => (string) Str::uuid(), 'company_id' => $this->companyId,
                'obligation_def_id' => 'annexure1_record', 'label' => 'Annexure 1',
                'subject_type' => 'INCIDENT', 'subject_id' => 2,
                'regulatory_pack_id' => 'za-ohsa', 'regulatory_pack_version' => '2026.1.0',
                'anchor_at' => Carbon::create($this->year, $this->month, 1),
                'due_at' => Carbon::create($this->year, $this->month, 8),
                'submitted_at' => Carbon::create($this->year, $this->month, 20),  // late
                'status' => 'SUBMITTED', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $leading = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['leading'];

        $this->assertSame(2, $leading['obligations_due']);
        $this->assertSame(1, $leading['obligations_met_on_time']);
        $this->assertSame(50.0, $leading['obligations_on_time_percent'],
            'A late submission is not "met" — hiding that would make the register lie.');
    }

    // ── Exposure honesty (SHEQ-DASH-4) ──────────────────────────────────────────────────────────

    public function test_hand_entered_hours_mark_the_period_as_estimated(): void
    {
        $this->hours(200000, 'MANUAL');

        $result = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId);

        $this->assertTrue($result['exposure_estimated']);
        $this->assertTrue((bool) DB::table('sheq_kpi_snapshots')
            ->where('period_month', $this->month)->value('exposure_estimated'));
    }

    public function test_payroll_hours_are_not_estimated(): void
    {
        $this->hours(200000, 'PAYROLL');

        $this->assertFalse(
            $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['exposure_estimated']
        );
    }

    public function test_a_period_with_no_hours_captured_is_treated_as_estimated(): void
    {
        // Absent is not the same as measured, and must not read as a payroll figure.
        $result = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId);

        $this->assertTrue($result['exposure_estimated']);
        $this->assertFalse($result['inputs']['exposure']['captured']);
    }

    // ── The projection (SHEQ-ARC-4) ─────────────────────────────────────────────────────────────

    public function test_dashboards_read_a_stored_snapshot_with_its_lag(): void
    {
        $this->hours(500000);
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME']);

        $this->assertNull($this->service()->snapshot($this->companyId, $this->year, $this->month, $this->siteId));

        $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId);
        $snapshot = $this->service()->snapshot($this->companyId, $this->year, $this->month, $this->siteId);

        $this->assertNotNull($snapshot);
        $this->assertSame(2.0, $snapshot['metrics']['rates']['LTIFR']['value']);
        $this->assertNotNull($snapshot['pack'], 'The snapshot records which pack computed it.');
        $this->assertLessThan(30, $snapshot['lag_seconds'], 'Projection lag must be visible.');
    }

    public function test_recomputing_a_period_replaces_rather_than_duplicates_the_snapshot(): void
    {
        $this->hours(500000);
        $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId);

        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME']);
        $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId);

        $this->assertSame(1, DB::table('sheq_kpi_snapshots')
            ->where('company_id', $this->companyId)->where('period_year', $this->year)
            ->where('period_month', $this->month)->where('site_id', $this->siteId)->count());

        $this->assertSame(2.0, $this->service()
            ->snapshot($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['rates']['LTIFR']['value']);
    }

    public function test_every_rate_carries_the_inputs_it_was_computed_from(): void
    {
        // A KPI whose numerator cannot be shown is not auditable.
        $this->hours(400000);
        $this->incident(['outcome' => 'LOST_TIME', 'treatment_level' => 'LOST_TIME']);

        $rate = $this->service()->computePeriod($this->companyId, $this->year, $this->month, $this->siteId)['metrics']['rates']['LTIFR'];

        $this->assertSame(1, $rate['numerator']);
        $this->assertSame(400000.0, $rate['denominator']);
        $this->assertSame(2.5, $rate['value']);
    }
}
