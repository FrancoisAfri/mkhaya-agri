<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\Finance\Sars\Models\SarsSubmission;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Support\PayRunLifecycle;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Files a payroll-bureau client's statutory return into the SHARED SARS submission history, so
 * bureau filings get the same versioning + audit trail + stored PDF as the tenant's own returns.
 *
 * The submission carries the operator tenant's company_id (from the pay run) and is tagged with
 * the client via bureau_company_id + subject_label. Versioning keys on
 * (tax_type, period, bureau_company_id) so each client's monthly return has its own chain.
 * The bureau already computed the statutory totals on the run; this snapshots them.
 */
class BureauStatutoryFilingService
{
    /** Snapshot a finalised bureau pay run as a versioned EMP201 in the shared SARS history. */
    public function fileEmp201(BureauPayRun $run): SarsSubmission
    {
        $company = $run->company;                        // the client employer (BureauCompany)
        $end = Carbon::parse($run->period_end);
        $month = $end->format('Y-m');
        $data = $this->emp201Data($run, $company, $month);

        $prior = SarsSubmission::where('tax_type', 'emp201')->where('period', $month)
            ->where('bureau_company_id', $company->id)->orderByDesc('version')->first();
        $hasErrors = ! empty($data['validation']['errors']);

        $submission = SarsSubmission::create([
            'company_id'        => $run->company_id,     // operator tenant
            'bureau_company_id' => $company->id,          // client employer
            'subject_label'     => $company->name,
            'tax_type'          => 'emp201',
            'period'            => $month,
            'status'            => $hasErrors ? 'generated' : 'validated',
            'version'           => $prior ? $prior->version + 1 : 1,
            'amends_id'         => $prior?->id,
            'totals'            => $data['totals'],
            'payload'           => $data,
            'validation'        => $data['validation'],
            'notes'             => 'Bureau filing · ' . $company->name . ' · run ' . $run->uuid,
            'generated_by'      => Auth::id(),
            'generated_at'      => now(),
        ]);

        $pdf = Pdf::loadView('pdf.sars.emp201', ['data' => $data, 'submission' => $submission])->setPaper('a4');
        $path = "sars/{$run->company_id}/emp201/{$submission->reference}.pdf";
        Storage::put($path, $pdf->output());
        $submission->update(['file_path' => $path]);

        // Same opening entry as the employer's own return: the trail is shared, so a bureau's
        // client filings and its own sit in one history under one set of rules.
        app(\App\Modules\Finance\Sars\Services\SubmissionStatusService::class)
            ->recordGenerated($submission, 'Bureau filing · ' . $company->name);

        // Who filed a client's return, and which version. The submission itself is versioned, but
        // the trail is where a bureau answers the question without knowing to look in SARS history.
        app(BureauAuditService::class)->run(
            $run,
            BureauAuditService::EMP201_FILED,
            "EMP201 for {$company->name} filed for {$month} (version {$submission->version}).",
            [
                'reference' => $submission->reference,
                'version'   => $submission->version,
                'totals'    => $data['totals'],
                'amends'    => $prior?->reference,
            ],
        );

        // Paid and reported is the definition of done for a month. Only from `paid`, because a run
        // whose EMP201 is filed before anybody has been paid is not complete, it is out of order —
        // and the lifecycle refuses that move rather than quietly recording a state that is untrue.
        if (PayRunLifecycle::canMove($run->status, PayRunLifecycle::COMPLETED)) {
            app(BureauPayRunService::class)->complete($run);
        }

        return $submission;
    }

    /** Shape the run's statutory totals into the EMP201 payload the shared PDF/history expects. */
    private function emp201Data(BureauPayRun $run, BureauCompany $company, string $month): array
    {
        $paye = (float) $run->total_paye;
        $sdl = (float) $run->total_sdl;
        $uifEmployee = (float) $run->total_uif_employee;
        $uifEmployer = (float) $run->total_uif_employer;
        $uifTotal = round($uifEmployee + $uifEmployer, 2);
        $eti = (float) $run->total_eti;
        $total = round($paye + $sdl + $uifTotal - $eti, 2);

        $errors = [];
        if (blank($company->paye_reference)) {
            $errors[] = ['entity' => 'config', 'id' => null, 'field' => 'paye_reference', 'message' => $company->name . ' has no PAYE reference number.'];
        }

        return [
            'period' => [
                'month' => $month,
                'from'  => Carbon::parse($run->period_start)->toDateString(),
                'to'    => Carbon::parse($run->period_end)->toDateString(),
                'label' => Carbon::parse($run->period_end)->format('M Y'),
            ],
            'header' => [
                'company_name'   => $company->name,
                'paye_reference' => $company->paye_reference,
                'uif_reference'  => $company->uif_reference,
                'sdl_reference'  => $company->sdl_reference,
                'sdl_liable'     => $sdl > 0,
                'contact_person' => null,
                'contact_email'  => null,
            ],
            'lines' => [
                ['code' => '4102', 'label' => 'PAYE (Pay-As-You-Earn)',       'amount' => $paye],
                ['code' => '4142', 'label' => 'SDL (Skills Development Levy)',  'amount' => $sdl],
                ['code' => '4141', 'label' => 'UIF (employee + employer)',      'amount' => $uifTotal],
                ['code' => '4118', 'label' => 'ETI (Employment Tax Incentive)', 'amount' => $eti],
            ],
            'totals' => [
                'paye'          => $paye,
                'sdl'           => $sdl,
                'uif_employee'  => $uifEmployee,
                'uif_employer'  => $uifEmployer,
                'uif_total'     => $uifTotal,
                'eti'           => $eti,
                'total_payable' => $total,
                'employees'     => (int) $run->employee_count,
                'gross'         => (float) $run->total_gross,
                'runs'          => 1,
            ],
            'validation' => ['validated' => 4, 'errors' => $errors],
        ];
    }
}
