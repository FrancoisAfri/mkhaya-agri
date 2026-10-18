<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Leave\Models\LeaveAbsenceUploadBatch;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Services\LeaveAbsenceUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance-driven absence upload: spreadsheet of non-clock-in days →
 * preview → confirm → (if needed) reverse the whole batch.
 */
class LeaveAbsenceUploadController extends Controller
{
    public function __construct(private readonly LeaveAbsenceUploadService $service) {}

    public function index(): Response
    {
        return Inertia::render('HR/Leave/AbsenceUpload', [
            'leaveTypes' => LeaveType::where('status', 1)->orderBy('name')->get(['id', 'name']),
            'batches'    => LeaveAbsenceUploadBatch::withCount('items')->latest('id')->limit(100)->get(),
        ]);
    }

    /** Parse the upload and show what would happen. Nothing is written. */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'file'          => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'hours_per_day' => ['required', 'numeric', 'min:0.5', 'max:24'],
        ]);

        $rows = $this->parseFile($request->file('file'));
        $parsed = $this->service->extractAbsences($rows);

        if (empty($parsed['records']) && empty($parsed['errors'])) {
            return response()->json([
                'error' => 'No absence rows found. The sheet needs an "Employee Number" column and a "Date" column.',
            ], 422);
        }

        $preview = $this->service->preview(
            $parsed['records'],
            (int) $request->input('leave_type_id'),
            (float) $request->input('hours_per_day'),
        );

        $preview['parse_errors'] = $parsed['errors'];
        $preview['file_name']    = $request->file('file')->getClientOriginalName();

        return response()->json($preview);
    }

    /** Apply the previewed absences as a reversible batch. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'hours_per_day' => ['required', 'numeric', 'min:0.5', 'max:24'],
            'file_name'     => ['nullable', 'string', 'max:255'],
            'items'         => ['required', 'array', 'min:1'],
            'items.*.employee_id' => ['required', 'integer'],
            'items.*.date'        => ['required', 'date'],
            'items.*.hours'       => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $result = $this->service->confirm(
            $data['items'],
            (int) $data['leave_type_id'],
            (float) $data['hours_per_day'],
            $data['file_name'] ?? null,
        );

        activity()
            ->useLog('LeaveAbsenceUpload')
            ->causedBy(Auth::user())
            ->withProperties([
                'batch_id' => $result['batch']->id,
                'applied'  => $result['applied'],
                'skipped'  => count($result['skipped']),
            ])
            ->log("Absence upload applied: {$result['applied']} day(s) deducted (batch #{$result['batch']->id})");

        return response()->json([
            'message'    => "{$result['applied']} day(s) of leave deducted.",
            'applied'    => $result['applied'],
            'skipped'    => $result['skipped'],
            'batch_uuid' => $result['batch']->uuid,
        ]);
    }

    /** The rows behind one past upload. */
    public function show(LeaveAbsenceUploadBatch $batch): JsonResponse
    {
        return response()->json([
            'batch' => $batch,
            'items' => $batch->items()->orderBy('employee_name')->orderBy('absence_date')->get(),
        ]);
    }

    /** Undo an upload: credit the hours back and cancel the leave it created. */
    public function reverse(Request $request, LeaveAbsenceUploadBatch $batch): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $reversed = $this->service->reverse($batch, $data['reason'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        activity()
            ->useLog('LeaveAbsenceUpload')
            ->causedBy(Auth::user())
            ->withProperties(['batch_id' => $batch->id, 'reversed' => $reversed])
            ->log("Absence upload batch #{$batch->id} reversed ({$reversed} day(s))");

        return response()->json([
            'message'  => "Reversed {$reversed} day(s) of leave.",
            'reversed' => $reversed,
        ]);
    }

    /**
     * Read every row of the sheet. CSV is read directly; anything else goes
     * through the same Excel reader the leave import uses.
     *
     * @return array<int, array<int, mixed>>
     */
    private function parseFile($file): array
    {
        if (strtolower($file->getClientOriginalExtension()) === 'csv') {
            $rows = [];

            if (($handle = fopen($file->getRealPath(), 'r')) !== false) {
                while (($row = fgetcsv($handle)) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);
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
}
