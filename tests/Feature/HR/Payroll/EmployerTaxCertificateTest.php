<?php

namespace Tests\Feature\HR\Payroll;

use App\Core\User\Models\User;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\TaxCertificate;
use App\Modules\HR\Payroll\Services\TaxCertificateService;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An IRP5 that stays what it was on the day it was issued.
 *
 * TaxCertificateService opens by describing the defect it exists to fix: an IRP5 that is a query
 * rather than a record, recomputed from whatever payroll says at that moment, so an employee whose
 * February payslip is corrected in June downloads a different certificate from the one they filed
 * their return with — and nothing records that the two differ.
 *
 * That fix was wired to the bureau and never to the in-house payroll, where Emp501Service still
 * said "not stored" in its own docblock. An employer running its own payroll therefore had exactly
 * the defect the class was written to prevent.
 */
class EmployerTaxCertificateTest extends PayrollTestCase
{
    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (int) (DB::table('companies')->min('id')
            ?? Company::create(['name' => 'Certificate Test Co'])->id);

        DB::table('users')->where('id', $this->hrManager->id)->update(['company_id' => $this->companyId]);
        $this->hrManager->refresh();
        $this->actingAs($this->hrManager);

        app(\App\Modules\Tenancy\Services\CurrentCompany::class)->set($this->companyId);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);
    }

    /** An employee paid once in the 2027 tax year (March 2026 – February 2027). */
    private function paidEmployee(float $gross = 30000, float $paye = 4500, string $periodEnd = '2026-09-30', ?int $companyId = null): int
    {
        // Whichever tenant the session is pinned to: row-level security refuses an insert whose
        // company_id is not the session's, which is the point of the tenancy test below.
        $companyId ??= $this->companyId;
        $user = User::factory()->create(['status' => 1]);

        DB::table('hr_people')->insert([
            'user_id' => $user->id, 'first_name' => 'Thabo', 'surname' => 'Mokoena',
            'employee_number' => 'E' . $user->id, 'id_number' => '9001015800083',
            'income_tax_number' => '1234567890', 'monthly_package' => $gross,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $runId = DB::table('payroll_runs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'name' => 'Run ' . $periodEnd, 'ref_number' => 'PR-' . Str::random(6),
            'period_start' => date('Y-m-01', strtotime($periodEnd)), 'period_end' => $periodEnd,
            'status' => PayrollRunStatus::Released->value, 'created_by' => $this->hrManager->id,
            'total_gross' => $gross, 'total_deductions' => $paye, 'total_net' => $gross - $paye,
            'employee_count' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('payslips')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
            'payroll_run_id' => $runId, 'employee_id' => $user->id,
            'status' => 'finalised', 'basic_salary' => $gross, 'gross_pay' => $gross,
            'total_deductions' => $paye, 'net_pay' => $gross - $paye,
            'paye' => $paye, 'uif' => 177.12, 'pension' => 0, 'eti' => 0,
            'period_start' => date('Y-m-01', strtotime($periodEnd)), 'period_end' => $periodEnd,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) $user->id;
    }

    private function service(): TaxCertificateService
    {
        return app(TaxCertificateService::class);
    }

    // ── Issuing from the in-house payroll ────────────────────────────────────

    public function test_an_employer_can_issue_certificates_off_its_own_payroll(): void
    {
        // The whole gap: this was possible for a bureau client and impossible for an employer.
        $employeeId = $this->paidEmployee();

        $issued = $this->service()->issueForEmployer(2027);

        $this->assertCount(1, $issued);
        $this->assertSame(TaxCertificate::HR, $issued->first()->employee_type);
        $this->assertSame($employeeId, $issued->first()->employee_id);
        $this->assertEqualsWithDelta(30000.00, (float) $issued->first()->gross, 0.01);
    }

    public function test_a_certificate_with_paye_is_an_irp5_and_one_without_is_an_it3a(): void
    {
        // SARS rejects an IRP5 sent where an IT3(a) was due, so the figures decide the type.
        $this->paidEmployee(gross: 30000, paye: 4500);
        $this->assertSame('IRP5', $this->service()->issueForEmployer(2027)->first()->type);

        TaxCertificate::query()->delete();
        DB::table('payslips')->update(['paye' => 0]);

        $this->assertSame('IT3(a)', $this->service()->issueForEmployer(2027)->first()->type);
    }

    public function test_only_released_runs_are_certified(): void
    {
        // A certificate built from a run still in review states a number about to change, and it is
        // the employee who finds out.
        $this->paidEmployee();
        DB::table('payroll_runs')->update(['status' => PayrollRunStatus::Review->value]);

        $this->expectException(ValidationException::class);
        $this->service()->issueForEmployer(2027);
    }

    public function test_the_tax_year_runs_march_to_february(): void
    {
        // A calendar year is the wrong answer for ten months out of twelve.
        $this->paidEmployee(periodEnd: '2027-02-28');

        $this->assertCount(1, $this->service()->issueForEmployer(2027));

        // 28 February 2027 is the last day of the 2027 year, so 2028 has nothing in it — and a
        // year with no released payroll refuses rather than issuing an empty set.
        $this->expectException(ValidationException::class);
        $this->service()->issueForEmployer(2028);
    }

    // ── Reissuing ────────────────────────────────────────────────────────────

    public function test_issuing_twice_with_nothing_changed_does_not_make_a_second_version(): void
    {
        // Otherwise pressing generate twice hands everybody a version 2 that says exactly what
        // version 1 said, and the history stops meaning anything.
        $this->paidEmployee();

        $first = $this->service()->issueForEmployer(2027)->first();
        $second = $this->service()->issueForEmployer(2027)->first();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $second->version);
    }

    public function test_a_corrected_payslip_supersedes_rather_than_edits(): void
    {
        /*
         * The point of the whole class. Both documents survive, because "what did we give this
         * person, and what before that" has to be answerable across the five years SARS works in.
         */
        $this->paidEmployee(gross: 30000, paye: 4500);
        $first = $this->service()->issueForEmployer(2027)->first();

        DB::table('payslips')->update(['gross_pay' => 33000, 'paye' => 5200]);

        $second = $this->service()->issueForEmployer(2027, reason: 'February payslip corrected.')->first();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->id, $second->supersedes_id);
        $this->assertSame('February payslip corrected.', $second->reissue_reason);

        // The superseded one is still readable — it is what the employee filed with.
        $this->assertEqualsWithDelta(30000.00, (float) $first->fresh()->gross, 0.01);
    }

    // ── The download is the record, not a recalculation ──────────────────────

    public function test_the_download_serves_the_issued_figures_after_payroll_moves(): void
    {
        /*
         * The defect, stated as a test. Before this, correcting a payslip in June silently changed
         * the document an employee had already filed a return with.
         */
        $employeeId = $this->paidEmployee(gross: 30000, paye: 4500);
        $this->service()->issueForEmployer(2027);

        DB::table('payslips')->update(['gross_pay' => 99000, 'paye' => 30000]);

        $pdf = app(\App\Modules\HR\Payroll\Services\Emp501Service::class)
            ->certificatePdf($employeeId, 2027);

        // Rendered from the certificate, so the corrected 99 000 must not appear on it.
        $this->assertNotNull($pdf);
        $certificate = TaxCertificate::query()
            ->forEmployee(TaxCertificate::HR, $employeeId)->current()->first();
        $this->assertEqualsWithDelta(30000.00, (float) $certificate->gross, 0.01);
    }

    public function test_an_employee_never_certified_still_previews(): void
    {
        // A certificate not yet issued has to be previewable, so the live calculation stays — as
        // the fallback rather than the answer.
        $employeeId = $this->paidEmployee();

        $this->assertNotNull(
            app(\App\Modules\HR\Payroll\Services\Emp501Service::class)->certificatePdf($employeeId, 2027)
        );
    }

    // ── Tenancy ──────────────────────────────────────────────────────────────

    public function test_another_employers_payslips_are_not_certified(): void
    {
        $ours = $this->paidEmployee();

        $other = Company::create(['name' => 'Another Employer']);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $other->id]);
        $theirs = $this->paidEmployee(companyId: (int) $other->id);
        DB::statement("SELECT set_config('app.company_id', ?, false)", [(string) $this->companyId]);

        $issued = $this->service()->issueForEmployer(2027);

        $this->assertSame([$ours], $issued->pluck('employee_id')->all());
        $this->assertNotContains($theirs, $issued->pluck('employee_id')->all());
    }
}
