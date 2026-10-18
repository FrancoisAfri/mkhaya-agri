<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Beneficiary;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Models\Remittance;
use App\Modules\HR\Payroll\Services\DeductionFileService;
use App\Modules\HR\Payroll\Services\RemittanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Third-party payments: who is owed what, and whether it has gone.
 *
 * Everything on this screen was previously invisible. Deductions came off the payslip and the only
 * way to know whether the medical aid had been paid was to ask the bank.
 */
class RemittanceController extends Controller
{
    public function __construct(private readonly RemittanceService $remittances)
    {
    }

    public function index(): \Inertia\Response
    {
        $outstanding = $this->remittances->outstanding();

        return Inertia::render('Modules/HR/Payroll/Remittances/Index', [
            'outstanding' => $outstanding->map(fn (Remittance $r) => [
                'uuid'        => $r->uuid,
                'beneficiary' => $r->beneficiary?->name,
                'type'        => $r->beneficiary?->type,
                // Whether this fund is set up for a machine-read file. The button is drawn only
                // then — offering a download that always refuses teaches people to ignore it.
                'has_file'    => (bool) $r->beneficiary?->file_spec_code,
                'period'      => $r->period_start?->toDateString() . ' – ' . $r->period_end?->toDateString(),
                'employees'   => $r->employee_count,
                'employee_portion' => (float) $r->employee_portion,
                'employer_portion' => (float) $r->employer_portion,
                'total'       => (float) $r->total,
                'due_on'      => $r->due_on?->toDateString(),
                'overdue'     => $r->isOverdue(),
                'payable'     => (bool) $r->beneficiary?->isPayable(),
            ]),
            'paid' => Remittance::with('beneficiary')->where('status', 'paid')
                ->latest('paid_at')->limit(25)->get()
                ->map(fn (Remittance $r) => [
                    'uuid' => $r->uuid, 'beneficiary' => $r->beneficiary?->name,
                    'total' => (float) $r->total, 'paid_at' => $r->paid_at?->toDateString(),
                    'reference' => $r->payment_reference,
                ]),
            'beneficiaries' => Beneficiary::orderBy('name')->get()
                ->map(fn (Beneficiary $b) => [
                    'uuid' => $b->uuid, 'name' => $b->name, 'type' => $b->type,
                    'line_types' => $b->line_types ?? [], 'active' => (bool) $b->active,
                    'payable' => $b->isPayable(), 'pay_by_day' => $b->pay_by_day,
                ]),
            'types' => Beneficiary::TYPES,
            // Totals somebody can act on: what is owed, and how much of it is late.
            'summary' => [
                'due_total'     => round((float) $outstanding->sum('total'), 2),
                'overdue_total' => round((float) $outstanding->filter(fn ($r) => $r->isOverdue())->sum('total'), 2),
                'overdue_count' => $outstanding->filter(fn ($r) => $r->isOverdue())->count(),
            ],
        ]);
    }

    public function storeBeneficiary(Request $request): RedirectResponse
    {
        Beneficiary::create($this->validated($request) + ['created_by' => Auth::id()]);

        return back()->with('success', 'Beneficiary added. Their share of the next run will be worked out automatically.');
    }

    public function updateBeneficiary(Request $request, string $uuid): RedirectResponse
    {
        Beneficiary::where('uuid', $uuid)->firstOrFail()->update($this->validated($request, creating: false));

        return back()->with('success', 'Beneficiary updated.');
    }

    /** Recalculates a run's remittances by hand, for a run released before this existed. */
    public function rebuild(string $runUuid): RedirectResponse
    {
        $run = PayrollRun::where('uuid', $runUuid)->firstOrFail();
        $result = $this->remittances->buildForRun($run);

        return back()->with('success', sprintf(
            '%d created, %d updated%s.',
            $result['created'],
            $result['updated'],
            $result['skipped'] > 0 ? ", {$result['skipped']} left alone because they are already paid" : '',
        ));
    }

    public function markPaid(Request $request, string $uuid): RedirectResponse
    {
        $data = $request->validate(['reference' => ['nullable', 'string', 'max:120']]);
        $remittance = Remittance::where('uuid', $uuid)->firstOrFail();

        if ($remittance->status === 'paid') {
            return back()->with('info', 'That payment was already recorded.');
        }

        $this->remittances->markPaid($remittance, $data['reference'] ?? null, Auth::id());

        return back()->with('success', 'Payment recorded.');
    }

    /** The per-employee schedule the fund asks for when it queries a payment. */
    public function download(string $uuid): Response
    {
        $remittance = Remittance::with('beneficiary')->where('uuid', $uuid)->firstOrFail();
        $name = str($remittance->beneficiary?->name ?? 'schedule')->slug();

        return response($this->remittances->toCsv($remittance), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $name . '-' . $remittance->period_end?->format('Y-m') . '.csv"',
        ]);
    }

    /**
     * The vendor deduction file for a remittance — Q Link, PIPEDEL, or any other transcribed layout.
     *
     * Separate from download(), which is the CSV schedule a fund's administrator reads by eye. This
     * one is machine-read on the other end, so it refuses rather than approximates: a missing
     * layout, a missing reference or an empty required field all stop the file with a message
     * naming what to fix.
     */
    public function deductionFile(string $uuid, DeductionFileService $files): Response
    {
        $remittance = Remittance::with('beneficiary')->where('uuid', $uuid)->firstOrFail();

        $file = $files->build($remittance);

        return response($file['contents'], 200, [
            'Content-Type'        => 'text/plain; charset=US-ASCII',
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
        ]);
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'name'           => [$creating ? 'required' : 'sometimes', 'string', 'max:150'],
            'type'           => ['nullable', 'in:' . implode(',', Beneficiary::TYPES)],
            'reference'      => ['nullable', 'string', 'max:80'],
            'bank_name'      => ['nullable', 'string', 'max:80'],
            'branch_code'    => ['nullable', 'string', 'max:20'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'account_type'   => ['nullable', 'string', 'max:20'],
            'contact_email'  => ['nullable', 'email', 'max:150'],
            'line_types'     => ['nullable', 'array'],
            'line_types.*'   => ['string', 'max:40'],
            'pay_by_day'     => ['nullable', 'integer', 'min:1', 'max:28'],
            // Which transcribed layout this fund is sent. Null keeps the CSV schedule, which is
            // still the right answer for a fund that reads a spreadsheet.
            'file_spec_code' => ['nullable', 'string', 'max:60'],
            'employer_code'  => ['nullable', 'string', 'max:40'],
            'active'         => ['nullable', 'boolean'],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
