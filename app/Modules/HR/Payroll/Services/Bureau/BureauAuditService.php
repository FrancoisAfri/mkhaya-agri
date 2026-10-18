<?php

namespace App\Modules\HR\Payroll\Services\Bureau;

use App\Modules\HR\Payroll\Models\Bureau\BureauCompany;
use App\Modules\HR\Payroll\Models\Bureau\BureauEmployee;
use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;
use App\Modules\HR\Payroll\Models\PayrollAuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Who did what to a client's payroll.
 *
 * The bureau engine recorded nothing. It runs other companies' payrolls, files their EMP201s and
 * releases their money, and there was no answer to who processed a run, who reopened a finalised
 * one, or who changed an employee's bank account the day before payment. For a service bureau that
 * is not a missing feature — it is the first thing a client's auditor asks for, and the only
 * defence when a payment is disputed.
 *
 * Writes to the same table as the in-house engine. A compliance question is asked about a company,
 * not about which of our two engines happened to run its payroll, and two trails means every report
 * has to remember to union them — which one of them eventually will not.
 *
 * Failures are logged and swallowed. An audit row that cannot be written must not roll back the
 * payroll it was describing; the run completing matters more, and a gap in the trail is visible in
 * a way a failed month is not.
 */
class BureauAuditService
{
    // Run lifecycle.
    public const RUN_CREATED = 'bureau.run.created';
    public const RUN_PROCESSED = 'bureau.run.processed';
    public const RUN_APPROVED = 'bureau.run.approved';
    public const RUN_REJECTED = 'bureau.run.rejected';
    public const ROSTER_CHANGED = 'bureau.run.roster_changed';
    public const RUN_FINALISED = 'bureau.run.finalised';
    public const RUN_PAID = 'bureau.run.paid';
    public const RUN_COMPLETED = 'bureau.run.completed';
    public const RUN_REOPENED = 'bureau.run.reopened';

    // Statutory and payment — the actions that leave the building.
    public const EMP201_GENERATED = 'bureau.emp201.generated';
    public const EMP201_FILED = 'bureau.emp201.filed';
    public const CERTIFICATES_GENERATED = 'bureau.certificates.generated';
    public const BANK_FILE_GENERATED = 'bureau.bankfile.generated';
    public const PAYSLIPS_EMAILED = 'bureau.payslips.emailed';
    public const DISBURSEMENT_SENT = 'bureau.disbursement.sent';
    public const DISBURSEMENT_RESPONSE = 'bureau.disbursement.response';

    // Employee changes that move money or change what SARS is told.
    public const EMPLOYEE_CREATED = 'bureau.employee.created';
    public const BANKING_CHANGED = 'bureau.employee.banking_changed';
    public const TAX_DETAILS_CHANGED = 'bureau.employee.tax_changed';
    public const ETI_CHANGED = 'bureau.employee.eti_changed';
    public const PAY_FREQUENCY_CHANGED = 'bureau.employee.frequency_changed';
    public const EMPLOYEE_TERMINATED = 'bureau.employee.terminated';

    /**
     * The employee fields worth a line of their own.
     *
     * Not everything: a corrected spelling of a surname is noise, and a trail full of noise is one
     * nobody reads. These are the fields that change where money goes, what SARS is told, or which
     * run somebody lands in — the three ways a payroll goes wrong expensively.
     */
    private const WATCHED = [
        'bank_name' => self::BANKING_CHANGED,
        'branch_code' => self::BANKING_CHANGED,
        'account_number' => self::BANKING_CHANGED,
        'account_type' => self::BANKING_CHANGED,
        'account_holder' => self::BANKING_CHANGED,
        'tax_number' => self::TAX_DETAILS_CHANGED,
        'id_number' => self::TAX_DETAILS_CHANGED,
        'passport_number' => self::TAX_DETAILS_CHANGED,
        'tax_directive_percentage' => self::TAX_DETAILS_CHANGED,
        'eti_opt_in' => self::ETI_CHANGED,
        'eti_start_date' => self::ETI_CHANGED,
        'employment_category' => self::ETI_CHANGED,
        'pay_frequency' => self::PAY_FREQUENCY_CHANGED,
    ];

    /** Record something that happened to a run. */
    public function run(BureauPayRun $run, string $action, string $description, array $meta = []): void
    {
        $this->write($action, $description, [
            'bureau_pay_run_id' => $run->id,
            'bureau_company_id' => $run->bureau_company_id,
            'subject_type' => BureauPayRun::class,
            'subject_id' => $run->id,
            'meta' => $meta + [
                'period_start' => (string) $run->period_start,
                'period_end' => (string) $run->period_end,
                'frequency' => $run->frequency,
                'status' => $run->status,
            ],
        ]);
    }

