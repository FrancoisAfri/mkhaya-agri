<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Services\EmploymentEquityService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Employment Equity return — EEA2 and EEA4.
 *
 * Payroll holds the remuneration half of the EEA4, and nothing assembled it. The risk in a report
 * like this is not that it fails; it is that it quietly places people in the wrong row, or drops
 * the ones it cannot place, and gets filed anyway.
 */
class EmploymentEquityTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private EmploymentEquityService $equity;
    /** @var array<string,int> */
    private array $runs = [];
    private ?int $author = null;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = DB::table('companies')->min('id');
        if ($companyId === null) {
            $this->markTestSkipped('No company in the test database.');
        }
        $this->companyId = (int) $companyId;
        app(CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $companyId]);

        // Nobody else's people may appear in the grid, so the window is cleared first.
        DB::table('hr_people')->where('company_id', $this->companyId)->delete();

        $this->equity = app(EmploymentEquityService::class);
    }

    private function ethnicity(string $name): int
    {
        return (int) (DB::table('ethnicities')->whereRaw('lower(value) = ?', [strtolower($name)])->value('id')
            ?? DB::table('ethnicities')->insertGetId(['value' => $name, 'status' => 1]));
    }

    /** @return int the user id, so a payslip can be hung off it */
    private function person(array $attributes): int
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ee+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        DB::table('hr_people')->insert(array_merge([
            'user_id' => $user->id, 'company_id' => $this->companyId,
            'first_name' => 'Test', 'surname' => 'Person',
            'citizenship' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));

        return (int) $user->id;
    }

    private function author(): int
    {
        return $this->author ??= (int) User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ee-run+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ])->id;
    }

    /** One run per period: a person cannot have two payslips in the same run. */
    private function runId(string $periodEnd): int
    {
        return $this->runs[$periodEnd] ??= (int) DB::table('payroll_runs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'EE ' . Str::random(5),
            'period_start' => date('Y-m-01', strtotime($periodEnd)), 'period_end' => $periodEnd, 'status' => 'draft',
            'created_by' => $this->author(), 'company_id' => $this->companyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function paid(int $userId, float $gross, string $periodEnd = '2026-06-30'): void
    {
        DB::table('payslips')->insert([
            'uuid' => (string) Str::uuid(), 'payroll_run_id' => $this->runId($periodEnd), 'employee_id' => $userId,
            'status' => 'draft', 'basic_salary' => $gross, 'gross_pay' => $gross,
            'total_deductions' => 0, 'net_pay' => $gross,
            'period_start' => date('Y-m-01', strtotime($periodEnd)), 'period_end' => $periodEnd,
            'company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── The grid ─────────────────────────────────────────────────────────────

    public function test_a_person_lands_in_the_cell_the_form_asks_for(): void
    {
        $this->person(['ethnicity' => $this->ethnicity('African'), 'gender' => 2, 'occupational_level' => 2]);

        $report = $this->equity->report(2026);

        // Middle management is the form's "professionally qualified" row; the people record's own
        // level names are not the Act's.
        $this->assertSame(1, $report['profile']['professionally_qualified']['african']['female']);
        $this->assertSame(1, $report['headcount']);
    }

    public function test_the_lookup_is_read_by_name_so_a_reseeded_id_cannot_reclassify_anybody(): void
    {
        // A row inserted with a fresh id — an installation that seeded the lookup in a different
        // order would report this person as somebody else entirely if the id were trusted.
        $id = DB::table('ethnicities')->insertGetId(['value' => 'Coloured ' . Str::random(3), 'status' => 1]);
        DB::table('ethnicities')->where('id', $id)->update(['value' => 'Coloured']);

        $this->person(['ethnicity' => $id, 'gender' => 1, 'occupational_level' => 5]);

        $this->assertSame(1, $this->equity->report(2026)['profile']['unskilled']['coloured']['male']);
    }

    public function test_a_person_who_cannot_be_placed_is_shown_rather_than_dropped(): void
    {
        $this->person(['first_name' => 'Unplaceable', 'surname' => 'Employee',
            'ethnicity' => null, 'gender' => null, 'occupational_level' => null]);

        $report = $this->equity->report(2026);

        // Dropping them would balance the form and understate the workforce, which is the failure
        // mode that gets an employer into trouble rather than out of it.
        $this->assertSame(1, $report['headcount']);
        $this->assertSame(1, $report['profile']['unclassified']['unclassified']['unclassified']);
        $this->assertFalse($report['data_quality']['complete']);
        $this->assertContains('Unplaceable Employee', $report['data_quality']['missing_race']);
        $this->assertContains('Unplaceable Employee', $report['data_quality']['missing_gender']);
        $this->assertContains('Unplaceable Employee', $report['data_quality']['missing_level']);
    }

    public function test_a_non_citizen_is_reported_as_a_foreign_national_not_as_a_population_group(): void
    {
        $this->person(['ethnicity' => $this->ethnicity('White'), 'gender' => 1,
            'occupational_level' => 1, 'citizenship' => 3]);

        $report = $this->equity->report(2026);

        $this->assertSame(1, $report['foreign']);
        $this->assertSame(1, $report['profile']['senior_management']['foreign']['male']);
        $this->assertSame(0, $report['profile']['senior_management']['white']['male']);
    }

    // ── The income half ──────────────────────────────────────────────────────

    public function test_remuneration_is_the_years_gross_pay_not_one_month(): void
    {
        $user = $this->person(['ethnicity' => $this->ethnicity('Indian'), 'gender' => 2, 'occupational_level' => 7]);
        $this->paid($user, 40000, '2026-06-30');
        $this->paid($user, 40000, '2026-07-31');

        $report = $this->equity->report(2026);

        $this->assertEqualsWithDelta(80000, $report['income']['skilled_technical']['indian']['female'], 0.01);
    }

    public function test_pay_outside_the_reporting_year_is_left_out(): void
    {
        $user = $this->person(['ethnicity' => $this->ethnicity('African'), 'gender' => 1, 'occupational_level' => 4]);
        $this->paid($user, 15000, '2026-06-30');

        // The reporting year runs 1 September to 31 August, so this one belongs to the next return.
        $this->paid($user, 99000, '2026-09-30');

        $this->assertEqualsWithDelta(15000, $this->equity->report(2026)['income']['semi_skilled']['african']['male'], 0.01);
    }

    public function test_an_employee_with_no_payslip_still_counts_in_the_headcount(): void
    {
        $this->person(['ethnicity' => $this->ethnicity('African'), 'gender' => 2, 'occupational_level' => 5]);

        $report = $this->equity->report(2026);

        $this->assertSame(1, $report['profile']['unskilled']['african']['female']);
        $this->assertEqualsWithDelta(0, $report['income']['unskilled']['african']['female'], 0.01);
    }

    // ── The differential, which is the point of the EEA4 ──────────────────────

    public function test_the_widest_gap_is_measured_between_groups_at_the_same_level(): void
    {
        $high = $this->person(['ethnicity' => $this->ethnicity('White'), 'gender' => 1, 'occupational_level' => 1]);
        $low = $this->person(['ethnicity' => $this->ethnicity('African'), 'gender' => 2, 'occupational_level' => 1]);
        $this->paid($high, 200000);
        $this->paid($low, 100000);

        $gap = $this->equity->report(2026)['differential']['widest_gap'];

        $this->assertSame('senior_management', $gap['level']);
        $this->assertSame('white:male', $gap['highest']);
        $this->assertSame('african:female', $gap['lowest']);
        $this->assertEqualsWithDelta(100.0, $gap['gap_percent'], 0.01);
    }

    public function test_one_group_at_a_level_is_not_a_gap(): void
    {
        $only = $this->person(['ethnicity' => $this->ethnicity('African'), 'gender' => 1, 'occupational_level' => 1]);
        $this->paid($only, 200000);

        // A level with nobody to compare against is not evidence of a differential, and reporting
        // it as one would put a number in front of a board that means nothing.
        $this->assertNull($this->equity->report(2026)['differential']['widest_gap']);
    }

    public function test_the_average_per_level_divides_by_everybody_at_that_level(): void
    {
        $a = $this->person(['ethnicity' => $this->ethnicity('African'), 'gender' => 1, 'occupational_level' => 3]);
        $b = $this->person(['ethnicity' => $this->ethnicity('White'), 'gender' => 2, 'occupational_level' => 3]);
        $this->paid($a, 60000);
        $this->paid($b, 40000);

        $this->assertEqualsWithDelta(
            50000,
            $this->equity->report(2026)['differential']['average_per_level']['skilled_technical'],
            0.01,
        );
    }

    // ── What comes out ───────────────────────────────────────────────────────

    public function test_the_csv_carries_only_the_cells_that_hold_somebody(): void
    {
        $user = $this->person(['ethnicity' => $this->ethnicity('African'), 'gender' => 2, 'occupational_level' => 5]);
        $this->paid($user, 48000);

        $csv = $this->equity->toCsv($this->equity->report(2026));

        $this->assertStringContainsString('unskilled,african,female,1,48000.00,48000.00', $csv);
        $this->assertStringNotContainsString('top_management', $csv);
    }
}
