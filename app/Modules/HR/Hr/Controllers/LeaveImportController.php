<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Leave\Models\LeaveCycle;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LeaveImportController extends Controller
{
    // ── Column specs per import type ──────────────────────────────────────────
    private const COLUMNS = [
        'balance'    => ['employee_number', 'leave_type', 'transaction_type', 'hours', 'description', 'date'],
        'leave'      => ['employee_number', 'leave_type', 'start_date', 'end_date', 'status', 'notes'],
        'sick_leave' => ['employee_number', 'pattern_type', 'detection_date', 'pattern_start', 'pattern_end', 'occurrence_count'],
        'payout'     => ['employee_number', 'leave_type', 'hours_paid_out', 'amount', 'payout_date', 'payroll_reference'],
    ];

    private const BALANCE_TYPES = [
        'opening_balance', 'allocation', 'manual_adjustment', 'correction', 'carryover', 'forfeiture',
    ];

    private const SICK_PATTERN_TYPES = [
        'monday_absence', 'friday_absence', 'monday_friday_pattern',
        'pre_holiday', 'post_holiday', 'consecutive_3_plus', '8_week_rolling', 'excessive_sick',
    ];

    public function index(): Response
    {
        return Inertia::render('HR/Leave/LeaveImport', [
            'importTypes' => [
                [
                    'key'         => 'balance',
                    'label'       => 'Bulk Balance Import',
                    'description' => 'Import opening balances, allocations, or manual adjustments for multiple employees.',
                    'icon'        => 'ri-scales-3-line',
                    'color'       => 'primary',
                    'columns'     => self::COLUMNS['balance'],
                ],
                [
                    'key'         => 'leave',
                    'label'       => 'Bulk Leave Import',
                    'description' => 'Import historical approved leave records for multiple employees.',
                    'icon'        => 'ri-calendar-check-line',
                    'color'       => 'success',
                    'columns'     => self::COLUMNS['leave'],
                ],
                [
                    'key'         => 'sick_leave',
                    'label'       => 'Historical Sick Leave',
                    'description' => 'Import historical sick leave patterns for compliance tracking.',
                    'icon'        => 'ri-heart-pulse-line',
                    'color'       => 'warning',
                    'columns'     => self::COLUMNS['sick_leave'],
                ],
                [
                    'key'         => 'payout',
                    'label'       => 'Historical Payout Import',
                    'description' => 'Import historical leave payout records.',
                    'icon'        => 'ri-money-dollar-circle-line',
                    'color'       => 'info',
                    'columns'     => self::COLUMNS['payout'],
                ],
            ],
        ]);
    }

    public function downloadTemplate(string $type): StreamedResponse
    {
        abort_unless(array_key_exists($type, self::COLUMNS), 404);

        $columns = self::COLUMNS[$type];
        $example = $this->templateExampleRow($type);

        return response()->streamDownload(function () use ($columns, $example) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);
            fputcsv($handle, $example);
            fclose($handle);
        }, "template_{$type}.csv", ['Content-Type' => 'text/csv']);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
            'type' => 'required|in:balance,leave,sick_leave,payout',
        ]);

        $type    = $request->input('type');
        $columns = self::COLUMNS[$type];

        // Store file temporarily for later import
        $tempId   = Str::uuid()->toString();
        $tempPath = "imports/{$tempId}." . $request->file('file')->getClientOriginalExtension();
        Storage::disk('local')->put($tempPath, file_get_contents($request->file('file')->getRealPath()));

        $rows   = $this->parseFile($request->file('file'));
        $valid  = [];
        $errors = [];

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex === 0 && $this->isHeaderRow($row, $columns)) {
                continue; // skip header
            }

            $mapped = [];
            foreach ($columns as $i => $col) {
                $mapped[$col] = trim((string) ($row[$i] ?? ''));
            }

            $rowErrors = $this->validateRow($type, $mapped, $rowIndex + 1);

            if (empty($rowErrors)) {
                $valid[] = array_merge($mapped, ['_row' => $rowIndex + 1]);
            } else {
                $errors[] = array_merge($mapped, ['_row' => $rowIndex + 1, '_errors' => $rowErrors]);
            }
        }

        // Cache temp path keyed by tempId (1 hour TTL)
        Cache::put("import_temp_{$tempId}", [
            'path'    => $tempPath,
            'type'    => $type,
            'columns' => $columns,
        ], now()->addHour());

        return response()->json([
            'temp_id'     => $tempId,
            'valid_count' => count($valid),
            'error_count' => count($errors),
            'valid_rows'  => array_slice($valid, 0, 200),
            'error_rows'  => array_slice($errors, 0, 200),
            'columns'     => $columns,
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'temp_id' => 'required|string',
            'type'    => 'required|in:balance,leave,sick_leave,payout',
        ]);

        $tempId  = $request->input('temp_id');
        $type    = $request->input('type');
        $cached  = Cache::get("import_temp_{$tempId}");

        if (! $cached || $cached['type'] !== $type) {
            return response()->json(['error' => 'Import session expired. Please re-upload your file.'], 422);
        }

        $tempPath = $cached['path'];
        $columns  = $cached['columns'];
        $batchId  = Str::uuid()->toString();

        if (! Storage::disk('local')->exists($tempPath)) {
            return response()->json(['error' => 'Temp file not found. Please re-upload.'], 422);
        }

        $tempFile = Storage::disk('local')->path($tempPath);

        $imported   = 0;
        $errors     = [];
        $createdIds = [];

        try {
            $rows = $this->parseFilePath($tempFile);

            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex === 0 && $this->isHeaderRow($row, $columns)) {
                    continue;
                }

                $mapped = [];
                foreach ($columns as $i => $col) {
                    $mapped[$col] = trim((string) ($row[$i] ?? ''));
                }

                $rowErrors = $this->validateRow($type, $mapped, $rowIndex + 1);

                if (! empty($rowErrors)) {
                    $errors[] = array_merge($mapped, ['_row' => $rowIndex + 1, '_errors' => $rowErrors]);
                    continue;
                }

                try {
                    $id = DB::transaction(fn() => $this->insertRow($type, $mapped, $batchId));
                    if ($id) {
                        $createdIds[] = ['table' => $this->tableForType($type), 'id' => $id];
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = array_merge($mapped, ['_row' => $rowIndex + 1, '_errors' => [$e->getMessage()]]);
                }
            }
        } finally {
            Storage::disk('local')->delete($tempPath);
            Cache::forget("import_temp_{$tempId}");
        }

        // Store batch for rollback (24 h)
        Cache::put("import_batch_{$batchId}", [
            'type'       => $type,
            'ids'        => $createdIds,
            'imported'   => $imported,
            'imported_by'=> Auth::id(),
            'imported_at'=> now()->toISOString(),
        ], now()->addHours(24));

        activity()
            ->useLog('LeaveImport')
            ->causedBy(Auth::user())
            ->withProperties([
                'type'        => $type,
                'batch_id'    => $batchId,
                'imported'    => $imported,
                'error_count' => count($errors),
            ])
            ->log("Bulk {$type} import completed: {$imported} records imported (batch {$batchId})");

        return response()->json([
            'batch_id'    => $batchId,
            'imported'    => $imported,
            'error_count' => count($errors),
            'errors'      => $errors,
        ]);
    }

    public function rollback(Request $request): JsonResponse
    {
        $request->validate(['batch_id' => 'required|string']);

        $batchId = $request->input('batch_id');
        $batch   = Cache::get("import_batch_{$batchId}");

        if (! $batch) {
            return response()->json(['error' => 'Batch not found or rollback window expired (24 h).'], 422);
        }

        $deleted = 0;
        foreach ($batch['ids'] as $entry) {
            $rows = DB::table($entry['table'])->where('id', $entry['id'])->delete();
            $deleted += $rows;
        }

        Cache::forget("import_batch_{$batchId}");

        activity()
            ->useLog('LeaveImport')
            ->causedBy(Auth::user())
            ->withProperties([
                'type'     => $batch['type'],
                'batch_id' => $batchId,
                'deleted'  => $deleted,
            ])
            ->log("Import rollback completed: {$deleted} records deleted (batch {$batchId})");

        return response()->json([
            'message' => "Rollback complete. {$deleted} records deleted.",
            'deleted' => $deleted,
        ]);
    }

    public function downloadErrors(Request $request): StreamedResponse
    {
        $errors  = $request->input('errors', []);
        $columns = $request->input('columns', []);

        return response()->streamDownload(function () use ($errors, $columns) {
            $handle = fopen('php://output', 'w');
            if ($columns) {
                fputcsv($handle, array_merge($columns, ['_row', '_errors']));
            }
            foreach ($errors as $row) {
                $errorStr = implode('; ', (array) ($row['_errors'] ?? []));
                $values   = array_map(fn($col) => $row[$col] ?? '', $columns);
                fputcsv($handle, array_merge($values, [$row['_row'] ?? '', $errorStr]));
            }
            fclose($handle);
        }, 'import_errors.csv', ['Content-Type' => 'text/csv']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function parseFile($file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'csv') {
            $rows = [];
            if (($h = fopen($file->getRealPath(), 'r')) !== false) {
                while (($row = fgetcsv($h)) !== false) {
                    $rows[] = $row;
                }
                fclose($h);
            }
            return $rows;
        }

        $collected = [];
        Excel::import(new class($collected) implements ToArray {
            public function __construct(private array &$data) {}
            public function array(array $array): void { $this->data = $array; }
        }, $file);

        return $collected;
    }

    private function parseFilePath(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'csv') {
            $rows = [];
            if (($h = fopen($path, 'r')) !== false) {
                while (($row = fgetcsv($h)) !== false) {
                    $rows[] = $row;
                }
                fclose($h);
            }
            return $rows;
        }

        $collected = [];
        Excel::import(new class($collected) implements ToArray {
            public function __construct(private array &$data) {}
            public function array(array $array): void { $this->data = $array; }
        }, $path);

        return $collected;
    }

    private function isHeaderRow(array $row, array $columns): bool
    {
        $first = strtolower(trim((string) ($row[0] ?? '')));
        return $first === strtolower($columns[0]);
    }

    private function validateRow(string $type, array $row, int $rowNum): array
    {
        $errors = [];

        // Resolve employee
        $empNum = $row['employee_number'] ?? '';
        if (empty($empNum)) {
            $errors[] = 'employee_number is required';
        } else {
            $emp = DB::table('hr_people')->where('employee_number', $empNum)->first(['id']);
            if (! $emp) {
                $errors[] = "Employee '{$empNum}' not found";
            }
        }

        match ($type) {
            'balance' => $this->validateBalanceRow($row, $errors),
            'leave'   => $this->validateLeaveRow($row, $errors),
            'sick_leave' => $this->validateSickLeaveRow($row, $errors),
            'payout'  => $this->validatePayoutRow($row, $errors),
        };

        return $errors;
    }

    private function validateBalanceRow(array $row, array &$errors): void
    {
        if (empty($row['leave_type'])) {
            $errors[] = 'leave_type is required';
        } else {
            if (! LeaveType::where('name', $row['leave_type'])->exists()) {
                $errors[] = "Leave type '{$row['leave_type']}' not found";
            }
        }
        if (empty($row['transaction_type']) || ! in_array($row['transaction_type'], self::BALANCE_TYPES)) {
            $errors[] = 'transaction_type must be one of: ' . implode(', ', self::BALANCE_TYPES);
        }
        if (! is_numeric($row['hours'] ?? '')) {
            $errors[] = 'hours must be a number';
        }
    }

    private function validateLeaveRow(array $row, array &$errors): void
    {
        if (empty($row['leave_type'])) {
            $errors[] = 'leave_type is required';
        } else {
            if (! LeaveType::where('name', $row['leave_type'])->exists()) {
                $errors[] = "Leave type '{$row['leave_type']}' not found";
            }
        }
        if (empty($row['start_date']) || ! strtotime($row['start_date'])) {
            $errors[] = 'start_date must be a valid date (YYYY-MM-DD)';
        }
        if (empty($row['end_date']) || ! strtotime($row['end_date'])) {
            $errors[] = 'end_date must be a valid date (YYYY-MM-DD)';
        }
        if (! empty($row['start_date']) && ! empty($row['end_date']) && strtotime($row['end_date']) < strtotime($row['start_date'])) {
            $errors[] = 'end_date cannot be before start_date';
        }
    }

    private function validateSickLeaveRow(array $row, array &$errors): void
    {
        if (empty($row['pattern_type']) || ! in_array($row['pattern_type'], self::SICK_PATTERN_TYPES)) {
            $errors[] = 'pattern_type must be one of: ' . implode(', ', self::SICK_PATTERN_TYPES);
        }
        if (empty($row['detection_date']) || ! strtotime($row['detection_date'])) {
            $errors[] = 'detection_date must be a valid date';
        }
    }

    private function validatePayoutRow(array $row, array &$errors): void
    {
        if (empty($row['leave_type'])) {
            $errors[] = 'leave_type is required';
        } else {
            if (! LeaveType::where('name', $row['leave_type'])->exists()) {
                $errors[] = "Leave type '{$row['leave_type']}' not found";
            }
        }
        if (! is_numeric($row['hours_paid_out'] ?? '')) {
            $errors[] = 'hours_paid_out must be a number';
        }
        if (empty($row['payout_date']) || ! strtotime($row['payout_date'])) {
            $errors[] = 'payout_date must be a valid date';
        }
    }

    private function insertRow(string $type, array $row, string $batchId): ?int
    {
        $empId = DB::table('hr_people')->where('employee_number', $row['employee_number'])->value('id');
        $cycle = LeaveCycle::active();

        return match ($type) {
            'balance'    => $this->insertBalance($row, $empId, $cycle, $batchId),
            'leave'      => $this->insertLeave($row, $empId, $cycle, $batchId),
            'sick_leave' => $this->insertSickLeave($row, $empId, $batchId),
            'payout'     => $this->insertPayout($row, $empId, $cycle, $batchId),
        };
    }

    private function insertBalance(array $row, int $empId, $cycle, string $batchId): int
    {
        $ltId = LeaveType::where('name', $row['leave_type'])->value('id');

        // Compute running balance
        $current = (float) DB::table('leave_balance_transactions')
            ->where('employee_id', $empId)
            ->where('leave_type_id', $ltId)
            ->orderByDesc('id')
            ->value('balance_after') ?? 0;

        $hours = (float) $row['hours'];
        $after = $current + $hours;

        return DB::table('leave_balance_transactions')->insertGetId([
            'employee_id'      => $empId,
            'leave_type_id'    => $ltId,
            'leave_cycle_id'   => $cycle?->id,
            'transaction_type' => $row['transaction_type'],
            'hours'            => $hours,
            'balance_before'   => $current,
            'balance_after'    => $after,
            'reference_type'   => 'import',
            'description'      => $row['description'] ?: "Bulk import batch {$batchId}",
            'performed_by'     => Auth::id(),
            'created_at'       => $row['date'] ? \Carbon\Carbon::parse($row['date']) : now(),
        ]);
    }

    private function insertLeave(array $row, int $empId, $cycle, string $batchId): int
    {
        $ltId      = LeaveType::where('name', $row['leave_type'])->value('id');
        $start     = \Carbon\Carbon::parse($row['start_date']);
        $end       = \Carbon\Carbon::parse($row['end_date']);
        $workDays  = $start->diffInWeekdays($end) + 1;

        return DB::table('leave_requests')->insertGetId([
            'uuid'            => Str::uuid(),
            'request_number'  => 'IMP-' . strtoupper(Str::random(8)),
            'employee_id'     => $empId,
            'leave_type_id'   => $ltId,
            'leave_cycle_id'  => $cycle?->id,
            'request_mode'    => 'daily',
            'start_date'      => $row['start_date'],
            'end_date'        => $row['end_date'],
            'working_days'    => $workDays,
            'working_hours'   => $workDays * 8,
            'status'          => $row['status'] ?: 'approved',
            'notes'           => ($row['notes'] ?: '') . " [Imported batch {$batchId}]",
            'submitted_by'    => Auth::id(),
            'submitted_at'    => now(),
            'approved_at'     => $row['status'] === 'approved' ? now() : null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    private function insertSickLeave(array $row, int $empId, string $batchId): int
    {
        return DB::table('sick_leave_patterns')->insertGetId([
            'employee_id'    => $empId,
            'pattern_type'   => $row['pattern_type'],
            'detection_date' => $row['detection_date'],
            'pattern_start'  => $row['pattern_start'] ?: null,
            'pattern_end'    => $row['pattern_end'] ?: null,
            'occurrence_count'=> (int) ($row['occurrence_count'] ?: 1),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    private function insertPayout(array $row, int $empId, $cycle, string $batchId): int
    {
        $ltId = LeaveType::where('name', $row['leave_type'])->value('id');

        return DB::table('leave_payout_records')->insertGetId([
            'employee_id'       => $empId,
            'leave_type_id'     => $ltId,
            'leave_cycle_id'    => $cycle?->id,
            'hours_paid_out'    => (float) $row['hours_paid_out'],
            'amount'            => is_numeric($row['amount'] ?? '') ? (float) $row['amount'] : null,
            'payout_date'       => $row['payout_date'],
            'payroll_reference' => $row['payroll_reference'] ?: null,
            'approved_by'       => Auth::id(),
            'status'            => 'processed',
            'notes'             => "Imported batch {$batchId}",
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    private function tableForType(string $type): string
    {
        return match ($type) {
            'balance'    => 'leave_balance_transactions',
            'leave'      => 'leave_requests',
            'sick_leave' => 'sick_leave_patterns',
            'payout'     => 'leave_payout_records',
        };
    }

    private function templateExampleRow(string $type): array
    {
        return match ($type) {
            'balance'    => ['EMP001', 'Annual Leave', 'opening_balance', '80', 'Opening balance 2026', '2026-01-01'],
            'leave'      => ['EMP001', 'Annual Leave', '2026-03-10', '2026-03-14', 'approved', 'Vacation'],
            'sick_leave' => ['EMP001', 'monday_absence', '2026-02-01', '2026-01-27', '2026-02-01', '1'],
            'payout'     => ['EMP001', 'Annual Leave', '16', '3200.00', '2026-02-28', 'PAYROLL-2026-02'],
        };
    }
}