    /** Record something that happened to a client, with no particular run attached. */
    public function client(BureauCompany $company, string $action, string $description, array $meta = []): void
    {
        $this->write($action, $description, [
            'bureau_company_id' => $company->id,
            'subject_type' => BureauCompany::class,
            'subject_id' => $company->id,
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * Record the changes to an employee that matter, one line each, with the old and new value.
     *
     * Called with the model *after* fill but *before* save, so `getDirty()` still holds the change.
     * Returns how many lines were written, which is only really useful in a test.
     */
    public function employeeChanges(BureauEmployee $employee): int
    {
        $written = 0;

        foreach ($this->groupedChanges($employee) as $action => $changes) {
            $this->write($action, $this->describe($action, $employee), [
                'bureau_company_id' => $employee->bureau_company_id,
                'subject_type' => BureauEmployee::class,
                'subject_id' => $employee->id,
                'old_values' => array_map(fn ($c) => $c['old'], $changes),
                'new_values' => array_map(fn ($c) => $c['new'], $changes),
                'meta' => [
                    'employee_number' => $employee->employee_number,
                    'employee_name' => trim("{$employee->first_name} {$employee->last_name}"),
                ],
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * The dirty attributes we care about, grouped by the action they belong to.
     *
     * Banking details are one line even when three columns changed together, because a person
     * changing an account number changes the branch code with it and three rows describing one act
     * makes the trail harder to read, not more complete.
     *
     * @return array<string, array<string, array{old: mixed, new: mixed}>>
     */
    private function groupedChanges(BureauEmployee $employee): array
    {
        $grouped = [];

        foreach ($employee->getDirty() as $field => $new) {
            $action = self::WATCHED[$field] ?? null;

            if ($action === null) {
                continue;
            }

            $old = $employee->getOriginal($field);

            // A model filled with the value it already had is not a change. Loose comparison on
            // purpose: a boolean read back from Postgres and one set from a form are the same fact
            // in different types, and reporting that as a change is how a trail fills with noise.
            if ($old == $new) {
                continue;
            }

            $grouped[$action][$field] = [
                // Account numbers are masked here as well as on screen. The trail records *that*
                // banking changed and by whom — reprinting the full number in a log that more people
                // can read than can see the employee record widens the exposure rather than the
                // audit.
                'old' => $this->maskIfSensitive($field, $old),
                'new' => $this->maskIfSensitive($field, $new),
            ];
        }

        return $grouped;
    }

    private function maskIfSensitive(string $field, mixed $value): mixed
    {
        if (! in_array($field, ['account_number', 'id_number', 'passport_number'], true) || blank($value)) {
            return $value;
        }

        $value = (string) $value;

        return strlen($value) <= 4
            ? str_repeat('*', strlen($value))
            : str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }

    private function describe(string $action, BureauEmployee $employee): string
    {
        $who = trim("{$employee->first_name} {$employee->last_name}") ?: "Employee #{$employee->id}";

        return match ($action) {
            self::BANKING_CHANGED => "Banking details changed for {$who}.",
            self::TAX_DETAILS_CHANGED => "Tax details changed for {$who}.",
            self::ETI_CHANGED => "Employment tax incentive details changed for {$who}.",
            self::PAY_FREQUENCY_CHANGED => "Pay frequency changed for {$who}.",
            default => "{$who} updated.",
        };
    }

    /** @param array<string, mixed> $attributes */
    private function write(string $action, string $description, array $attributes): void
    {
        try {
            $request = app()->runningInConsole() ? null : request();

            PayrollAuditLog::create($attributes + [
                'action' => $action,
                'description' => $description,
                'user_id' => Auth::id(),
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            // The payroll matters more than the line describing it, and a gap in the trail is
            // visible in a way a failed month is not.
            Log::warning('Bureau payroll audit failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    /**
     * Anything recorded against a client, newest first — what the bureau's audit screen reads.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PayrollAuditLog>
     */
    public function historyFor(BureauCompany $company, int $limit = 100)
    {
        return PayrollAuditLog::query()
            ->where('bureau_company_id', $company->id)
            // Tie-broken on the id, because a busy minute writes several rows in the same second and
            // ordering on the timestamp alone puts them back in whatever order the database felt
            // like. A trail that reorders itself between two readings is not a trail.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
