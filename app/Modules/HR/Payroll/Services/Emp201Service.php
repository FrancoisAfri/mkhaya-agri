<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\Finance\Sars\Models\SarsTaxConfig;
use App\Modules\Finance\Sars\Services\SarsValidator;
use App\Modules\HR\Payroll\Models\Bureau\SaTaxYear;
use App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine;
use App\Modules\Tenancy\Services\CurrentCompany;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a monthly EMP201 employer declaration (PAYE + SDL + UIF, less ETI) from finalised
 * normal-engine payroll, validates it against the company's SARS profile, and — on generate —
 * persists a versioned SarsSubmission with a stored PDF into the shared submission history.
 *
 * Sourced from the tenant's own payroll: released payroll_runs + their finalised payslips,
 * scoped by company_id (RLS-backed). The bureau engine has its own per-client EMP201 export;
 * this is the employer's own return. Read paths never write; generate() is the only mutation.
 */
class Emp201Service
{
    // SARS PRN / EMP201 field codes.
    private const CODE_PAYE = '4102';
    private const CODE_SDL  = '4142';
    private const CODE_UIF  = '4141';
    private const CODE_ETI  = '4118';

    public function __construct(
        private SarsValidator $validator,
        private CurrentCompany $currentCompany,
    ) {
    }

    /** The most recent complete tax month (EMP201 is monthly), as 'YYYY-MM'. */
    public function defaultMonth(): string
    {
        return Carbon::today()->startOfMonth()->subMonth()->format('Y-m');
    }

    /**
     * Assemble the EMP201 for a tax month WITHOUT persisting: header from the SARS profile,
     * statutory lines aggregated from finalised payroll, and the validation result.
     */
    public function build(string $month): array
    {
        $config = SarsTaxConfig::current();
        [$from, $to] = $this->monthBounds($month);
        $agg = $this->aggregate($from, $to);

        $payeAmt = $agg->paye;
        $uifEmployee = $agg->uif;
        $uifTotal = round($uifEmployee * 2, 2);                 // employer matches the employee 1%
        // Read from the tax year rather than assumed at 1%: the rate is configurable precisely
        // because SARS can change it, and a hard-coded rate quietly files the wrong return.
        $sdlRate = (float) (SaTaxYear::for($this->taxYearFor($to))->sdl_rate ?? 0.01);
        $sdl = $config->sdl_liable ? round($agg->gross * $sdlRate, 2) : 0.0;
        // The engine calculates the incentive now, so the return can finally claim it. It used to be
        // a literal zero, which meant every employer running their own payroll here paid SARS the
        // full PAYE and never claimed an incentive they were entitled to.
        $eti = round((float) ($agg->eti ?? 0), 2);
        $total = round($payeAmt + $sdl + $uifTotal - $eti, 2);

        $lines = [
            ['code' => self::CODE_PAYE, 'label' => 'PAYE (Pay-As-You-Earn)',      'amount' => $payeAmt],
            ['code' => self::CODE_SDL,  'label' => 'SDL (Skills Development Levy)', 'amount' => $sdl],
            ['code' => self::CODE_UIF,  'label' => 'UIF (employee + employer)',     'amount' => $uifTotal],
            ['code' => self::CODE_ETI,  'label' => 'ETI (Employment Tax Incentive)', 'amount' => $eti],
        ];

        return [
            'period' => ['month' => $month, 'from' => $from, 'to' => $to, 'label' => Carbon::parse($from)->format('M Y')],
            'header' => [
                'company_name'   => (string) (DB::table('companies')->where('id', $this->currentCompany->id())->value('name') ?? ''),
                'paye_reference' => $config->paye_reference,
                'uif_reference'  => $config->uif_reference,
                'sdl_reference'  => $config->sdl_reference,
                'sdl_liable'     => (bool) $config->sdl_liable,
                'contact_person' => $config->contact_person,
                'contact_email'  => $config->contact_email,
            ],
            'lines'  => $lines,
            'totals' => [
                'paye'           => $payeAmt,
                'sdl'            => $sdl,
                'uif_employee'   => $uifEmployee,
                'uif_employer'   => $uifEmployee,
                'uif_total'      => $uifTotal,
                'eti'            => $eti,
                'total_payable'  => $total,
                'employees'      => (int) $agg->employees,
                'gross'          => $agg->gross,
                'runs'           => (int) $agg->runs,
            ],
            'validation' => $this->validate($config, $agg),
        ];
    }

