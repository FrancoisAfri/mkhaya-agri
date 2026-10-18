<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayslip;
use App\Modules\HR\Payroll\Enums\EarningsLineType;
use App\Modules\HR\Payroll\Enums\PayrollRunStatus;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Models\TaxCertificate;
use App\Modules\HR\Payroll\Support\PayRunLifecycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues tax certificates, and keeps the ones already issued.
 *
 * Today an IRP5 is a query — press download and the totals are recomputed from whatever the payroll
 * says at that moment. That is right the first time and wrong every time after. An employee whose
 * February payslip is corrected in June gets a different certificate from the one they filed their
 * return with, and nothing records that the two differ.
 *
 * So issuing captures the figures, and everything downstream reads them back. A correction never
 * edits a certificate: it issues a new version that supersedes the old one, with a reason, and both
 * survive. SARS works in a five-year window; "what did we give this person, and what before that"
 * has to be answerable for all of it.
 *
 * Both engines. An employee who moved from a bureau client onto the in-house payroll has one tax
 * year, not two halves nobody can reconcile.
 */
class TaxCertificateService
{
    /** The SARS reconciliation periods: the interim to August, and the full year to February. */
    public const FULL_YEAR = '02';
    public const INTERIM = '08';

    /**
     * Issue certificates for every employee a client paid in the tax year.
     *
     * @return Collection<int, TaxCertificate>
     */
    public function issueForClient(BureauCompany $company, int $taxYear, string $half = self::FULL_YEAR, ?string $reason = null): Collection
    {
        $runs = $company->payRuns()
            ->where('tax_year', $taxYear)
            // Only runs whose figures are settled. A certificate built from a run still in review
            // states a number that is about to change, and it is the employee who finds out.
            ->whereIn('status', [PayRunLifecycle::FINALISED, PayRunLifecycle::PAID, PayRunLifecycle::COMPLETED])
            ->get();

        if ($runs->isEmpty()) {
            throw ValidationException::withMessages([
                'tax_year' => "No finalised pay runs for {$company->name} in the {$taxYear} tax year, so there is nothing to certify.",
            ]);
        }

        $slips = BureauPayslip::query()
            ->whereIn('bureau_pay_run_id', $runs->pluck('id'))
            ->with('employee')
            ->get()
            ->groupBy('bureau_employee_id');

        $issued = collect();

        foreach ($slips as $employeeId => $forEmployee) {
            $employee = $forEmployee->first()->employee;

            if (! $employee) {
                continue;
            }

            $issued->push($this->issue(
                TaxCertificate::BUREAU,
                (int) $employeeId,
                $taxYear,
                $half,
                $this->figuresFor($forEmployee, (int) $employeeId),
                $this->employeeSnapshot($employee),
                $this->employerSnapshot($company),
                $company->id,
                $reason,
            ));
        }

        return $issued;
    }

    /**
     * Issue certificates for every employee this employer paid in the tax year.
     *
     * The in-house half of the same job, and the half that was missing. issueForClient() gave the
     * bureau stored, versioned certificates; an employer running its own payroll still had
     * Emp501Service::certificatePdf(), which says "not stored" in its own docblock and recomputes
     * from live payslips on every download. That is precisely the defect this class was written to
     * fix — an employee whose February payslip is corrected in June receives a different certificate
     * from the one they filed with, and nothing anywhere records that the two differ.
     *
     * @return Collection<int, TaxCertificate>
     */
    public function issueForEmployer(int $taxYear, string $half = self::FULL_YEAR, ?string $reason = null): Collection
    {
        [$from, $to] = $this->taxYearBounds($taxYear);

        // Released runs only, for the same reason the bureau uses finalised ones: a certificate
        // built from a run still in review states a number that is about to change, and it is the
        // employee who finds out.
        $slips = DB::table('payslips as p')
            ->join('payroll_runs as r', 'r.id', '=', 'p.payroll_run_id')
            ->where('r.status', PayrollRunStatus::Released->value)
            ->whereNull('p.deleted_at')
            ->whereBetween('r.period_end', [$from, $to])
            ->where('p.company_id', $this->companyId())
            ->select('p.*', 'r.period_start as run_start', 'r.period_end as run_end')
            ->get()
            ->groupBy('employee_id');

        if ($slips->isEmpty()) {
            throw ValidationException::withMessages([
                'tax_year' => "No released payroll runs fall in the {$taxYear} tax year, so there is nothing to certify.",
            ]);
        }

        $employer = $this->employerSnapshotForCompany();
        $issued = collect();

        foreach ($slips as $employeeId => $forEmployee) {
            $person = DB::table('hr_people')->where('user_id', $employeeId)->first();

            if (! $person) {
                continue;   // a payslip with no HR record cannot be certified to anybody
            }

            $issued->push($this->issue(
                TaxCertificate::HR,
                (int) $employeeId,
                $taxYear,
                $half,
                $this->employerFigures($forEmployee, (int) $employeeId, $taxYear),
                $this->personSnapshot($person),
                $employer,
                null,
                $reason,
            ));
        }

        return $issued;
    }

