<?php

namespace App\Modules\HR\Payroll\Services;

use App\Modules\HR\Payroll\Models\DeductionReference;
use App\Modules\HR\Payroll\Models\FileSpecification;
use App\Modules\HR\Payroll\Models\Remittance;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a remittance into the file its third party actually reads.
 *
 * Everything this needs was already here and unconnected: `file_specifications` holds vendor
 * layouts as data, `FileSpecificationRenderer` renders them, and a remittance already stores the
 * per-employee schedule. What was missing was the reference each person is known by at the fund —
 * added alongside this — and the join between the three.
 *
 * ── On the layouts themselves ───────────────────────────────────────────────
 *
 * No Q Link or PIPEDEL layout is shipped. Their byte positions come from vendor documents that are
 * revised on the vendor's schedule and differ per employer code, and a layout guessed from memory
 * produces a file that looks authoritative and is either rejected or, far worse, applied to the
 * wrong accounts. The layout is transcribed once by somebody with the document in front of them —
 * which is what `file_specifications` exists for — and this service then fills it.
 *
 * So the failure mode when nothing is configured is a clear refusal naming what to do, not a
 * plausible-looking file.
 */
class DeductionFileService
{
    public function __construct(private FileSpecificationRenderer $renderer)
    {
    }

    /**
     * The rendered file for one remittance, or a refusal explaining what is missing.
     *
     * @return array{filename: string, contents: string, spec: FileSpecification, rows: int}
     */
    public function build(Remittance $remittance): array
    {
        $beneficiary = $remittance->beneficiary;

        if (! $beneficiary?->file_spec_code) {
            throw ValidationException::withMessages([
                'file' => 'This beneficiary is not set up for a deduction file. Set its file layout '
                    . 'on the beneficiary, then transcribe the layout under Payroll → File layouts. '
                    . 'Until then the CSV schedule is the export.',
            ]);
        }

        // Resolved against the period the file covers, not today: a fund that revised its layout in
        // June still wants May's file in May's layout when it asks for a re-send.
        $spec = FileSpecification::resolve($beneficiary->file_spec_code, $remittance->period_end);

        if (! $spec) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'No active "%s" layout covers %s. Add or extend it under Payroll → File layouts — '
                    . 'the positions and lengths come from the vendor\'s own specification document.',
                    $beneficiary->file_spec_code,
                    $remittance->period_end?->format('F Y') ?? 'this period',
                ),
            ]);
        }

        $rows = $this->rows($remittance);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'file' => 'This remittance has no employees on it, so there is nothing to send.',
            ]);
        }

        // build() rather than render(): a required field left empty is a file the fund rejects, and
        // finding that out from the fund a week later is the outcome this avoids.
        $contents = $this->renderer->build($spec, $this->context($remittance, $spec), $rows);

        return [
            'filename' => $this->filename($remittance, $spec),
            'contents' => $contents,
            'spec'     => $spec,
            'rows'     => count($rows),
        ];
    }

    /**
     * One line per employee, each carrying the reference the fund knows them by.
     *
     * An employee with no reference is not silently dropped and not sent with a blank: either would
     * be money leaving the employer that the fund cannot apply. They are named, and the file is
     * refused until somebody fixes the register.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Remittance $remittance): array
    {
        $schedule = (array) ($remittance->schedule ?? []);

        if ($schedule === []) {
            return [];
        }

        $type = $remittance->bureau_pay_run_id ? DeductionReference::BUREAU : DeductionReference::HR;
        $employeeIds = array_values(array_filter(array_column($schedule, 'employee_id')));

        $references = DeductionReference::query()
            ->where('beneficiary_id', $remittance->beneficiary_id)
            ->where('employee_type', $type)
            ->whereIn('employee_id', $employeeIds)
            ->effectiveOn($remittance->period_end)
            ->get()
            ->keyBy('employee_id');

        $rows = [];
        $missing = [];

        foreach ($schedule as $line) {
            $employeeId = (int) ($line['employee_id'] ?? 0);
            $reference = $references[$employeeId] ?? null;

            if (! $reference) {
                $missing[] = $line['name'] ?: "#{$employeeId}";

                continue;
            }

            $rows[] = ['employee' => [
                'id'              => $employeeId,
                'name'            => (string) ($line['name'] ?? ''),
                'employee_number' => (string) ($line['employee_number'] ?? ''),
                'id_number'       => (string) ($line['id_number'] ?? ''),
                'reference'       => $reference->reference,
                'secondary_reference' => (string) $reference->secondary_reference,
                'deduction_type'  => (string) $reference->deduction_type,
                // The employee's own deduction and the employer's contribution stay separate: a
                // fund's file distinguishes them and the two are not interchangeable when a
                // payment is queried.
                'amount'          => round((float) ($line['employee'] ?? 0), 2),
                'employer_amount' => round((float) ($line['employer'] ?? 0), 2),
                'total'           => round((float) ($line['employee'] ?? 0) + (float) ($line['employer'] ?? 0), 2),
                'instalment'      => $reference->instalment !== null ? (float) $reference->instalment : null,
                'total_amount'    => $reference->total_amount !== null ? (float) $reference->total_amount : null,
            ]];
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    '%d employee(s) have no %s reference for this period, so the fund could not apply '
                    . 'their money: %s. Capture their reference numbers, then build the file again.',
                    count($missing),
                    $remittance->beneficiary?->name ?? 'beneficiary',
                    implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? '…' : ''),
                ),
            ]);
        }

        return $rows;
    }

    /** @return array<string, mixed> the header/trailer values a layout can address */
    private function context(Remittance $remittance, FileSpecification $spec): array
    {
        $beneficiary = $remittance->beneficiary;

        return [
            'company' => [
                'name' => (string) (DB::table('companies')->where('id', $remittance->company_id)->value('name') ?? ''),
            ],
            'beneficiary' => [
                'name'           => (string) $beneficiary?->name,
                'reference'      => (string) $beneficiary?->reference,
                'employer_code'  => (string) $beneficiary?->employer_code,
                'account_number' => (string) $beneficiary?->account_number,
                'branch_code'    => (string) $beneficiary?->branch_code,
            ],
            'remittance' => [
                'period_start'   => $remittance->period_start?->toDateString(),
                'period_end'     => $remittance->period_end?->toDateString(),
                'employee_total' => (float) $remittance->employee_portion,
                'employer_total' => (float) $remittance->employer_portion,
                'total'          => (float) $remittance->total,
                'employee_count' => (int) $remittance->employee_count,
                'due_on'         => $remittance->due_on?->toDateString(),
            ],
            'spec' => ['code' => $spec->code, 'version' => $spec->version],
        ];
    }

    private function filename(Remittance $remittance, FileSpecification $spec): string
    {
        $fund = str($remittance->beneficiary?->name ?? 'deductions')->slug();
        $period = $remittance->period_end?->format('Ym') ?? date('Ym');

        return "{$spec->code}-{$fund}-{$period}.txt";
    }
}
