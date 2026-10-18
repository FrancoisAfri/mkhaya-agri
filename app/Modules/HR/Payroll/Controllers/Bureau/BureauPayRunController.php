<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayslip;
use App\Modules\HR\Payroll\Services\Bureau\BureauApprovalService;
use App\Modules\HR\Payroll\Services\Bureau\BureauAuditService;
use App\Modules\HR\Payroll\Services\Bureau\BureauPayRunService;
use App\Modules\HR\Payroll\Services\Bureau\PayrollValidationService;
use App\Modules\HR\Payroll\Support\PayRunLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BureauPayRunController extends Controller
{
    public function __construct(
        private readonly BureauPayRunService $runs,
        private readonly BureauAuditService $audit,
        private readonly PayrollValidationService $validation,
    ) {
    }

    public function index(BureauCompany $company): InertiaResponse
    {
        $this->gate($company);

        return Inertia::render('Modules/HR/Payroll/Bureau/PayRuns', [
            'company' => ['uuid' => $company->uuid, 'name' => $company->name, 'pay_frequency' => $company->pay_frequency, 'tax_year' => $company->tax_year],
            'runs'    => $company->payRuns()->latest()->get()->map(fn (BureauPayRun $r) => [
                'uuid' => $r->uuid, 'name' => $r->name, 'status' => $r->status,
                // Named on the list because "1–7 March" and "1–31 March" are different runs, and a
                // list showing only dates leaves the reader to work out which cycle they are on.
                'frequency' => $r->frequency,
                'period_start' => $r->period_start?->toDateString(), 'period_end' => $r->period_end?->toDateString(),
                'employee_count' => $r->employee_count, 'total_net' => (float) $r->total_net,
            ]),
        ]);
    }

    public function store(Request $request, BureauCompany $company): RedirectResponse
    {
        $this->gate($company);
        $data = $request->validate([
            'name'         => ['nullable', 'string', 'max:120'],
            'period_start' => ['required', 'date'],
            'period_end'   => ['required', 'date', 'after_or_equal:period_start'],
            'pay_date'     => ['nullable', 'date'],
            'tax_year'     => ['nullable', 'integer', 'between:2000,2100'],
            // Which cycle this run is for. Without it the run inherited the company's and a client
            // could only ever pay on one — anybody on another cycle was never paid at all.
            'frequency'    => ['nullable', 'in:' . implode(',', BureauCompany::FREQUENCIES)],
            // The screen has always sent this; it was simply never validated, so every run was
            // 'regular' and an off-cycle bonus run could not be created.
            'run_type'     => ['nullable', 'in:regular,offcycle,bonus,termination'],
        ]);
        $run = $this->runs->create($company, $data);

        return redirect()->route('payroll.bureau.runs.show', [$company->uuid, $run->uuid]);
    }

    public function show(BureauCompany $company, BureauPayRun $run, BureauApprovalService $approvals): InertiaResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        return Inertia::render('Modules/HR/Payroll/Bureau/PayRun', [
            'company'  => ['uuid' => $company->uuid, 'name' => $company->name],
            'run'      => [
                'uuid' => $run->uuid, 'name' => $run->name, 'status' => $run->status,
                'period_start' => $run->period_start?->toDateString(), 'period_end' => $run->period_end?->toDateString(),
                'tax_year' => $run->tax_year, 'employee_count' => $run->employee_count,
                'total_gross' => (float) $run->total_gross, 'total_paye' => (float) $run->total_paye,
                'total_uif_employee' => (float) $run->total_uif_employee, 'total_uif_employer' => (float) $run->total_uif_employer,
                'total_sdl' => (float) $run->total_sdl, 'total_eti' => (float) $run->total_eti,
                'total_net' => (float) $run->total_net,
                'run_type' => $run->run_type, 'frequency' => $run->frequency,
                'pay_date' => $run->pay_date?->toDateString(),
                // Who signed it off and when — one of the run fields the specification asks for,
                // and the answer to the only question anybody asks about a disputed payment.
                'approved_by' => $run->approver?->name,
                'approved_at' => $run->approved_at?->toDateTimeString(),
                'processed_at' => $run->processed_at?->toDateTimeString(),
                'paid_at' => $run->paid_at?->toDateTimeString(),
                'completed_at' => $run->completed_at?->toDateTimeString(),
                'reopen_reason' => $run->reopen_reason,
                'reopened_at' => $run->reopened_at?->toDateTimeString(),
                'stage' => PayRunLifecycle::position($run->status),
                'stages' => PayRunLifecycle::ORDER,
            ],
            'emp201'   => $this->runs->emp201($run),
            // Who is on this run, and how they got here — the automatic ones read differently from
            // the ones an officer chose.
            'roster'   => $run->roster()->with('employee:id,uuid,first_name,last_name,employee_number,status')->get()
                ->map(fn ($r) => [
                    'uuid'   => $r->employee?->uuid,
                    'name'   => trim(($r->employee?->first_name ?? '') . ' ' . ($r->employee?->last_name ?? '')),
                    'number' => $r->employee?->employee_number,
                    'status' => $r->employee?->status,
                    'via'    => $r->added_via,
                ])->values(),
            // Where it stands in the chain, and whose signature it is waiting for.
            'approval' => [
                'steps' => $approvals->stepsFor($company)->map(fn ($s) => [
                    'sequence' => $s->sequence, 'name' => $s->name,
                    'approver' => $s->approverLabel(),
                    'current'  => $s->sequence === $run->current_step,
                    'done'     => $run->current_step === null || $s->sequence < $run->current_step,
                ])->values(),
                'current_step' => $run->current_step,
                'round'        => $run->approval_round,
                'can_sign'     => (bool) $approvals->currentStep($run)?->mayBeSignedBy(Auth::user(), $run->created_by),
                'history'      => $approvals->historyFor($run)->map(fn ($a) => [
                    'step' => $a->step_name, 'decision' => $a->decision,
                    'by' => $a->decidedBy?->name, 'at' => $a->decided_at?->toDateTimeString(),
                    'comment' => $a->comment, 'round' => $a->round,
                ])->values(),
            ],
            // Everything wrong with the run, before anybody approves it. Shown on the screen rather
            // than behind a button: a check nobody runs is a check that does not exist, and each of
            // these otherwise gets discovered by the employee on payday.
            'validation' => $run->payslips()->exists() ? $this->validation->validate($run) : null,
            // Nobody should be typing a uuid. The list is built here so it is scoped to this client
            // by construction — a bureau agent working across two clients cannot be offered somebody
            // from the other one, whatever the browser sends.
            'employees' => $company->employees()
                ->orderByRaw("case when status = 'active' then 0 else 1 end")
                ->orderBy('last_name')
                ->get(['uuid', 'first_name', 'last_name', 'employee_number', 'id_number', 'passport_number', 'status'])
                ->map(fn (BureauEmployee $e) => [
                    'uuid'   => $e->uuid,
                    'name'   => trim($e->first_name . ' ' . $e->last_name),
                    'number' => $e->employee_number,
                    // A register with two people of the same name needs something to tell them
                    // apart, and the number alone is not always known to whoever is looking.
                    'ident'  => $e->id_number ?: $e->passport_number,
                    'status' => $e->status,
                ]),
            'payslips' => $run->payslips()->with('employee:id,uuid,first_name,last_name')->get()->map(fn (BureauPayslip $p) => [
                'uuid' => $p->uuid, 'name' => ($p->employee_snapshot['name'] ?? ''),
                'gross' => (float) $p->gross, 'paye' => (float) $p->paye,
                'uif' => (float) $p->uif_employee, 'net' => (float) $p->net_pay,
                'locked' => $p->is_locked,
            ]),
        ]);
    }

    public function process(BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        $this->runs->process($run);

        return back()->with('success', 'Payslips generated and calculated.');
    }

    /** Sign the step this run is standing on. */
    public function approve(Request $request, BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:500']]);
        $after = $this->runs->approve($run, $data['comment'] ?? null);

        return back()->with('success', $after->status === PayRunLifecycle::APPROVED
            ? 'Run approved.'
            : 'Step approved — the run moves to the next approver.');
    }

    /** Send it back to whoever prepared it, with what needs fixing. */
    public function reject(Request $request, BureauCompany $company, BureauPayRun $run, BureauApprovalService $approvals): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $approvals->reject($run, $data['reason']);

        return back()->with('success', 'Run sent back for correction.');
    }

    /** Put somebody on this run. */
    public function addEmployees(Request $request, BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        $data = $request->validate([
            'employees'   => ['required', 'array', 'min:1'],
            'employees.*' => ['string'],
        ]);

        $added = 0;

        // Scoped to this client, like every other lookup on this run. The picker only ever offers
        // this company's people, but the roster is the one place a uuid arrives as a list from the
        // browser: unscoped, a uuid belonging to another client of the same bureau was accepted and
        // that person joined this run — paid out of the wrong client's account, on their payslip.
        foreach ($company->employees()->whereIn('uuid', $data['employees'])->get() as $employee) {
            $added += $this->runs->addToRoster($run, (int) $employee->id) ? 1 : 0;
        }

        return back()->with('success', $added === 1
            ? '1 employee added to the run.'
            : "{$added} employees added to the run.");
    }

    /**
     * Capture overtime hours — for one employee or for several at once.
     *
     * Hours rather than an amount: a bureau's clients do not clock into this system, and asking an
     * officer to work out what the hours are worth is asking them to do the BCEA arithmetic per
     * person by hand.
     */
    public function addOvertime(Request $request, BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        $data = $request->validate([
            'kind'        => ['required', 'in:overtime,sunday_time,public_holiday_time'],
            'hours'       => ['required', 'numeric', 'gt:0', 'max:400'],
            'employees'   => ['required', 'array', 'min:1'],
            'employees.*' => ['string'],
        ]);

        $ids = BureauEmployee::whereIn('uuid', $data['employees'])
            ->where('bureau_company_id', $company->id)
            ->pluck('id')
            ->all();

        $result = $this->runs->addOvertimeForMany($run, $ids, $data['kind'], (float) $data['hours']);

        $message = sprintf('%s captured for %d employee(s) — R%s. Re-run "Generate & calculate" to apply it.',
            \App\Modules\HR\Payroll\Models\Bureau\BureauPayrunInput::HOUR_KIND_LABELS[$data['kind']],
            $result['applied'], number_format($result['total'], 2));

        if ($result['skipped'] !== []) {
            $message .= ' Skipped: ' . implode('; ', array_slice($result['skipped'], 0, 5)) . '.';
        }

        return back()->with('success', $message);
    }

    /** Take somebody off it. */
    public function removeEmployee(BureauCompany $company, BureauPayRun $run, BureauEmployee $employee): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        abort_unless($employee->bureau_company_id === $company->id, 404);

        $this->runs->removeFromRoster($run, (int) $employee->id);

        return back()->with('success', 'Employee removed from the run.');
    }

    public function finalise(BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        $this->runs->finalise($run);

        return back()->with('success', 'Run finalised — payslips locked and encrypted.');
    }

    /**
     * Record that the money has gone.
     *
     * Explicit rather than inferred from a bank-file download, because most South African bureaus
     * still pay by uploading the file to the bank themselves, and a download is somebody looking at
     * it — not evidence that anybody was paid.
     */
    public function markPaid(BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        $this->runs->markPaid($run, ['recorded_manually' => true]);

        return back()->with('success', 'Payment recorded as released.');
    }

    public function complete(BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        $this->runs->complete($run);

        return back()->with('success', 'Run completed.');
    }

    /** The authorised amendment: a signed-off run opened back up, with the reason on the record. */
    public function reopen(Request $request, BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $this->runs->reopen($run, $data['reason']);

        return back()->with('success', 'Run reopened. The reason is on the audit record.');
    }

    /** Email each finalised, encrypted payslip to the employee (only they can open it). */
    public function email(BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        abort_unless($run->isFinalised(), 422, 'Finalise the run before emailing payslips.');

        $sent = 0;
        foreach ($run->payslips()->with('employee')->get() as $payslip) {
            $employee = $payslip->employee;
            if (! $employee?->email || ! $payslip->hasBytes()) {
                continue;
            }
            Mail::to($employee->email)->queue(new \App\Modules\HR\Payroll\Mail\BureauPayslipMail(
                $company->name, $employee->fullName(), $run->name, (string) $payslip->bytes(),
            ));
            $sent++;
        }

        return back()->with('success', "Emailed {$sent} encrypted payslip(s).");
    }

    /** Download a finalised, encrypted payslip PDF (open-password = the employee's payslip key). */
    public function downloadPayslip(BureauCompany $company, BureauPayRun $run, BureauPayslip $payslip): Response
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id && $payslip->bureau_pay_run_id === $run->id, 404);
        abort_if(! $payslip->hasBytes(), 404, 'Payslip not finalised yet.');

        return response((string) $payslip->bytes(), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="payslip-'.$payslip->uuid.'.pdf"',
        ]);
    }

    /** Generate the bank salary file for a finalised run and store it encrypted. */
    public function generateBankFile(BureauCompany $company, BureauPayRun $run, \App\Modules\HR\Payroll\Services\Bureau\BankFileService $bank): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        $file = $bank->generate($run);
        $run->forceFill([
            'bank_file_data'         => \Illuminate\Support\Facades\Crypt::encryptString($file['content']),
            'bank_file_name'         => $file['filename'],
            'bank_file_generated_at' => now(),
        ])->save();

        $msg = "Bank file generated for {$file['count']} employee(s), total R ".number_format($file['total'], 2).'.';
        if (! empty($file['skipped'])) {
            $msg .= ' Skipped: '.implode('; ', $file['skipped']).'.';
        }

        return back()->with('success', $msg);
    }

    /** Authenticated, scoped download of the (decrypted) bank file. */
    public function downloadBankFile(BureauCompany $company, BureauPayRun $run): Response
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        abort_if(blank($run->bank_file_data), 404, 'No bank file generated yet.');

        return response(\Illuminate\Support\Facades\Crypt::decryptString($run->bank_file_data), 200, [
            'Content-Type'        => 'text/plain',
            'Content-Disposition' => 'attachment; filename="'.($run->bank_file_name ?: 'salaries.txt').'"',
        ]);
    }

    /** Add a once-off input (bonus/13th, retro, leave pay-out, severance, garnishee). */
    public function addInput(Request $request, BureauCompany $company, BureauPayRun $run): RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        $data = $request->validate([
            'employee_uuid'    => ['required', 'string'],
            'kind'             => ['required', 'in:bonus,retro,leave_payout,severance,garnishee,unpaid_leave,other_earning,other_deduction'],
            'description'      => ['nullable', 'string', 'max:120'],
            'amount'           => ['nullable', 'numeric', 'min:0.01'],
            'days'             => ['nullable', 'numeric', 'min:0.5'],
            'sars_source_code' => ['nullable', 'string', 'max:10'],
        ]);
        $employee = $company->employees()->where('uuid', $data['employee_uuid'])->firstOrFail();
        $this->runs->addInput($run, $employee, $data);

        return back()->with('success', 'Once-off input added.');
    }

    /** Download the GL accounting journal (CSV) for a run. */
    public function glJournal(BureauCompany $company, BureauPayRun $run, \App\Modules\HR\Payroll\Services\Bureau\GlJournalService $gl): Response
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        return response($gl->csv($run), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="gl-journal-'.$run->uuid.'.csv"',
        ]);
    }

    /** Download the EMP201 monthly declaration (CSV) for a run. */
    public function emp201Csv(BureauCompany $company, BureauPayRun $run, \App\Modules\HR\Payroll\Services\Bureau\StatutoryExportService $exports): Response
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);

        $this->audit->run($run, BureauAuditService::EMP201_GENERATED, "EMP201 exported for {$company->name}.", [
            'period' => $run->period_end->format('Y-m'), 'format' => 'csv',
        ]);

        return response($exports->emp201Csv($run), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="emp201-'.$run->period_end->format('Y-m').'.csv"',
        ]);
    }

    /** File the run's EMP201 into the shared SARS submission history (versioned, with a PDF). */
    public function fileEmp201(BureauCompany $company, BureauPayRun $run, \App\Modules\HR\Payroll\Services\Bureau\BureauStatutoryFilingService $filing): \Illuminate\Http\RedirectResponse
    {
        $this->gate($company);
        abort_unless($run->bureau_company_id === $company->id, 404);
        abort_unless($run->isFinalised(), 422, 'Only a finalised run can be filed.');

        $submission = $filing->fileEmp201($run);

        return back()->with('success', "EMP201 filed as {$submission->reference} (v{$submission->version}) for {$company->name}.");
    }

    /** Download the IRP5/IT3(a) tax-certificate data (CSV) for a tax year. */
    public function irp5Csv(Request $request, BureauCompany $company, \App\Modules\HR\Payroll\Services\Bureau\StatutoryExportService $exports): Response
    {
        $this->gate($company);
        $taxYear = (int) ($request->query('tax_year') ?: $company->tax_year ?: \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::taxYearFor());

        // A tax certificate carries a person's full year of earnings and their tax number. Who took
        // a copy, for which year, is exactly what a client asks after one turns up somewhere it
        // should not have.
        $this->audit->client($company, BureauAuditService::CERTIFICATES_GENERATED, "IRP5/IT3(a) data exported for {$company->name}.", [
            'tax_year' => $taxYear, 'format' => 'csv',
        ]);

        return response($exports->irp5Csv($company, $taxYear), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="irp5-'.$company->uuid.'-'.$taxYear.'.csv"',
        ]);
    }

    /** Download the SARS e@syFile import file (IRP5/IT3a) for a tax year. */
    public function easyFile(Request $request, BureauCompany $company, \App\Modules\HR\Payroll\Services\Bureau\StatutoryExportService $exports): Response
    {
        $this->gate($company);
        $taxYear = (int) ($request->query('tax_year') ?: $company->tax_year ?: \App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine::taxYearFor());

        $this->audit->client($company, BureauAuditService::CERTIFICATES_GENERATED, "e@syFile import generated for {$company->name}.", [
            'tax_year' => $taxYear, 'format' => 'easyfile',
        ]);

        return response($exports->easyFileImport($company, $taxYear), 200, [
            'Content-Type'        => 'text/plain',
            'Content-Disposition' => 'attachment; filename="easyfile-'.$company->uuid.'-'.$taxYear.'.txt"',
        ]);
    }

    private function gate(BureauCompany $company): void
    {
        abort_unless($company->accessibleBy(Auth::user()), 403);
    }
}
