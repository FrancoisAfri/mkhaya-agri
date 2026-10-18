<?php

namespace Tests\Feature\Payroll;

use App\Modules\HR\Payroll\Enums\PayFrequency;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One company, several pay frequencies — and nobody paid twice for the same days.
 *
 * The direct payroll assumed monthly: `payroll_periods.frequency` existed but no employee carried
 * one, so every run swept up everybody. The bureau had the mirror-image gap — the field existed
 * per employee and nothing read it. Either way a weekly run paid monthly staff a month early.
 */
class PayFrequencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_frequency_decides_its_own_pay_period(): void
    {
        $wednesday = Carbon::parse('2026-09-16');

        [$start, $end] = PayFrequency::Weekly->periodFor($wednesday);
        $this->assertSame('2026-09-14', $start->toDateString(), 'A wage week runs Monday to Sunday.');
        $this->assertSame('2026-09-20', $end->toDateString());

        [$start, $end] = PayFrequency::Monthly->periodFor($wednesday);
        $this->assertSame('2026-09-01', $start->toDateString());
        $this->assertSame('2026-09-30', $end->toDateString());
    }

    /**
     * The boundary must not move. Deriving a fortnight as "two weeks back from today" shifts it
     * every time it is asked, so two runs a fortnight apart would overlap by a day or skip one.
     */
    public function test_every_day_of_a_fortnight_resolves_to_the_same_period(): void
    {
        $periods = [];

        for ($i = 0; $i < 14; $i++) {
            [$s, $e] = PayFrequency::Fortnightly->periodFor(Carbon::parse('2026-09-14')->addDays($i));
            $periods[$s->toDateString() . '..' . $e->toDateString()] = true;
        }

        $this->assertCount(1, $periods, 'The fortnight boundary drifted: ' . implode(' | ', array_keys($periods)));
    }

    /**
     * PAYE on a weekly wage is annualised by 52, not 12. An employee paid fortnightly but taxed
     * as though monthly is taxed wrongly in a way nobody notices until SARS reconciles.
     */
    public function test_each_frequency_annualises_on_its_own_number_of_periods(): void
    {
        $this->assertSame(52, PayFrequency::Weekly->periodsPerYear());
        $this->assertSame(26, PayFrequency::Fortnightly->periodsPerYear());
        $this->assertSame(12, PayFrequency::Monthly->periodsPerYear());
    }

    /**
     * Quietly substituting monthly is what kept the original fortnightly bug invisible — the
     * payslip looked ordinary and the PAYE was simply wrong. A calculation gets null and has to
     * decide; only a screen gets a fallback.
     */
    public function test_an_unrecognised_frequency_is_refused_rather_than_assumed(): void
    {
        $this->assertNull(PayFrequency::tryParse('bi-weekly-ish'));
        $this->assertNull(PayFrequency::tryParse(null));

        $this->assertSame(PayFrequency::Weekly, PayFrequency::tryParse('weekly'));
        // Legacy spellings still resolve, via the tax engine's own alias table.
        $this->assertSame(PayFrequency::Fortnightly, PayFrequency::tryParse('bi-weekly'));
        $this->assertSame(PayFrequency::Fortnightly, PayFrequency::tryParse('biweekly'));

        // A list cannot stop rendering over one blank field, so display falls back and logs it.
        $this->assertSame(PayFrequency::Monthly, PayFrequency::forDisplay('nonsense'));
    }

    /** One table of periods, not two — the tax engine's, which the PAYE calculation already uses. */
    public function test_the_annualisation_comes_from_the_tax_engine(): void
    {
        foreach (PayFrequency::cases() as $frequency) {
            $this->assertSame(
                \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::periodsFor($frequency->value),
                $frequency->periodsPerYear(),
            );
        }
    }

    // ── The guard that matters ───────────────────────────────────────────────

    public function test_both_payrolls_carry_a_frequency(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('hr_people', 'pay_frequency'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('payroll_runs', 'frequency'));
        // The bureau already had these; the point is that both sides can now express the same idea.
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('bureau_employees', 'pay_frequency'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('bureau_pay_runs', 'frequency'));
    }

    /**
     * With several frequencies live in the same month, the weekly and the monthly run both exist
     * — and an employee on the wrong one lands in both. payslips was unique on
     * (payroll_run_id, employee_id), which stops a duplicate inside one run and permits as many
     * runs over the same dates as somebody cares to create.
     */
    public function test_an_employee_cannot_be_paid_twice_for_overlapping_periods(): void
    {
        $employee = DB::table('hr_people')->insertGetId([
            'first_name' => 'Nomsa', 'surname' => 'Dlamini', 'status' => 1,
            'pay_frequency' => 'monthly', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $monthly = $this->payrollRun('monthly');
        $weekly  = $this->payrollRun('weekly');

        $this->payslip($monthly, $employee, '2026-09-01', '2026-09-30');

        try {
            $this->payslip($weekly, $employee, '2026-09-14', '2026-09-20');
            $this->fail('A second payslip covering days already paid was accepted.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('payslips_no_overlapping_period', $e->getMessage());
        }
    }

    public function test_a_different_period_is_still_allowed(): void
    {
        $employee = DB::table('hr_people')->insertGetId([
            'first_name' => 'Thabo', 'surname' => 'Mokoena', 'status' => 1,
            'pay_frequency' => 'weekly', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = $this->payrollRun('weekly');

        $this->payslip($run, $employee, '2026-09-07', '2026-09-13');
        $this->payslip($this->payrollRun('weekly'), $employee, '2026-09-14', '2026-09-20');

        // Two weeks, two payslips, no overlap — the guard must not stop ordinary weekly payroll.
        $this->assertSame(2, DB::table('payslips')->where('employee_id', $employee)->count());
    }

    public function test_the_bureau_cannot_add_the_same_employee_to_a_run_twice(): void
    {
        $run = DB::table('bureau_pay_runs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'bureau_company_id' => $this->bureauCompany(),
            'name' => 'Weekly ' . Str::random(5), 'frequency' => 'weekly', 'status' => 'draft',
            'tax_year' => 2027,
            'period_start' => '2026-09-14', 'period_end' => '2026-09-20',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $employee = DB::table('bureau_employees')->insertGetId([
            'uuid' => (string) Str::uuid(), 'bureau_company_id' => $this->bureauCompany(),
            'first_name' => 'Sipho', 'last_name' => 'Khumalo',
            'pay_frequency' => 'weekly', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $add = fn () => DB::table('bureau_pay_run_employees')->insert([
            'bureau_pay_run_id' => $run, 'bureau_employee_id' => $employee,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $add();

        // There was no unique here at all, so one import running twice paid somebody twice out of
        // a single file.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $add();
    }

    private function payrollRun(string $frequency): int
    {
        return DB::table('payroll_runs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => ucfirst($frequency) . ' ' . Str::random(5),
            'frequency' => $frequency, 'status' => 'draft',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'created_by' => $this->someone(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A user id to own the rows — payroll_runs insists on knowing who made a run, rightly. */
    private function someone(): int
    {
        return DB::table('users')->value('id')
            ?? \App\Core\User\Models\User::factory()->create()->id;
    }

    private function bureauCompany(): int
    {
        return DB::table('bureau_companies')->value('id') ?? DB::table('bureau_companies')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Probe Client ' . Str::random(4),
            // A bureau client belongs to the agency that services it.
            'agent_id' => $this->someone(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payslip(int $run, int $employee, string $start, string $end): void
    {
        DB::table('payslips')->insert([
            'uuid' => (string) Str::uuid(), 'payroll_run_id' => $run, 'employee_id' => $employee,
            'status' => 'draft', 'period_start' => $start, 'period_end' => $end,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