    /** Aggregate finalised payslips (released runs) for the current tenant in the month. */
    /** The SARS year of assessment covering a date — March to February, so the calendar year lies. */
    private function taxYearFor(string $date): int
    {
        return PayrollTaxEngine::taxYearFor(Carbon::parse($date));
    }

    private function aggregate(string $from, string $to): object
    {
        return DB::table('payslips as p')
            ->join('payroll_runs as r', 'r.id', '=', 'p.payroll_run_id')
            ->where('r.status', 'released')
            ->where('p.status', '!=', 'draft')
            ->whereBetween('r.period_end', [$from, $to])
            ->where('p.company_id', $this->companyId())
            ->selectRaw('COALESCE(SUM(p.paye),0) paye, COALESCE(SUM(p.uif),0) uif, COALESCE(SUM(p.eti),0) eti, COALESCE(SUM(p.gross_pay),0) gross, COUNT(*) employees, COUNT(DISTINCT r.id) runs')
            ->first();
    }

    /**
     * The tenant to scope every aggregation to. Always resolves to a concrete id so a null
     * current company (e.g. a platform admin without a CurrentCompany) can never aggregate
     * across tenants — it falls back to the user's own company, then to a sentinel that
     * matches nothing. Mirrors the documented platform-admin leak-prevention pattern.
     */
    private function companyId(): int
    {
        return (int) ($this->currentCompany->id() ?? auth()->user()?->company_id ?? -1);
    }

    private function validate(SarsTaxConfig $config, object $agg): array
    {
        $result = $this->validator->validateConfig($config);
        $errors = $result['errors'];

        if (blank($config->paye_reference)) {
            $errors[] = ['entity' => 'config', 'id' => null, 'field' => 'paye_reference', 'message' => 'A PAYE reference number is required to file EMP201.'];
        }
        if ((int) $agg->runs === 0) {
            $errors[] = ['entity' => 'emp201', 'id' => null, 'field' => 'period', 'message' => 'No finalised (released) payroll run falls in this month — nothing to declare.'];
        }
        if ($agg->paye < 0 || $agg->uif < 0) {
            $errors[] = ['entity' => 'emp201', 'id' => null, 'field' => 'tax', 'message' => 'Negative PAYE or UIF in the period — check for reversed/corrected payslips.'];
        }
        if ($config->sdl_liable && blank($config->sdl_reference)) {
            $errors[] = ['entity' => 'config', 'id' => null, 'field' => 'sdl_reference', 'message' => 'SDL is marked liable but no SDL reference number is set.'];
        }

        // "N checks": PAYE ref + UIF ref + SDL liability + a payroll present + the four lines.
        return ['validated' => 4 + 4, 'errors' => array_values($errors)];
    }

    /**
     * Generate and persist the EMP201 as a versioned submission with a stored PDF. A new version
     * is created if one already exists for the month (amendment chain).
     */
    public function generate(string $month, ?string $notes = null): SarsSubmission
    {
        $data = $this->build($month);
        $companyId = $this->currentCompany->id();

        $prior = SarsSubmission::ownReturns()->where('tax_type', 'emp201')->where('period', $month)->orderByDesc('version')->first();
        $hasErrors = ! empty($data['validation']['errors']);

        $submission = SarsSubmission::create([
            'company_id'   => $companyId,
            'tax_type'     => 'emp201',
            'period'       => $month,
            'status'       => $hasErrors ? 'generated' : 'validated',
            'version'      => $prior ? $prior->version + 1 : 1,
            'amends_id'    => $prior?->id,
            'totals'       => $data['totals'],
            'payload'      => $data,
            'validation'   => $data['validation'],
            'notes'        => $notes,
            'generated_by' => Auth::id(),
            'generated_at' => now(),
        ]);

        $pdf = Pdf::loadView('pdf.sars.emp201', ['data' => $data, 'submission' => $submission])->setPaper('a4');
        $path = "sars/{$companyId}/emp201/{$submission->reference}.pdf";
        Storage::put($path, $pdf->output());
        $submission->update(['file_path' => $path]);

        // The opening entry in the trail. Without it the first thing recorded about a return is
        // whoever moved it second, and "who generated this" has no answer.
        app(\App\Modules\Finance\Sars\Services\SubmissionStatusService::class)->recordGenerated($submission, $notes);

        return $submission;
    }

    /** @return array{0:string,1:string} [firstDay, lastDay] of the YYYY-MM month. */
    private function monthBounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        return [$start->toDateString(), (clone $start)->endOfMonth()->toDateString()];
    }
}
