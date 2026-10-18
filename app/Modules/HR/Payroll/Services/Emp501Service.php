<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Models\SarsTaxConfig;
use App\Modules\Finance\Sars\Services\SarsValidator;
use App\Modules\Tenancy\Services\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the annual EMP501 employer reconciliation and its IRP5 / IT3(a) employee tax
 * certificates from finalised normal-engine payroll for a SA tax year (1 Mar – end Feb).
 * The reconciliation ties the 12 months of PAYE declarations to the total PAYE on the
 * certificates — the whole point of EMP501. On generate() it persists a versioned
 * SarsSubmission with a stored PDF into the shared submission history. A single employee's
 * IRP5/IT3(a) can also be rendered on demand. Tenant-scoped by company_id (RLS-backed).
 * Read paths never write; generate() is the only mutation.
 */
class Emp501Service
{
    public function __construct(
        private SarsValidator $validator,
        private CurrentCompany $currentCompany,
    ) {
    }

    /**
     * The tenant to scope every aggregation to. Always a concrete id so a null current company
     * (e.g. a platform admin) can never aggregate across tenants — falls back to the user's own
     * company, then a sentinel matching nothing.
     */
    private function companyId(): int
    {
        return (int) ($this->currentCompany->id() ?? auth()->user()?->company_id ?? -1);
    }

    /** The most recent COMPLETED SA tax year (ended before today). */
    public function defaultTaxYear(): int
    {
        $row = DB::table('sa_tax_years')->whereDate('ends_on', '<', Carbon::today())
            ->orderByDesc('tax_year')->first();

        return (int) ($row->tax_year ?? (Carbon::today()->month >= 3 ? Carbon::today()->year + 1 : Carbon::today()->year));
    }

    /** @return array{0:string,1:string,2:string} [from, to, label] for a SA tax year. */
    public function taxYearBounds(int $taxYear): array
    {
        $row = DB::table('sa_tax_years')->where('tax_year', $taxYear)->orderBy('id')->first();
        if ($row) {
            $from = Carbon::parse($row->starts_on);
            $to = Carbon::parse($row->ends_on);
        } else {
            // Fallback: SA tax year Y runs 1 Mar (Y-1) → end Feb (Y).
            $from = Carbon::create($taxYear - 1, 3, 1)->startOfDay();
            $to = Carbon::create($taxYear, 2, 1)->endOfMonth();
        }

        return [$from->toDateString(), $to->toDateString(), $from->format('M Y') . ' – ' . $to->format('M Y')];
    }

    /** Assemble the full EMP501 (certificates + monthly declarations + reconciliation), no writes. */
    public function build(int $taxYear): array
    {
        $config = SarsTaxConfig::current();
        [$from, $to, $label] = $this->taxYearBounds($taxYear);

        $certs = $this->certificates($from, $to);
        $monthly = $this->monthlyDeclarations($from, $config);

        $certPaye = round(array_sum(array_column($certs, 'paye')), 2);
        $certUif = round(array_sum(array_column($certs, 'uif')), 2);
        $certIncome = round(array_sum(array_column($certs, 'income')), 2);
        $declaredPaye = round(array_sum(array_column($monthly, 'paye')), 2);
        $declaredUifEmployee = round(array_sum(array_column($monthly, 'uif_employee')), 2);
        $declaredSdl = round(array_sum(array_column($monthly, 'sdl')), 2);

        $payeDiff = round($declaredPaye - $certPaye, 2);
        $uifDiff = round($declaredUifEmployee - $certUif, 2);
        $balanced = abs($payeDiff) < 0.01 && abs($uifDiff) < 0.01;

        $reconciliation = [
            'declared_paye'     => $declaredPaye,
            'certificate_paye'  => $certPaye,
            'paye_difference'   => $payeDiff,
            'declared_uif'      => $declaredUifEmployee,
            'certificate_uif'   => $certUif,
            'uif_difference'    => $uifDiff,
            'declared_sdl'      => $declaredSdl,
            'balanced'          => $balanced,
        ];

        return [
            'period'         => ['tax_year' => $taxYear, 'from' => $from, 'to' => $to, 'label' => $label],
            'header'         => [
                'company_name'   => (string) (DB::table('companies')->where('id', $this->currentCompany->id())->value('name') ?? ''),
                'paye_reference' => $config->paye_reference,
                'uif_reference'  => $config->uif_reference,
                'sdl_reference'  => $config->sdl_reference,
                'sdl_liable'     => (bool) $config->sdl_liable,
                'contact_person' => $config->contact_person,
                'contact_email'  => $config->contact_email,
            ],
            'certificates'   => $certs,
            'monthly'        => $monthly,
            'totals'         => [
                'certificate_count' => count($certs),
                'irp5_count'        => count(array_filter($certs, fn ($c) => $c['type'] === 'IRP5')),
                'it3a_count'        => count(array_filter($certs, fn ($c) => $c['type'] === 'IT3(a)')),
                'total_income'      => $certIncome,
                'total_paye'        => $certPaye,
                'total_uif'         => $certUif,
                'declared_sdl'      => $declaredSdl,
            ],
            'reconciliation' => $reconciliation,
            'validation'     => $this->validate($config, $certs, $reconciliation),
        ];
    }

