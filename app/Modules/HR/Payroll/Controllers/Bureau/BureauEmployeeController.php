<?php

namespace App\Modules\HR\Payroll\Controllers\Bureau;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\EtiClaim;
use App\Modules\HR\Payroll\Services\EtiClaimService;
use App\Modules\HR\Payroll\Services\Bureau\BureauComponentService;
use App\Modules\HR\Payroll\Services\Bureau\BureauEmployeeService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BureauEmployeeController extends Controller
{
    public function __construct(
        private readonly BureauEmployeeService $employees,
        private readonly BureauComponentService $components,
    ) {
    }

    public function index(BureauCompany $company): Response
    {
        $this->gate($company);

        // Loaded once and handed to the rows, so a register of four hundred does not ask the
        // database who each person's manager is four hundred times.
        $employees = $company->employees()->with('manager:id,uuid,first_name,last_name')
            ->orderBy('last_name')->get();

        // How much of each person's twenty-four ETI months is gone. Read in one query for the whole
        // register — asking per employee is how a four-hundred-person client becomes four hundred
        // queries, which is the reason claimedMonthsFor() exists.
        //
        // On the register rather than only on the operations dashboard, because the dashboard warns
        // three months out and this answers the question at any point before that. The incentive
        // ending is a rise in what the client owes SARS, and a bureau that cannot see it coming
        // explains it afterwards.
        $etiMonths = app(EtiClaimService::class)
            ->claimedMonthsFor(EtiClaim::BUREAU, $employees->pluck('id')->all());

        return Inertia::render('Modules/HR/Payroll/Bureau/Employees', [
            'company'   => ['uuid' => $company->uuid, 'name' => $company->name, 'pay_frequency' => $company->pay_frequency],
            'employees' => $employees->map(fn (BureauEmployee $e) => [
                'uuid' => $e->uuid, 'name' => $e->fullName(), 'employee_number' => $e->employee_number,
                'occupation' => $e->occupation, 'basic_salary' => (float) $e->basic_salary,
                'manager'      => $e->manager?->uuid,
                'manager_name' => $e->manager ? trim($e->manager->first_name . ' ' . $e->manager->last_name) : null,
                // The edit form needs the whole record back, not just what the table shows.
                'id_number' => $e->id_number, 'passport_number' => $e->passport_number,
                'tax_number' => $e->tax_number, 'email' => $e->email, 'phone' => $e->phone,
                'payment_type' => $e->payment_type,
                'pay_frequency' => $e->pay_frequency,
                'employment_category' => $e->employment_category,
                'eti_opt_in' => (bool) $e->eti_opt_in,
                'eti_claimed'   => (int) ($etiMonths[$e->id] ?? 0),
                'eti_remaining' => max(0, EtiClaim::MAX_MONTHS - (int) ($etiMonths[$e->id] ?? 0)),
                'bank_name' => $e->bank_name, 'branch_code' => $e->branch_code,
                'account_number' => $e->account_number,
                'medical_members' => (int) $e->medical_members,
                'medical_dependants' => (int) $e->medical_dependants,
                'retirement_contribution' => (float) $e->retirement_contribution,
                // Surfaced so the register shows who is missing the date their leave accrues from.
                'start_date' => $e->start_date?->toDateString(),
                // Months this employee worked before the accrual engine ever saw them. The cycle is
                // resolved once above rather than per row — this is a register, not a detail page.
                'backfill_months' => 0,
                'status' => $e->status, 'has_key' => $e->hasPayslipKey(),
            ]),
            'components' => $company->components()->where('active', true)->get()
                ->map(fn ($c) => ['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'type' => $c->type]),
        ]);
    }

    public function store(Request $request, BureauCompany $company): RedirectResponse
    {
        $this->gate($company);
        $this->employees->create($company, $this->validated($request, $company));

        return back()->with('success', 'Employee added.');
    }

    public function update(Request $request, BureauCompany $company, BureauEmployee $employee): RedirectResponse
    {
        $this->gate($company);
        abort_unless($employee->bureau_company_id === $company->id, 404);
        $this->employees->update($employee, $this->validated($request, $company, $employee));

        return back()->with('success', 'Employee updated.');
    }

    /** Generate/rotate the employee's payslip key; the plaintext is shown once. */
    public function generateKey(BureauCompany $company, BureauEmployee $employee): RedirectResponse
    {
        $this->gate($company);
        abort_unless($employee->bureau_company_id === $company->id, 404);
        $key = $this->employees->generatePayslipKey($employee);

        return back()->with('payslip_key', $key)->with('success', 'Payslip key generated — share it with the employee. It will not be shown again.');
    }

    /** Mark an employee terminated. */
    public function terminate(Request $request, BureauCompany $company, BureauEmployee $employee): RedirectResponse
    {
        $this->gate($company);
        abort_unless($employee->bureau_company_id === $company->id, 404);
        $data = $request->validate(['end_date' => ['required', 'date']]);
        $this->employees->terminate($employee, $data['end_date']);

        return back()->with('success', 'Employee terminated.');
    }

    /**
     * Column order for the bulk-import CSV template.
     *
     * employee_number leads because it is the column that decides identity: it is what a client's
     * own records are keyed on, what the Leave Bureau importer matches people by, and what makes a
     * second upload of a corrected file update people rather than duplicate them. Leaving it out —
     * as this template did — meant every imported employee lost their real number and was issued an
     * EMPnnnn nobody at the client recognised.
     *
     * start_date is here for the same reason: leave accrues from it, so an employee imported
     * without one accrues nothing at all.
     */
    private const IMPORT_COLUMNS = [
        'employee_number', 'first_name', 'last_name', 'start_date', 'id_number', 'passport_number',
        'tax_number', 'email', 'phone',
        // Which cycle this person is paid on. Blank inherits the client's — but somebody on a
        // different cycle from their employer can only be paid if it is stated, and until now the
        // column was reachable by nothing at all: not this template, and not the form.
        'occupation', 'employment_category', 'pay_frequency', 'payment_type', 'basic_salary', 'bank_name', 'branch_code', 'account_number',
        'account_holder', 'medical_members', 'medical_dependants', 'medical_contribution', 'retirement_contribution',
    ];

    /** Download a sample CSV the client fills in and uploads. */
    public function template(BureauCompany $company): \Illuminate\Http\Response
    {
        $this->gate($company);
        $h = fopen('php://temp', 'r+');
        fputcsv($h, self::IMPORT_COLUMNS);
        // one illustrative row
        fputcsv($h, ['EMP0001', 'Thabo', 'Mokoena', '2024-03-01', '9001015800086', '', '1234567890', 'thabo@example.com', '0820000000',
            'Technician', 'permanent', '', 'salary', '25000', 'FNB', '250655', '62012345678', 'T Mokoena', '1', '2', '2000', '1500']);
        rewind($h);

        return response(stream_get_contents($h), 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="employee-import-template.csv"',
        ]);
    }

    /** Bulk import employees from an uploaded CSV (header row matches the template). */
    public function import(Request $request, BureauCompany $company): RedirectResponse
    {
        $this->gate($company);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:4096']]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = null;
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($h) => trim(strtolower($h)), $line);
                continue;
            }
            if (count(array_filter($line, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // skip blank lines
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($line[$i]) ? trim((string) $line[$i]) : null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        $result = $this->employees->import($company, $rows);

        // Named rather than totalled: "20 imported" hides that 6 of them were existing people whose
        // details just changed, and that 3 will never accrue leave because they have no start date.
        $message = "{$result['created']} employee(s) added, {$result['updated']} updated.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} row(s) skipped — no first or last name.";
        }
        if ($result['without_start_date'] > 0) {
            $message .= " {$result['without_start_date']} have no start date and will not accrue leave until one is set.";
        }

        return back()->with('success', $message);
    }

    /** Generate (idempotent) + return the employee's self-service portal link. */
    public function portalLink(BureauCompany $company, BureauEmployee $employee): RedirectResponse
    {
        $this->gate($company);
        abort_unless($employee->bureau_company_id === $company->id, 404);
        $token = $employee->ensurePortalToken();

        return back()->with('portal_link', route('payroll.bureau.portal.show', $token))
            ->with('success', 'Self-service payslip link generated — share it with the employee.');
    }

    /**
     * @param  BureauEmployee|null  $employee  the record being edited; null when creating, and the
     *                                         one the uniqueness check must ignore when not
     */
    private function validated(Request $request, BureauCompany $company, ?BureauEmployee $employee = null): array
    {
        $creating = $employee === null;

        return $request->validate([
            // Unique within the client, not globally: two different clients of the same bureau are
            // each entitled to their own EMP0001.
            'employee_number'          => [
                'nullable', 'string', 'max:50',
                Rule::unique('bureau_employees', 'employee_number')
                    ->where(fn ($q) => $q->where('bureau_company_id', $company->id))
                    ->ignore($employee?->id),
            ],
            'first_name'               => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'last_name'                => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'id_number'                => ['nullable', 'string', 'max:20'],
            'passport_number'          => ['nullable', 'string', 'max:30'],
            'date_of_birth'            => ['nullable', 'date'],
            'tax_number'               => ['nullable', 'string', 'max:20'],
            'email'                    => ['nullable', 'email', 'max:255'],
            'phone'                    => ['nullable', 'string', 'max:40'],
            // Required on create: everything about leave hangs off it, and an employee without one
            // silently accrues nothing for as long as nobody notices.
            'start_date'               => [$creating ? 'required' : 'nullable', 'date'],
            'end_date'                 => ['nullable', 'date'],
            'status'                   => ['nullable', 'in:active,terminated,suspended'],
            'occupation'               => ['nullable', 'string', 'max:120'],
            // Who approves this person's leave. Sent as the manager's uuid and resolved below —
            // scoped to this client, because a manager at another employer approving leave here
            // would be both wrong and invisible.
            'manager'                  => [
                'nullable', 'string',
                Rule::exists('bureau_employees', 'uuid')
                    ->where(fn ($q) => $q->where('bureau_company_id', $company->id)),
            ],
            'pay_frequency'            => ['nullable', 'in:monthly,fortnightly,weekly'],
            // The category the incentive is written for. Setting it to intern or learnership starts
            // ETI tracking on the spot, so nobody has to remember a separate box.
            'employment_category'      => ['nullable', 'in:' . implode(',', EmploymentCategory::ALL)],
            'eti_start_date'           => ['nullable', 'date'],
            'payment_type'             => ['nullable', 'in:salary,wage'],
            'basic_salary'             => ['nullable', 'numeric', 'min:0'],
            'pay_rate'                 => ['nullable', 'numeric', 'min:0'],
            'bank_name'                => ['nullable', 'string', 'max:80'],
            'branch_code'              => ['nullable', 'string', 'max:20'],
            'account_number'           => ['nullable', 'string', 'max:40'],
            'account_type'             => ['nullable', 'string', 'max:30'],
            'account_holder'           => ['nullable', 'string', 'max:120'],
            // An employee is at most ONE main member on a medical scheme; cap dependants sanely.
            'medical_members'          => ['nullable', 'integer', 'min:0', 'max:1'],
            'medical_dependants'       => ['nullable', 'integer', 'min:0', 'max:20'],
            'medical_contribution'     => ['nullable', 'numeric', 'min:0'],
            // A retirement contribution cannot exceed the basic salary.
            'retirement_contribution'  => ['nullable', 'numeric', 'min:0', 'lte:basic_salary'],
            'tax_directive_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'eti_opt_in'               => ['sometimes', 'boolean'],
        ], [
            'employee_number.unique' => 'That employee number is already used at this client.',
            'start_date.required'    => 'A start date is needed — leave accrues from it, and a mid-year joiner is pro-rated from it.',
            'manager.exists'         => 'That manager is not on this client\'s register.',
        ]);

        return $this->withManager($data, $company, $employee);
    }

    /**
     * Turn the manager's uuid into the id the column holds.
     *
     * Nobody may be their own manager. It sounds like a silly thing to guard, but it is one careless
     * click on an edit form, and the result is an employee whose leave waits for their own approval
     * for ever with no obvious reason why.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withManager(array $data, BureauCompany $company, ?BureauEmployee $employee): array
    {
        if (! array_key_exists('manager', $data)) {
            return $data;
        }

        $uuid = $data['manager'];
        unset($data['manager']);

        if (blank($uuid)) {
            $data['manager_employee_id'] = null;

            return $data;
        }

        $manager = BureauEmployee::query()
            ->where('bureau_company_id', $company->id)->where('uuid', $uuid)->firstOrFail();

        if ($employee && $manager->id === $employee->id) {
            throw ValidationException::withMessages([
                'manager' => 'Somebody cannot approve their own leave.',
            ]);
        }

        $data['manager_employee_id'] = $manager->id;

        return $data;
    }

    private function gate(BureauCompany $company): void
    {
        abort_unless($company->accessibleBy(Auth::user()), 403);
    }
}
