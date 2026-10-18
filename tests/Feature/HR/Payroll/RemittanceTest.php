<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Models\Beneficiary;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Remittance;
use App\Modules\HR\Payroll\Services\RemittanceService;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Paying over what was deducted.
 *
 * Medical aid, provident, union dues and garnishees came off the payslip and stopped there. Net
 * pay was right, the fund never heard from us, and "did September's provident payment go?" could
 * only be answered by the bank. Holding money on somebody else's behalf without a record of
 * paying it across is how an employer ends up in front of a determination.
 */
class RemittanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $companyId;
    private int $authorId;

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

        $this->authorId = (int) User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => 'rem+' . Str::random(8) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ])->id;
    }

    private function payRun(): PayrollRun
    {
        return PayrollRun::create([
            'uuid' => (string) Str::uuid(), 'name' => 'September', 'company_id' => $this->companyId,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'status' => 'draft', 'created_by' => $this->authorId,
        ]);
    }

    private function beneficiary(array $attributes = []): Beneficiary
    {
        return Beneficiary::create(array_merge([
            'company_id' => $this->companyId, 'name' => 'Discovery Health', 'type' => 'medical_aid',
            'line_types' => ['medical_aid', 'employer_medical'],
            'bank_name' => 'FNB', 'branch_code' => '250655', 'account_number' => '62000111222',
            'active' => true,
        ], $attributes));
    }

    /** A payslip with deduction lines on it. */
    private function payslip(PayrollRun $run, string $name, array $lines): int
    {
        $user = User::forceCreate([
            'uuid' => (string) Str::uuid(), 'email' => Str::slug($name) . Str::random(4) . '@example.test',
            'password' => bcrypt('secret'), 'status' => 1, 'company_id' => $this->companyId,
        ]);

        DB::table('hr_people')->insert([
            'user_id' => $user->id, 'company_id' => $this->companyId,
            'first_name' => $name, 'surname' => 'Mokoena', 'employee_number' => 'E' . random_int(1000, 9999),
            'id_number' => (string) random_int(8001010000000, 9912319999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $payslipId = DB::table('payslips')->insertGetId([
            'uuid' => (string) Str::uuid(), 'payroll_run_id' => $run->id, 'employee_id' => $user->id,
            'status' => 'draft', 'basic_salary' => 20000, 'gross_pay' => 20000,
            'total_deductions' => 0, 'net_pay' => 20000,
            'period_start' => $run->period_start, 'period_end' => $run->period_end,
            'company_id' => $this->companyId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($lines as $type => $amount) {
            DB::table('payroll_earnings_lines')->insert([
                'payslip_id' => $payslipId, 'line_type' => $type, 'amount' => $amount,
                'description' => $type, 'company_id' => $this->companyId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $payslipId;
    }

    // ── What is owed ─────────────────────────────────────────────────────────

    public function test_a_run_produces_what_each_fund_is_owed(): void
    {
        $run = $this->payRun();
        $this->beneficiary();
        $this->payslip($run, 'Thandi', ['medical_aid' => 1800, 'employer_medical' => 1800]);
        $this->payslip($run, 'Sipho', ['medical_aid' => 1200, 'employer_medical' => 1200]);

        $result = app(RemittanceService::class)->buildForRun($run);

        $this->assertSame(1, $result['created']);
        $remittance = Remittance::first();
        $this->assertEqualsWithDelta(3000, (float) $remittance->employee_portion, 0.01);
        $this->assertEqualsWithDelta(3000, (float) $remittance->employer_portion, 0.01);
        $this->assertEqualsWithDelta(6000, (float) $remittance->total, 0.01);
        $this->assertSame(2, (int) $remittance->employee_count);
    }

    public function test_the_schedule_names_who_the_money_was_for(): void
    {
        $run = $this->payRun();
        $this->beneficiary();
        $this->payslip($run, 'Thandi', ['medical_aid' => 1800, 'employer_medical' => 1800]);

        app(RemittanceService::class)->buildForRun($run);
        $schedule = Remittance::first()->schedule;

        // A fund querying a payment asks who it was for, and rebuilding that from payslips months
        // later is a bad afternoon.
        $this->assertCount(1, $schedule);
        $this->assertSame('Thandi Mokoena', $schedule[0]['name']);
        $this->assertEqualsWithDelta(1800, $schedule[0]['employee'], 0.01);
        $this->assertEqualsWithDelta(1800, $schedule[0]['employer'], 0.01);
    }

    public function test_employee_and_employer_portions_are_kept_apart(): void
    {
        $run = $this->payRun();
        $this->beneficiary(['line_types' => ['pension', 'employer_pension'], 'type' => 'provident', 'name' => 'Old Mutual']);
        $this->payslip($run, 'Lerato', ['pension' => 1000, 'employer_pension' => 1500]);

        app(RemittanceService::class)->buildForRun($run);
        $remittance = Remittance::first();

        // The two are not interchangeable when a payment is queried.
        $this->assertEqualsWithDelta(1000, (float) $remittance->employee_portion, 0.01);
        $this->assertEqualsWithDelta(1500, (float) $remittance->employer_portion, 0.01);
    }

    public function test_a_fund_owed_nothing_this_run_gets_no_remittance(): void
    {
        $run = $this->payRun();
        $this->beneficiary(['name' => 'Union', 'type' => 'union', 'line_types' => ['other_deduction']]);
        $this->payslip($run, 'Nomsa', ['medical_aid' => 900]);

        app(RemittanceService::class)->buildForRun($run);

        $this->assertSame(0, Remittance::count());
    }

    // ── Recalculating ────────────────────────────────────────────────────────

    public function test_rebuilding_updates_rather_than_duplicating(): void
    {
        $run = $this->payRun();
        $this->beneficiary();
        $this->payslip($run, 'Thandi', ['medical_aid' => 1800]);

        app(RemittanceService::class)->buildForRun($run);
        $second = app(RemittanceService::class)->buildForRun($run);

        $this->assertSame(1, Remittance::count());
        $this->assertSame(1, $second['updated']);
    }

    public function test_a_remittance_already_paid_is_never_silently_rewritten(): void
    {
        $run = $this->payRun();
        $this->beneficiary();
        $this->payslip($run, 'Thandi', ['medical_aid' => 1800]);
        app(RemittanceService::class)->buildForRun($run);

        $remittance = Remittance::first();
        app(RemittanceService::class)->markPaid($remittance, 'EFT-99', $this->authorId);

        // A later recalculation that disagrees is a discrepancy to surface, not a number to
        // overwrite — the money has already left.
        $result = app(RemittanceService::class)->buildForRun($run);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame('paid', $remittance->fresh()->status);
        $this->assertSame('EFT-99', $remittance->fresh()->payment_reference);
    }

    // ── Chasing it ───────────────────────────────────────────────────────────

    public function test_outstanding_lists_what_has_not_been_paid(): void
    {
        $run = $this->payRun();
        $this->beneficiary();
        $this->payslip($run, 'Thandi', ['medical_aid' => 1800]);
        app(RemittanceService::class)->buildForRun($run);

        $this->assertCount(1, app(RemittanceService::class)->outstanding());

        app(RemittanceService::class)->markPaid(Remittance::first(), 'EFT-1', $this->authorId);
        $this->assertCount(0, app(RemittanceService::class)->outstanding());
    }

    public function test_a_due_date_that_has_passed_is_overdue(): void
    {
        $run = $this->payRun();
        $this->beneficiary(['pay_by_day' => 7]);
        $this->payslip($run, 'Thandi', ['medical_aid' => 1800]);
        app(RemittanceService::class)->buildForRun($run);

        $remittance = Remittance::first();
        $remittance->update(['due_on' => now()->subWeek()->toDateString()]);

        $this->assertTrue($remittance->fresh()->isOverdue());
    }

    // ── The file the fund asks for ───────────────────────────────────────────

    public function test_the_schedule_exports_with_a_total_that_agrees(): void
    {
        $run = $this->payRun();
        $this->beneficiary();
        $this->payslip($run, 'Thandi', ['medical_aid' => 1800, 'employer_medical' => 1800]);
        app(RemittanceService::class)->buildForRun($run);

        $csv = app(RemittanceService::class)->toCsv(Remittance::first());

        $this->assertStringContainsString('Thandi Mokoena', $csv);
        $this->assertStringContainsString('"TOTAL"', $csv);
        $this->assertStringContainsString('3600.00', $csv);
    }

    public function test_a_member_number_cannot_execute_when_the_fund_opens_the_file(): void
    {
        $run = $this->payRun();
        $beneficiary = $this->beneficiary();
        $this->payslip($run, 'Thandi', ['medical_aid' => 100]);
        app(RemittanceService::class)->buildForRun($run);

        $remittance = Remittance::first();
        $schedule = $remittance->schedule;
        $schedule[0]['employee_number'] = '=cmd|calc';
        $remittance->update(['schedule' => $schedule]);

        // Excel executes a cell beginning with = when the fund opens the file.
        $this->assertStringContainsString("\"'=cmd|calc\"", app(RemittanceService::class)->toCsv($remittance->fresh()));
    }

    public function test_banking_details_are_required_before_a_fund_can_be_paid(): void
    {
        $payable = $this->beneficiary();
        $notPayable = $this->beneficiary(['name' => 'No bank', 'account_number' => null, 'branch_code' => null]);

        $this->assertTrue($payable->isPayable());
        $this->assertFalse($notPayable->isPayable());
    }
}