    /** Per-employee certificate figures for the tax year (one row per employee). */
    private function certificates(string $from, string $to, ?int $employeeId = null): array
    {
        $hasPension = \Illuminate\Support\Facades\Schema::hasColumn('payslips', 'pension');

        $rows = DB::table('payslips as p')
            ->join('payroll_runs as r', 'r.id', '=', 'p.payroll_run_id')
            ->leftJoin('hr_people as h', 'h.id', '=', 'p.employee_id')
            ->where('r.status', 'released')
            ->where('p.status', '!=', 'draft')
            ->whereBetween('r.period_end', [$from, $to])
            ->where('p.company_id', $this->companyId())
            ->when($employeeId, fn ($q) => $q->where('p.employee_id', $employeeId))
            ->groupBy('p.employee_id', 'h.first_name', 'h.surname', 'h.id_number', 'h.income_tax_number')
            ->selectRaw('p.employee_id, h.first_name, h.surname, h.id_number, h.income_tax_number,
                COALESCE(SUM(p.gross_pay),0) income, COALESCE(SUM(p.paye),0) paye, COALESCE(SUM(p.uif),0) uif, '
                . ($hasPension ? 'COALESCE(SUM(p.pension),0)' : '0') . ' pension, COUNT(*) periods')
            ->orderBy('h.surname')->get();

        return $rows->map(function ($r) {
            $income = round((float) $r->income, 2);
            $paye = round((float) $r->paye, 2);
            $uif = round((float) $r->uif, 2);
            $pension = round((float) $r->pension, 2);

            return [
                'employee_id' => (int) $r->employee_id,
                'name'        => trim(($r->first_name ?? '') . ' ' . ($r->surname ?? '')) ?: ('Employee #' . $r->employee_id),
                'id_number'   => $r->id_number,
                'tax_number'  => $r->income_tax_number,
                'type'        => $paye > 0 ? 'IRP5' : 'IT3(a)',
                'periods'     => (int) $r->periods,
                'income'      => $income,
                'paye'        => $paye,
                'uif'         => $uif,
                'pension'     => $pension,
                // SARS source codes: 3699 gross remuneration, 3601 income, 4001 pension,
                // 4102 PAYE, 4141 UIF.
                'codes'       => array_values(array_filter([
                    ['code' => '3601', 'label' => 'Income (taxable)', 'amount' => $income],
                    ['code' => '3699', 'label' => 'Gross remuneration', 'amount' => $income],
                    $pension > 0 ? ['code' => '4001', 'label' => 'Pension fund contributions', 'amount' => $pension] : null,
                    ['code' => '4102', 'label' => 'PAYE', 'amount' => $paye],
                    ['code' => '4141', 'label' => 'UIF contribution', 'amount' => $uif],
                ])),
            ];
        })->all();
    }

