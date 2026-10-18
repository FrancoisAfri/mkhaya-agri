<?php

namespace App\Modules\HR\Hr\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Hr\Models\HRPerson;
use App\Modules\HR\Leave\AI\LeaveAI;
use App\Modules\HR\Leave\Enums\LeaveAnomalyType;
use App\Modules\HR\Leave\Models\LeaveAnomalyFlag;
use App\Modules\HR\Leave\Models\LeaveBalanceTransaction;
use App\Modules\HR\Leave\Models\LeaveProfileAllocation;
use App\Modules\HR\Leave\Models\LeaveRequest;
use App\Modules\HR\Leave\Models\LeaveType;
use App\Modules\HR\Leave\Services\LeaveBalanceService;
use App\Modules\HR\Leave\Services\LeaveIntegrityScanner;
use App\Modules\HR\Leave\Services\LeaveProbationAccrualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Leave Anomalies — surfaces account-setup / ledger-integrity anomalies, an on-demand
 * scan, and AI-assisted, reversible remediation. HR-only. Multi-tenant (RLS) + self_hosted.
 */
class LeaveAnomaliesController extends Controller
{
    /** Anomaly types whose applied fix can be cleanly reversed. */
    private const REVERSIBLE = ['negative_balance', 'overtime_not_credited'];

    public function __construct(
        private readonly LeaveIntegrityScanner $scanner,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveProbationAccrualService $accrual,
    ) {}

    public function index(): Response
    {
        $rank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

        $flags = LeaveAnomalyFlag::whereIn('status', ['open', 'under_review', 'resolved'])
            ->orderByDesc('detected_at')
            ->get()
            ->sortBy(fn ($f) => ($rank[$f->severity] ?? 9))
            ->values()
            ->map(fn (LeaveAnomalyFlag $f) => $this->row($f));

        return Inertia::render('HR/Leave/Anomalies', [
            'flags'   => $flags,
            'summary' => [
                'open'     => LeaveAnomalyFlag::where('status', 'open')->count(),
                'high'     => LeaveAnomalyFlag::whereIn('status', ['open', 'under_review'])->whereIn('severity', ['high', 'critical'])->count(),
                'resolved' => LeaveAnomalyFlag::where('status', 'resolved')->count(),
            ],
        ]);
    }

    private function row(LeaveAnomalyFlag $f): array
    {
        $emp = HRPerson::find($f->employee_id);

        return [
            'id'            => $f->id,
            'employee'      => $emp ? trim("{$emp->first_name} {$emp->surname}") : "Employee #{$f->employee_id}",
            'employee_uuid' => $emp?->uuid,
            'type'        => $f->anomaly_type->value,
            'type_label'  => $f->anomaly_type->label(),
            'severity'    => $f->severity,
            'description' => $f->description,
            'evidence'    => $f->evidence,
            'status'      => $f->status,
            'detected_at' => $f->detected_at?->diffForHumans(),
            'resolution'  => $f->resolution_notes,
            'reversible'  => $f->status === 'resolved' && in_array($f->anomaly_type->value, self::REVERSIBLE, true),
        ];
    }

    /** Run the integrity scanner for the current company (the "Scan now" button). */
    public function scan(): RedirectResponse
    {
        $n = $this->scanner->scanCurrentCompany();

        return back()->with('success', $n > 0
            ? "Scan complete — {$n} new anomaly(ies) found."
            : 'Scan complete — no new anomalies found.');
    }

    /** Ask the AI to explain the anomaly and propose a fix (shown to HR before applying). */
    public function proposeFix(LeaveAnomalyFlag $flag, LeaveAI $ai): JsonResponse
    {
        try {
            $proposal = $ai->proposeFix(
                [
                    'type'        => $flag->anomaly_type->value,
                    'label'       => $flag->anomaly_type->label(),
                    'severity'    => $flag->severity,
                    'description' => $flag->description,
                    'evidence'    => $flag->evidence,
                ],
                ['is_data_capture' => $flag->anomaly_type->isDataCapture()],
            );

            return response()->json($proposal);
        } catch (\Throwable $e) {
            Log::warning('Leave anomaly fix proposal failed', ['flag' => $flag->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'AI unavailable. Try again shortly.'], 503);
        }
    }