    /** March to February, as SARS counts a year of assessment. */
    public function taxYearBounds(int $taxYear): array
    {
        return [($taxYear - 1) . '-03-01', Carbon::parse($taxYear . '-02-01')->endOfMonth()->toDateString()];
    }

    /**
     * One in-house employee's year, totalled into the figures a certificate carries.
     *
     * The payslip carries PAYE, UIF and the incentive directly; retirement, medical and fringe
     * benefits are on the earnings lines, because the direct engine keeps them there rather than in
     * columns the way the bureau does.
     *
     * @param  Collection<int, object>  $slips
     * @return array<string, mixed>
     */
    private function employerFigures(Collection $slips, int $employeeId, int $taxYear): array
    {
        $sum = fn (string $column) => round((float) $slips->sum(fn ($s) => (float) ($s->{$column} ?? 0)), 2);

        $gross = $sum('gross_pay');
        $paye = $sum('paye');
        $uifEmployee = $sum('uif');
        // The employer matches the employee's 1%, and the certificate states both halves together.
        $uif = round($uifEmployee * 2, 2);

        $lines = DB::table('payroll_earnings_lines')
            ->whereIn('payslip_id', $slips->pluck('id'))
            ->selectRaw('line_type, COALESCE(SUM(amount), 0) as total')
            ->groupBy('line_type')
            ->pluck('total', 'line_type');

        $lineTotal = fn (string ...$types) => round(array_sum(array_map(
            fn ($type) => (float) ($lines[$type] ?? 0),
            $types,
        )), 2);

        $retirement = $lineTotal(EarningsLineType::Pension->value);
        $medical = $lineTotal(EarningsLineType::MedicalAid->value);
        // Employer contributions are a taxable fringe benefit to the employee, which is what the
        // 3810 code on the certificate reports.
        $benefits = $lineTotal(
            EarningsLineType::EmployerMedical->value,
            EarningsLineType::EmployerPension->value,
        );
        $allowances = $lineTotal(
            EarningsLineType::Allowance->value,
            EarningsLineType::TravelAllowance->value,
        );

        // From the claim ledger rather than summed off the payslips: that is what makes the
        // certificate and the EMP501 agree after a correction.
        $eti = $this->etiClaimedIn(TaxCertificate::HR, $employeeId, $taxYear);

        return [
            'gross'           => $gross,
            'paye'            => $paye,
            'uif'             => $uif,
            'sdl'             => round($gross * 0.01, 2),
            'eti'             => $eti,
            'retirement'      => $retirement,
            'medical'         => $medical,
            'allowances'      => $allowances,
            'fringe_benefits' => $benefits,
            'periods_worked'  => $slips->count(),
            'period_start'    => $slips->min('run_start'),
            'period_end'      => $slips->max('run_end'),
            'source_codes'    => array_filter([
                '3601' => round($gross - $allowances, 2),   // income
                '3699' => $gross,                            // gross remuneration
                '3701' => $lineTotal(EarningsLineType::TravelAllowance->value),
                '3713' => $lineTotal(EarningsLineType::Allowance->value),
                '3810' => $benefits,
                '4001' => $retirement,
                '4005' => $medical,
                '4102' => $paye,
                '4141' => $uif,
                '4149' => round($paye + $uif, 2),
                '7004' => $eti,
            ], fn ($value) => (float) $value != 0.0),
        ];
    }

    /** An hr_people row in the shape a certificate stores. */
    private function personSnapshot(object $person): array
    {
        $firstNames = trim((string) ($person->first_name ?? ''));

        return [
            'name'            => trim($firstNames . ' ' . ($person->surname ?? '')),
            'surname'         => $person->surname ?? null,
            'first_names'     => $firstNames,
            'initials'        => strtoupper(substr($firstNames, 0, 1)),
            'employee_number' => $person->employee_number ?? null,
            'id_number'       => $person->id_number ?? null,
            'passport_number' => $person->passport_number ?? null,
            'tax_number'      => $person->income_tax_number ?? null,
            'date_of_birth'   => ! empty($person->date_of_birth) ? Carbon::parse($person->date_of_birth)->toDateString() : null,
            'email'           => $person->email ?? null,
            'nature'          => filled($person->id_number ?? null) ? 'A' : 'B',
        ];
    }