    /** The 12 monthly PAYE/SDL/UIF declarations across the tax year. */
    private function monthlyDeclarations(string $from, SarsTaxConfig $config): array
    {
        $cursor = Carbon::parse($from)->startOfMonth();
        $out = [];
        for ($i = 0; $i < 12; $i++) {
            $mFrom = $cursor->toDateString();
            $mTo = (clone $cursor)->endOfMonth()->toDateString();
            $a = DB::table('payslips as p')
                ->join('payroll_runs as r', 'r.id', '=', 'p.payroll_run_id')
                ->where('r.status', 'released')->where('p.status', '!=', 'draft')
                ->whereBetween('r.period_end', [$mFrom, $mTo])
                ->where('p.company_id', $this->companyId())
                ->selectRaw('COALESCE(SUM(p.paye),0) paye, COALESCE(SUM(p.uif),0) uif, COALESCE(SUM(p.gross_pay),0) gross')
                ->first();

            $out[] = [
                'month'        => $cursor->format('Y-m'),
                'label'        => $cursor->format('M Y'),
                'paye'         => round((float) $a->paye, 2),
                'uif_employee' => round((float) $a->uif, 2),
                'uif_total'    => round((float) $a->uif * 2, 2),
                'sdl'          => $config->sdl_liable ? round((float) $a->gross * 0.01, 2) : 0.0,
            ];
            $cursor->addMonth();
        }

        return $out;
    }

    private function validate(SarsTaxConfig $config, array $certs, array $reconciliation): array
    {
        $result = $this->validator->validateConfig($config);
        $errors = $result['errors'];

        if (blank($config->paye_reference)) {
            $errors[] = ['entity' => 'config', 'id' => null, 'field' => 'paye_reference', 'message' => 'A PAYE reference number is required to file EMP501.'];
        }
        if (count($certs) === 0) {
            $errors[] = ['entity' => 'emp501', 'id' => null, 'field' => 'period', 'message' => 'No finalised payroll in this tax year — there is nothing to reconcile.'];
        }
        if (! $reconciliation['balanced']) {
            $errors[] = ['entity' => 'emp501', 'id' => null, 'field' => 'reconciliation', 'message' => 'Certificates do not reconcile to the monthly declarations (PAYE diff ' . number_format($reconciliation['paye_difference'], 2) . ').'];
        }
        foreach ($certs as $c) {
            if (blank($c['tax_number'])) {
                $errors[] = ['entity' => 'certificate', 'id' => $c['employee_id'], 'field' => 'tax_number', 'message' => $c['name'] . ' has no income tax number on their profile (required on the IRP5).'];
            }
        }

        return ['validated' => 3 + count($certs), 'errors' => array_values($errors)];
    }

    /**
     * Generate and persist the EMP501 as a versioned submission with a stored PDF. A new version
     * is created if one already exists for the tax year (amendment chain).
     */
    public function generate(int $taxYear, ?string $notes = null): SarsSubmission
    {
        $data = $this->build($taxYear);
        $companyId = $this->currentCompany->id();
        $period = 'TY' . $taxYear;

        $prior = SarsSubmission::ownReturns()->where('tax_type', 'emp501')->where('period', $period)->orderByDesc('version')->first();
        $hasErrors = ! empty($data['validation']['errors']);

        $submission = SarsSubmission::create([
            'company_id'   => $companyId,
            'tax_type'     => 'emp501',
            'period'       => $period,
            'status'       => $hasErrors ? 'generated' : 'validated',
            'version'      => $prior ? $prior->version + 1 : 1,
            'amends_id'    => $prior?->id,
            'totals'       => $data['totals'] + ['reconciled' => $data['reconciliation']['balanced']],
            'payload'      => $data,
            'validation'   => $data['validation'],
            'notes'        => $notes,
            'generated_by' => Auth::id(),
            'generated_at' => now(),
        ]);

        $pdf = Pdf::loadView('pdf.sars.emp501', ['data' => $data, 'submission' => $submission])->setPaper('a4');
        $path = "sars/{$companyId}/emp501/{$submission->reference}.pdf";
        Storage::put($path, $pdf->output());
        $submission->update(['file_path' => $path]);

        // Same opening trail entry as the EMP201: every return in this history answers "who
        // generated this, and when" the same way, whichever screen produced it.
        app(\App\Modules\Finance\Sars\Services\SubmissionStatusService::class)->recordGenerated($submission);

        return $submission;
    }

