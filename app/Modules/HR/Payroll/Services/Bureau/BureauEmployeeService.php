<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Services\SecurePayslipService;
use App\Modules\HR\Payroll\Support\EmploymentCategory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Manages the standalone employees of a bureau client company, including bulk
 * import and the per-employee payslip lock key.
 */
class BureauEmployeeService
{
    public function __construct(
        private readonly SecurePayslipService $secure,
        private readonly BureauAuditService $audit,
    ) {
    }

    /**
     * Add an employee.
     *
     * A client coming off another system already has employee numbers, and those numbers are on
     * their payslips, their files and their own spreadsheets — so a supplied one is kept exactly as
     * given. Only when there is none do we issue the next in the EMPnnnn sequence.
     */
    public function create(BureauCompany $company, array $data): BureauEmployee
    {
        foreach (['first_name', 'last_name'] as $required) {
            if (blank($data[$required] ?? null)) {
                throw ValidationException::withMessages([$required => 'This field is required.']);
            }
        }

        $payload = $this->applyEtiProfile($this->present($this->fillable($data)));
        $supplied = ! blank($payload['employee_number'] ?? null);

        // The unique index is what actually decides. This loop is only for the case where two adds
        // read the same "next" number before either of them inserts.
        for ($attempt = 0; ; $attempt++) {
            if (! $supplied) {
                $payload['employee_number'] = $this->nextEmployeeNumber($company);
            }

            try {
                $employee = $company->employees()->create($payload + ['created_by' => auth()->id()]);

                $this->audit->client(
                    $company,
                    BureauAuditService::EMPLOYEE_CREATED,
                    "{$employee->fullName()} added to {$company->name}.",
                    ['employee_number' => $employee->employee_number, 'employee_id' => $employee->id],
                );

                return $employee;
            } catch (UniqueConstraintViolationException $e) {
                // A number the client typed themselves is theirs to correct — quietly issuing a
                // different one would hand them an employee they cannot find in their own records.
                if ($supplied) {
                    throw ValidationException::withMessages([
                        'employee_number' => "Employee number {$payload['employee_number']} is already used at {$company->name}.",
                    ]);
                }

                if ($attempt >= 4) {
                    throw $e;
                }
            }
        }
    }

    public function update(BureauEmployee $employee, array $data): BureauEmployee
    {
        $employee->fill($this->applyEtiProfile($this->fillable($data), $employee));

        // Recorded before the save, while getDirty() still holds what changed. Banking, tax and
        // incentive details are the fields that move money or change what SARS is told, and "who
        // changed this account number the day before payment" is the question nobody could answer.
        $this->audit->employeeChanges($employee);

        $employee->save();

        return $employee;
    }

