<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Payslip;
use App\Modules\HR\Payroll\Services\OvertimeCalculator;
use App\Modules\HR\Payroll\Services\PayrollService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Overtime at the rates the BCEA sets.
 *
 * The module could detect an underpayment and not produce a correct payment: overtime arrived as an
 * amount somebody typed in, and the only rate anywhere was one flat setting. §10 wants one and a
 * half times, §16 double for a Sunday, §18 double for a public holiday.
 */
class OvertimeCalculationTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private User $user;
    private int $personId;

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

        $this->user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'ot+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        $this->personId = (int) DB::table('hr_people')->insertGetId([
            'user_id' => $this->user->id, 'first_name' => 'Sipho', 'surname' => 'Dube',
            'company_id' => $this->companyId,
            'second_manager_id' => 0, 'eligible_to_drive' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** R16 000 a month over 160 standard hours is exactly R100/hr, which keeps the sums readable. */
    private function payslip(float $salary = 16000): Payslip
    {
        $run = PayrollRun::create([
            'uuid' => (string) Str::uuid(), 'name' => 'March 2026',
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'status' => 'draft', 'employee_count' => 1, 'created_by' => $this->user->id,
            'company_id' => $this->companyId,
        ]);

        return Payslip::create([
            'uuid' => (string) Str::uuid(), 'payroll_run_id' => $run->id, 'employee_id' => $this->user->id,
            'status' => 'draft', 'basic_salary' => $salary, 'gross_pay' => $salary,
            'total_deductions' => 0, 'net_pay' => $salary, 'paye' => 0, 'uif' => 0, 'pension' => 0,
            'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
            'company_id' => $this->companyId,
        ]);
    }

    private function attendance(string $date, float $hours, float $overtime = 0): void
    {
        DB::table('attendance_records')->insert([
            'employee_id' => $this->personId, 'date' => $date,
            'hours_worked' => $hours, 'overtime_hours' => $overtime,
            'company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_overtime_is_paid_at_one_and_a_half_times(): void
    {
        $payslip = $this->payslip();
        $this->attendance('2026-03-02', 8, 2);   // a Monday

        $result = app(OvertimeCalculator::class)->forPayslip($payslip);

        // R100/hr × 1.5 × 2 hrs = R300. BCEA §10.
        $this->assertSame(100.0, $result['rate']);
        $this->assertSame(300.0, $result['total']);
    }

    public function test_sunday_work_is_paid_at_double(): void
    {
        $payslip = $this->payslip();
        $this->attendance('2026-03-01', 6);   // a Sunday

        $result = app(OvertimeCalculator::class)->forPayslip($payslip);

        // R100 × 2 × 6 = R1 200. BCEA §16.
        $this->assertSame(1200.0, $result['total']);
        $this->assertSame('sunday', $result['lines'][0]['type']);
    }

    public function test_ordinary_weekday_hours_earn_no_premium(): void
    {
        $payslip = $this->payslip();
        $this->attendance('2026-03-03', 8);   // a Tuesday, no overtime

        $result = app(OvertimeCalculator::class)->forPayslip($payslip);

        // They are already in the salary; paying them again would double the basic.
        $this->assertSame(0.0, $result['total']);
        $this->assertSame(8.0, $result['ordinary_hours']);
    }

    public function test_a_public_holiday_on_a_sunday_pays_one_premium_not_two(): void
    {
        $payslip = $this->payslip();

        DB::table('public_holidays')->insert([
            'name' => 'Test holiday', 'day' => '03-01', 'year' => 2026,
            'company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->attendance('2026-03-01', 6);   // a Sunday that is also a public holiday

        $result = app(OvertimeCalculator::class)->forPayslip($payslip);

        // Counted once, in the category it qualifies for — not 2× on top of 2×.
        $this->assertSame(1200.0, $result['total']);
        $this->assertCount(1, $result['lines']);
        $this->assertSame('public_holiday', $result['lines'][0]['type']);
    }

    public function test_the_three_categories_add_up_separately(): void
    {
        $payslip = $this->payslip();
        $this->attendance('2026-03-02', 8, 2);   // Monday + 2 hrs overtime  → 300
        $this->attendance('2026-03-08', 4);      // Sunday, 4 hrs            → 800

        $result = app(OvertimeCalculator::class)->forPayslip($payslip);

        $this->assertSame(1100.0, $result['total']);
        $this->assertEqualsCanonicalizing(['overtime', 'sunday'], array_column($result['lines'], 'type'));
    }

    public function test_applying_it_twice_does_not_double_the_pay(): void
    {
        $payslip = $this->payslip();
        $this->attendance('2026-03-02', 8, 2);

        $calculator = app(OvertimeCalculator::class);
        $calculator->applyToPayslip($payslip);
        $calculator->applyToPayslip($payslip);

        // Correcting a timesheet and pressing again must correct the pay, not add to it.
        $lines = $payslip->earningsLines()->where('source', 'attendance')->get();
        $this->assertCount(1, $lines);
        $this->assertSame(300.0, (float) $lines->first()->amount);
    }

    public function test_a_line_somebody_typed_in_is_never_removed(): void
    {
        $payslip = $this->payslip();
        $this->attendance('2026-03-02', 8, 2);

        $payslip->earningsLines()->create([
            'line_type' => 'overtime', 'description' => 'Agreed callout, entered by hand',
            'amount' => 500, 'quantity' => 1, 'source' => 'manual',
        ]);

        app(OvertimeCalculator::class)->applyToPayslip($payslip);

        // It is not the calculator's to remove.
        $this->assertSame(1, $payslip->earningsLines()->where('source', 'manual')->count());
        $this->assertSame(1, $payslip->earningsLines()->where('source', 'attendance')->count());
    }

    public function test_no_attendance_means_no_lines_rather_than_zeros(): void
    {
        $result = app(OvertimeCalculator::class)->forPayslip($this->payslip());

        // A salaried employee with no timesheet should not get an overtime line of R0.
        $this->assertSame([], $result['lines']);
        $this->assertSame(0.0, $result['total']);
    }

    public function test_the_payslip_hours_block_stays_hidden_without_attendance(): void
    {
        // §33(1)(g) applies "if relevant to the calculation of pay". Printing zeros at somebody on a
        // fixed salary is worse than saying nothing.
        $this->assertSame([], app(PayrollService::class)->hoursFor($this->payslip()));
    }
}