    /**
     * Apply the (HR-approved) fix. The correction is deterministic per anomaly type — the AI
     * only explains; it never drives the ledger write. All ledger writes are new, reversible
     * entries (append-only).
     */
    public function applyFix(LeaveAnomalyFlag $flag): RedirectResponse
    {
        if (! in_array($flag->status, ['open', 'under_review'], true)) {
            return back()->with('warning', 'This anomaly is not open.');
        }

        $emp = HRPerson::find($flag->employee_id);
        if (! $emp) {
            return back()->with('error', 'Employee not found.');
        }

        $type = $flag->anomaly_type;

        // Data-capture anomalies cannot be auto-fixed — HR must supply the value.
        if ($type->isDataCapture()) {
            $flag->update([
                'status'           => 'under_review',
                'reviewed_by'      => Auth::id(),
                'reviewed_at'      => now(),
                'resolution_notes' => 'Awaiting HR to capture the missing data on the employee record; it will clear on the next scan.',
            ]);

            return back()->with('info', 'This needs data captured on the employee record. It will clear automatically once fixed and re-scanned.');
        }

        try {
            $summary = match ($type) {
                LeaveAnomalyType::NegativeBalance    => $this->fixNegativeBalance($flag),
                LeaveAnomalyType::OvertimeNotCredited => $this->fixUncreditedOvertime($flag),
                LeaveAnomalyType::MissingAccrual     => $this->fixMissingAccrual($emp),
                default                              => null,
            };
        } catch (\Throwable $e) {
            Log::error('Leave anomaly fix failed', ['flag' => $flag->id, 'error' => $e->getMessage()]);

            return back()->with('error', 'Could not apply the fix. Please review manually.');
        }

        if ($summary === null) {
            return back()->with('warning', 'No automatic fix is available for this anomaly.');
        }

        $flag->update([
            'status'           => 'resolved',
            'reviewed_by'      => Auth::id(),
            'reviewed_at'      => now(),
            'resolution_notes' => $summary,
        ]);

        return back()->with('success', 'Fix applied — ' . $summary);
    }

    private function fixNegativeBalance(LeaveAnomalyFlag $flag): string
    {
        $typeId = (int) ($flag->evidence['leave_type_id'] ?? 0);
        $bal    = (float) ($flag->evidence['balance_hours'] ?? $this->balances->getBalance($flag->employee_id, $typeId));
        if ($typeId <= 0 || $bal >= 0) {
            return 'Balance is no longer negative — nothing to correct.';
        }

        $this->balances->correct($flag->employee_id, $typeId, abs($bal), 'anomaly_fix', $flag->id,
            "Anomaly fix: cleared negative balance ({$bal} h) to zero.");

        return sprintf('Credited %.2f h to clear the negative balance.', abs($bal));
    }

    private function fixUncreditedOvertime(LeaveAnomalyFlag $flag): string
    {
        $count = 0;
        foreach ((array) ($flag->evidence['requests'] ?? []) as $reqNum) {
            $req = LeaveRequest::where('request_number', $reqNum)->first();
            if ($req) {
                $this->balances->overtimeCredit($req);
                $count++;
            }
        }

        return "Credited annual leave for {$count} approved overtime request(s).";
    }

    private function fixMissingAccrual(HRPerson $emp): string
    {
        $profile = (int) ($emp->getRawOriginal('leave_profile') ?? 0);
        $typeIds = LeaveProfileAllocation::where('leave_profile_id', $profile)->where('active', true)->pluck('leave_type_id');
        $applied = 0;
        foreach ($typeIds as $typeId) {
            $lt = LeaveType::find($typeId);
            if ($lt && abs($this->accrual->reconcile($emp, $lt)) > 0.01) {
                $applied++;
            }
        }

        return "Reconciled accrual on {$applied} leave type(s) to the expected balance.";
    }

    /** Reverse a previously-applied fix and reopen the anomaly. */
    public function reverseFix(LeaveAnomalyFlag $flag): RedirectResponse
    {
        if ($flag->status !== 'resolved' || ! in_array($flag->anomaly_type->value, self::REVERSIBLE, true)) {
            return back()->with('warning', 'This fix cannot be reversed.');
        }

        $reversed = false;

        // Reverse tagged ledger corrections (negative-balance fixes).
        foreach (LeaveBalanceTransaction::where('reference_type', 'anomaly_fix')->where('reference_id', $flag->id)->get() as $row) {
            $this->balances->correct((int) $row->employee_id, (int) $row->leave_type_id, -1 * (float) $row->hours,
                'anomaly_fix_reversal', $flag->id, "Reversal of anomaly fix #{$flag->id}.");
            $reversed = true;
        }

        // Reverse overtime credits.
        if ($flag->anomaly_type === LeaveAnomalyType::OvertimeNotCredited) {
            foreach ((array) ($flag->evidence['requests'] ?? []) as $reqNum) {
                $req = LeaveRequest::where('request_number', $reqNum)->first();
                if ($req) {
                    $this->balances->overtimeReverse($req);
                    $reversed = true;
                }
            }
        }

        if (! $reversed) {
            return back()->with('warning', 'Nothing to reverse.');
        }

        $flag->update([
            'status'           => 'open',
            'resolution_notes' => trim(($flag->resolution_notes ?? '') . ' — reversed.'),
        ]);

        return back()->with('success', 'Fix reversed — the anomaly is open again.');
    }

    public function dismiss(LeaveAnomalyFlag $flag): RedirectResponse
    {
        $flag->update([
            'status'          => 'dismissed',
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
            'false_positive'  => true,
        ]);

        return back()->with('success', 'Anomaly dismissed.');
    }
}
