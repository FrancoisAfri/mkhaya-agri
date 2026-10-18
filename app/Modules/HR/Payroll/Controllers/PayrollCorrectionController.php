<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollCorrection;
use App\Modules\HR\Payroll\Models\PayrollRun;
use App\Modules\HR\Payroll\Services\PayrollCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PayrollCorrectionController extends Controller
{
    public function __construct(private PayrollCorrectionService $correctionService) {}

    public function index(): Response
    {
        $filters  = request()->only(['status', 'type', 'run_id']);
        $runId    = $filters['run_id'] ?? '';
        $status   = $filters['status'] ?? '';
        $type     = $filters['type']   ?? '';

        $query = PayrollCorrection::query()
            ->with('payrollRun:id,uuid,ref_number,name');

        if ($runId)  $query->where('run_id', $runId);
        if ($status) $query->where('status', $status);
        if ($type)   $query->where('correction_type', $type);

        $corrections = $query->latest()->paginate(25)->withQueryString();

        // Enrich with employee names
        $employeeIds = $corrections->pluck('employee_id')->filter()->unique()->values();
        $names = DB::table('hr_people')
            ->whereIn('user_id', $employeeIds)
            ->select('user_id', DB::raw("CONCAT(first_name, ' ', surname) AS full_name"))
            ->get()->keyBy('user_id');

        $corrections->getCollection()->transform(function ($c) use ($names) {
            $c->employee_name = $names[$c->employee_id]->full_name ?? null;
            return $c;
        });

        $runs = PayrollRun::select('id', 'uuid', 'ref_number', 'name')
            ->latest('period_start')->limit(50)->get();

        $stats = [
            'total_suggested' => PayrollCorrection::where('status', 'suggested')->count(),
            'total_approved'  => PayrollCorrection::where('status', 'approved')->count(),
            'total_applied'   => PayrollCorrection::where('status', 'applied')->count(),
            'total_rejected'  => PayrollCorrection::where('status', 'rejected')->count(),
        ];

        return Inertia::render('Modules/HR/Payroll/Corrections/Index', [
            'corrections'      => $corrections,
            'filters'          => $filters,
            'stats'            => $stats,
            'runs'             => $runs,
            'correctionTypes'  => ['duplicate_payment', 'allowance_error', 'leave_error', 'tax_error', 'other'],
        ]);
    }

    public function suggest(PayrollRun $run): RedirectResponse
    {
        $saved = $this->correctionService->suggestPayrollCorrections($run);

        return back()->with('success', "Correction analysis complete — {$saved} new suggestion(s) generated for {$run->ref_number}.");
    }

    public function approve(PayrollCorrection $correction): RedirectResponse
    {
        $ok = $this->correctionService->approveSuggestedCorrection($correction);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Correction approved and ready to apply.'
            : 'This correction cannot be approved (status: ' . $correction->status . ').');
    }

    public function reject(Request $request, PayrollCorrection $correction): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $ok = $this->correctionService->rejectSuggestedCorrection($correction, $request->reason);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Correction rejected.'
            : 'This correction cannot be rejected (status: ' . $correction->status . ').');
    }

    public function apply(PayrollCorrection $correction): RedirectResponse
    {
        $ok = $this->correctionService->applyPayrollCorrection($correction);

        return back()->with($ok ? 'success' : 'error', $ok
            ? 'Correction applied to payslip successfully.'
            : 'Correction must be approved before it can be applied.');
    }
}