    /** The employer's own SARS profile, for a certificate issued off the in-house payroll. */
    private function employerSnapshotForCompany(): array
    {
        $config = \App\Modules\Finance\Sars\Models\SarsTaxConfig::current();
        $company = DB::table('companies')->where('id', $this->companyId())->first(['name', 'registration_number']);

        return [
            'name'                => $company->name ?? '',
            'trading_name'        => $company->name ?? '',
            'registration_number' => $company->registration_number ?? null,
            'paye_reference'      => $config->paye_reference,
            'uif_reference'       => $config->uif_reference,
            'sdl_reference'       => $config->sdl_reference,
        ];
    }

    /**
     * The tenant every in-house figure is scoped to.
     *
     * Resolved to a concrete id rather than left null, so a platform admin without a current
     * company cannot certify one employer's payroll out of another's payslips.
     */
    private function companyId(): int
    {
        return (int) (app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id()
            ?? Auth::user()?->company_id
            ?? -1);
    }

    /**
     * Write one certificate, or a new version of one that already exists.
     *
     * The figures decide the type: a certificate with no PAYE on it is an IT3(a), and SARS rejects
     * an IRP5 sent in its place.
     *
     * @param  array<string, mixed>  $figures
     */
    public function issue(
        string $employeeType,
        int $employeeId,
        int $taxYear,
        string $half,
        array $figures,
        array $employeeSnapshot,
        array $employerSnapshot,
        ?int $bureauCompanyId = null,
        ?string $reason = null,
    ): TaxCertificate {
        $period = $taxYear . $half;

        $existing = TaxCertificate::query()
            ->forEmployee($employeeType, $employeeId)
            ->where('tax_year', $taxYear)
            ->where('period', $period)
            ->current()
            ->first();

        // Nothing has moved, so nothing is reissued. Without this a bureau pressing "generate" twice
        // would hand every employee a version 2 that says exactly what version 1 said, and the
        // history stops meaning anything.
        if ($existing && $this->matches($existing, $figures)) {
            return $existing;
        }

        if ($existing && blank($reason)) {
            $reason = 'Payroll figures changed after the certificate was issued.';
        }

        return DB::transaction(function () use (
            $existing, $employeeType, $employeeId, $taxYear, $period,
            $figures, $employeeSnapshot, $employerSnapshot, $bureauCompanyId, $reason
        ) {
            $version = $existing ? $existing->version + 1 : 1;

            return TaxCertificate::create([
                'employee_type'      => $employeeType,
                'employee_id'        => $employeeId,
                'bureau_company_id'  => $bureauCompanyId,
                'tax_year'           => $taxYear,
                'period'             => $period,
                'certificate_number' => $this->number($employerSnapshot, $taxYear, $period, $employeeId, $version),
                'type'               => TaxCertificate::typeFor((float) ($figures['paye'] ?? 0)),
                'version'            => $version,
                'supersedes_id'      => $existing?->id,
                'reissue_reason'     => $existing ? $reason : null,
                'status'             => TaxCertificate::FINAL,
                'employee_snapshot'  => $employeeSnapshot,
                'employer_snapshot'  => $employerSnapshot,
                'source_codes'       => $figures['source_codes'] ?? [],
                'gross'              => $figures['gross'] ?? 0,
                'paye'               => $figures['paye'] ?? 0,
                'uif'                => $figures['uif'] ?? 0,
                'sdl'                => $figures['sdl'] ?? 0,
                'eti'                => $figures['eti'] ?? 0,
                'retirement'         => $figures['retirement'] ?? 0,
                'medical'            => $figures['medical'] ?? 0,
                'allowances'         => $figures['allowances'] ?? 0,
                'fringe_benefits'    => $figures['fringe_benefits'] ?? 0,
                'periods_worked'     => $figures['periods_worked'] ?? 0,
                'period_start'       => $figures['period_start'] ?? null,
                'period_end'         => $figures['period_end'] ?? null,
                'issued_by'          => Auth::id(),
                'issued_at'          => now(),
            ]);
        });
    }