    /**
     * One employee's IRP5 / IT3(a) for a tax year.
     *
     * Served from the issued certificate where one exists, and only computed live where none has
     * been issued yet.
     *
     * This used to always recompute, which is right the first time and wrong every time after: an
     * employee whose February payslip was corrected in June would download a document that no
     * longer matched the one they filed their return with, with nothing recording that the two
     * differed. TaxCertificateService was written to fix exactly that and had only ever been wired
     * to the bureau.
     *
     * The live path stays, because a certificate not yet issued still has to be previewable — but
     * it is now the fallback rather than the answer.
     */
    public function certificatePdf(int $employeeId, int $taxYear): \Barryvdh\DomPDF\PDF
    {
        [$from, $to, $label] = $this->taxYearBounds($taxYear);
        $config = SarsTaxConfig::current();
        $company = (string) (DB::table('companies')->where('id', $this->currentCompany->id())->value('name') ?? '');

        $issued = \App\Modules\HR\Payroll\Models\TaxCertificate::query()
            ->forEmployee(\App\Modules\HR\Payroll\Models\TaxCertificate::HR, $employeeId)
            ->where('tax_year', $taxYear)
            ->current()
            ->first();

        if ($issued) {
            return Pdf::loadView('pdf.sars.irp5', [
                'cert'    => $this->fromCertificate($issued),
                'period'  => ['tax_year' => $taxYear, 'label' => $label],
                'company' => $company,
                'config'  => $config,
                'issued'  => $issued,
            ])->setPaper('a4');
        }

        $certs = $this->certificates($from, $to, $employeeId);
        abort_if(empty($certs), 404, 'No payroll for this employee in the tax year.');

        return Pdf::loadView('pdf.sars.irp5', [
            'cert'    => $certs[0],
            'period'  => ['tax_year' => $taxYear, 'label' => $label],
            'company' => $company,
            'config'  => $config,
        ])->setPaper('a4');
    }

    /**
     * A stored certificate in the shape the IRP5 view already renders.
     *
     * The view is shared with the live path so that an issued certificate and a preview look
     * identical — the difference between them is which figures they carry, and that difference must
     * not also become a difference in how the document reads.
     *
     * @return array<string, mixed>
     */
    private function fromCertificate(\App\Modules\HR\Payroll\Models\TaxCertificate $certificate): array
    {
        $employee = (array) $certificate->employee_snapshot;

        // The codes as they were issued, in the shape the view renders. Labelled from the SARS code
        // set rather than stored with labels, so a certificate issued last year still reads
        // correctly if a label is corrected.
        $labels = [
            '3601' => 'Income (taxable)',
            '3699' => 'Gross remuneration',
            '3701' => 'Travel allowance',
            '3713' => 'Other allowances',
            '3810' => 'Medical scheme fringe benefit',
            '4001' => 'Pension fund contributions',
            '4005' => 'Medical scheme contributions',
            '4102' => 'PAYE',
            '4141' => 'UIF contribution',
            '4149' => 'Total tax, levies and contributions',
            '7004' => 'Employment tax incentive',
        ];

        $codes = [];
        foreach ((array) $certificate->source_codes as $code => $amount) {
            $codes[] = [
                'code'   => (string) $code,
                'label'  => $labels[(string) $code] ?? 'Source code ' . $code,
                'amount' => round((float) $amount, 2),
            ];
        }

        return [
            'employee_id'     => $certificate->employee_id,
            'name'            => $employee['name'] ?? '',
            'employee_number' => $employee['employee_number'] ?? null,
            'id_number'       => $employee['id_number'] ?? null,
            'tax_number'      => $employee['tax_number'] ?? null,
            'type'            => $certificate->type,
            'periods'         => (int) $certificate->periods_worked,
            'income'          => (float) $certificate->gross,
            'paye'            => (float) $certificate->paye,
            'uif'             => (float) $certificate->uif,
            'pension'         => (float) $certificate->retirement,
            'codes'           => $codes,
            // Only an issued certificate has these, and the view uses them to say which version of
            // the document this is — the question an employee asks when they hold two.
            'certificate_number' => $certificate->certificate_number,
            'version'         => $certificate->version,
            'issued_at'       => $certificate->issued_at?->toDateString(),
        ];
    }
}