    /**
     * Start the incentive clock the moment somebody is recorded as an intern or a learner.
     *
     * This is the category the Employment Tax Incentive exists for, so requiring an administrator to
     * separately tick an opt-in box is asking them to remember something the record already says.
     * Forgetting it is invisible — nothing is wrong on the payslip, the claim simply never happens —
     * and by the time anybody notices, months of a benefit the client was entitled to have gone.
     *
     * Being in the category does not earn the incentive. Age and remuneration still decide that, run
     * by run, and every month is recorded in the ledger with its reason either way. This only means
     * the employer is opted in and the clock starts on the right day.
     *
     * Never switched off automatically: moving somebody from intern to permanent is a promotion, not
     * a reason to stop claiming the months they still have.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyEtiProfile(array $payload, ?BureauEmployee $existing = null): array
    {
        $category = $payload['employment_category'] ?? $existing?->employment_category;

        if (! EmploymentCategory::tracksEti($category)) {
            return $payload;
        }

        $payload['eti_opt_in'] = true;

        // The date the incentive starts counting from, which is usually — but not always — the day
        // they started work. An existing date is never overwritten: somebody may have set a later
        // one on purpose, for a learner who turns eighteen in post.
        if (blank($existing?->eti_start_date) && blank($payload['eti_start_date'] ?? null)) {
            $payload['eti_start_date'] = $payload['start_date'] ?? $existing?->start_date;
        }

        return $payload;
    }

    /**
     * Bulk import a client's register.
     *
     * A file from a client is usually the register they already keep, so a row whose employee
     * number is on file is the same person rather than a new one, and updates them. That makes a
     * re-upload after a correction safe — which the previous behaviour was not: it created
     * everything unconditionally, so a second upload produced a duplicate of every employee under a
     * freshly issued number.
     *
     * Rows missing a name are skipped rather than guessed at. Rows missing a start date are
     * imported but counted, because they will not accrue leave until somebody supplies one.
     *
     * @return array{created:int, updated:int, skipped:int, without_start_date:int}
     */
    public function import(BureauCompany $company, array $rows): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'without_start_date' => 0];

        foreach ($rows as $row) {
            if (blank($row['first_name'] ?? null) || blank($row['last_name'] ?? null)) {
                $result['skipped']++;
                continue;
            }

            if (blank($row['start_date'] ?? null)) {
                $result['without_start_date']++;
            }

            $number = trim((string) ($row['employee_number'] ?? ''));
            $existing = $number === ''
                ? null
                : $company->employees()->where('employee_number', $number)->first();

            if ($existing) {
                // Sparse on purpose: a column the client left blank must not wipe a value already
                // on file, or a partial re-upload silently strips everyone's banking details.
                // Through the same ETI trigger as the form: an intern arriving by spreadsheet is
                // still an intern, and the clock has to start for them too.
                $existing->fill($this->applyEtiProfile($this->present($this->fillable($row)), $existing));
                // A spreadsheet changing three hundred bank accounts is exactly the event that has
                // to be on the trail, so the import audits the same way the form does.
                $this->audit->employeeChanges($existing);
                $existing->save();
                $result['updated']++;
                continue;
            }

            $this->create($company, $row);
            $result['created']++;
        }

        return $result;
    }

    /** Mark an employee terminated (their final settlement goes on a termination run). */
    public function terminate(BureauEmployee $employee, string $endDate): BureauEmployee
    {
        $employee->update(['status' => BureauEmployee::STATUS_TERMINATED, 'end_date' => $endDate]);

        if ($company = $employee->company) {
            $this->audit->client(
                $company,
                BureauAuditService::EMPLOYEE_TERMINATED,
                "{$employee->fullName()} terminated with effect from {$endDate}.",
                ['employee_number' => $employee->employee_number, 'employee_id' => $employee->id],
            );
        }

        return $employee;
    }

    /** Generate (or rotate) the employee's payslip key and return the plaintext once. */
    public function generatePayslipKey(BureauEmployee $employee): string
    {
        $key = $this->secure->generateKey();
        $employee->payslip_key = $key; // encrypted by the model setter
        $employee->save();

        return $key;
    }

    /** @return Collection<int,BureauEmployee> */
    public function activeFor(BureauCompany $company): Collection
    {
        return $company->employees()->where('status', BureauEmployee::STATUS_ACTIVE)->orderBy('last_name')->get();
    }

    /**
     * The next employee number for a client, read from the highest EMPnnnn already issued.
     *
     * Counting the employees was wrong twice over: a terminated employee removed from the count
     * made the next number collide with one still in use, and two adds landing together counted the
     * same total and picked the same number.
     */
    private function nextEmployeeNumber(BureauCompany $company): string
    {
        $highest = 0;

        foreach ($company->employees()->pluck('employee_number') as $number) {
            if (preg_match('/^EMP(\d+)$/i', (string) $number, $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'EMP' . str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Drop the keys the caller left empty, so a blank cell cannot overwrite a real value. */
    private function present(array $data): array
    {
        return array_filter($data, fn ($value) => ! blank($value));
    }

    private function fillable(array $data): array
    {
        $keys = [
            'employee_number', 'first_name', 'last_name', 'id_number', 'passport_number', 'date_of_birth',
            'tax_number', 'email', 'phone', 'start_date', 'end_date', 'status', 'occupation',
            'employment_category', 'eti_start_date', 'pay_frequency',
            'payment_type', 'basic_salary', 'pay_rate', 'bank_name', 'branch_code', 'account_number',
            'account_type', 'account_holder', 'medical_members', 'medical_dependants', 'medical_contribution',
            'retirement_contribution', 'tax_directive_percentage', 'eti_opt_in',
        ];

        return array_intersect_key($data, array_flip($keys));
    }
}