    /**
     * Total one employee's year into the figures a certificate carries.
     *
     * @param  Collection<int, BureauPayslip>  $slips
     * @return array<string, mixed>
     */
    private function figuresFor(Collection $slips, int $employeeId): array
    {
        $sum = fn (string $column) => round((float) $slips->sum(fn (BureauPayslip $s) => (float) $s->{$column}), 2);

        $gross = $sum('gross');
        $paye = $sum('paye');
        $uif = round($sum('uif_employee') + $sum('uif_employer'), 2);
        $retirement = $sum('retirement_deduction');
        $medical = $sum('medical_credit');
        $benefits = $sum('fringe_benefits');
        $eti = $sum('eti');

        // Dates from the runs the payslips belong to, so a mid-year joiner's certificate says when
        // they actually started rather than when the tax year did.
        $runs = BureauPayRun::query()->whereIn('id', $slips->pluck('bureau_pay_run_id'))->get();

        return [
            'gross'           => $gross,
            'paye'            => $paye,
            'uif'             => $uif,
            'sdl'             => $sum('sdl'),
            'eti'             => $eti,
            'retirement'      => $retirement,
            'medical'         => $medical,
            'allowances'      => 0.0,
            'fringe_benefits' => $benefits,
            'periods_worked'  => $slips->count(),
            'period_start'    => $runs->min('period_start'),
            'period_end'      => $runs->max('period_end'),

            // The SARS source codes as issued. Kept as a map rather than columns because the code
            // set is version-specific — a code SARS adds next year must not need a migration — and
            // because this is what the e@syFile file is built from.
            'source_codes' => array_filter([
                '3601' => $gross,          // income
                '3699' => $gross,          // gross remuneration
                '3810' => $benefits,       // medical scheme fringe benefit
                '4001' => $retirement,     // retirement fund contributions
                '4005' => $medical,        // medical scheme contributions
                '4102' => $paye,           // PAYE
                '4141' => $uif,            // UIF
                '4149' => round($paye + $uif, 2),   // total tax, levies and contributions
                '7004' => $eti,            // employment tax incentive
            ], fn ($value) => (float) $value != 0.0),
        ];
    }

    /**
     * Has anything on the certificate actually changed?
     *
     * Compared on the figures rather than on a timestamp, because a payroll reprocessed with the
     * same result has not changed anything an employee or SARS would care about.
     */
    private function matches(TaxCertificate $certificate, array $figures): bool
    {
        foreach (['gross', 'paye', 'uif', 'eti', 'retirement', 'medical', 'fringe_benefits'] as $column) {
            if (abs((float) $certificate->{$column} - (float) ($figures[$column] ?? 0)) > 0.005) {
                return false;
            }
        }

        return true;
    }

    /**
     * The certificate number.
     *
     * SARS specifies a 30-character number built from the employer's PAYE reference, the year and a
     * sequence, and the exact construction is version-specific. This follows that shape and is
     * unique and stable, but the definitive format belongs in the file specification for the tax
     * year — which is where it should be read from once the current BRS is loaded, rather than being
     * fixed here where a revision means a code change.
     */
    private function number(array $employer, int $taxYear, string $period, int $employeeId, int $version): string
    {
        $reference = preg_replace('/\D/', '', (string) ($employer['paye_reference'] ?? '')) ?: '0000000000';

        return sprintf(
            '%s%s%s%06d%02d',
            substr(str_pad($reference, 10, '0', STR_PAD_LEFT), -10),
            $taxYear,
            $period,
            $employeeId,
            $version,
        );
    }

    private function employeeSnapshot($employee): array
    {
        $firstNames = trim((string) $employee->first_name);

        return [
            'name'            => $employee->fullName(),
            'surname'         => $employee->last_name,
            'first_names'     => $firstNames,
            'initials'        => strtoupper(substr($firstNames, 0, 1)),
            'employee_number' => $employee->employee_number,
            'id_number'       => $employee->id_number,
            'passport_number' => $employee->passport_number,
            'tax_number'      => $employee->tax_number,
            'date_of_birth'   => $employee->date_of_birth ? Carbon::parse($employee->date_of_birth)->toDateString() : null,
            'email'           => $employee->email,
            // Nature of person: A for a South African ID, B for a passport. SARS rejects a
            // certificate whose nature does not match the identity number it carries.
            'nature'          => filled($employee->id_number) ? 'A' : 'B',
        ];
    }

    private function employerSnapshot(BureauCompany $company): array
    {
        return [
            'name'                => $company->name,
            'trading_name'        => $company->trading_name,
            'registration_number' => $company->registration_number,
            'paye_reference'      => $company->paye_reference,
            'uif_reference'       => $company->uif_reference,
            'sdl_reference'       => $company->sdl_reference,
        ];
    }

    /**
     * How much of the incentive was claimed for this employee in the year, from the ledger.
     *
     * On the certificate because SARS reconciles the EMP501 against it, and reading it from the
     * ledger rather than from the payslips is what makes the two agree after a correction.
     */
    public function etiClaimedIn(string $employeeType, int $employeeId, int $taxYear): float
    {
        return (float) EtiClaim::query()
            ->forEmployee($employeeType, $employeeId)
            ->where('tax_year', $taxYear)
            ->claimed()
            ->sum('amount');
    }
}
