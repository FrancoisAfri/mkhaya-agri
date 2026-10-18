<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollAnomaly;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollAnomalyController extends Controller
{
    public function index(Request $request): Response
    {
        $anomalies = PayrollAnomaly::with(['payrollRun:id,uuid,ref_number,name'])
            ->when($request->input('status'),   fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('severity'), fn ($q, $s) => $q->where('severity', $s))
            ->when($request->input('run_id'),   fn ($q, $id) => $q->where('run_id', $id))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        // Enrich with employee names
        $anomalies->getCollection()->transform(function ($anomaly) {
            if ($anomaly->employee_id) {
                $person = \Illuminate\Support\Facades\DB::table('hr_people')
                    ->where('user_id', $anomaly->employee_id)
                    ->selectRaw("COALESCE(TRIM(CONCAT(first_name, ' ', COALESCE(surname, ''))), CAST(user_id AS VARCHAR)) AS name")
                    ->first();
                $anomaly->employee_name = $person?->name ?? "Employee #{$anomaly->employee_id}";
            } else {
                $anomaly->employee_name = null;
            }
            return $anomaly;
        });

        $stats = [
            'total_open'     => PayrollAnomaly::where('status', 'open')->count(),
            'total_critical' => PayrollAnomaly::where('severity', 'critical')->where('status', 'open')->count(),
            'total_high'     => PayrollAnomaly::where('severity', 'high')->where('status', 'open')->count(),
            'total_resolved' => PayrollAnomaly::where('status', 'resolved')->count(),
        ];

        $runs = \App\Modules\HR\Payroll\Models\PayrollRun::select('id', 'uuid', 'ref_number', 'name')
            ->latest('period_start')
            ->limit(50)
            ->get();

        return Inertia::render('Modules/HR/Payroll/Anomalies/Index', [
            'anomalies' => $anomalies,
            'filters'   => $request->only(['status', 'severity', 'run_id']),
            'stats'     => $stats,
            'runs'      => $runs,
        ]);
    }

    public function resolve(Request $request, PayrollAnomaly $anomaly): RedirectResponse
    {
        $validated = $request->validate([
            'resolution_notes' => 'required|string|min:10|max:2000',
        ]);

        $anomaly->update([
            'status'           => 'resolved',
            'resolution_notes' => $validated['resolution_notes'],
            'resolved_by'      => auth()->id(),
            'resolved_at'      => now(),
        ]);

        return back()->with('success', 'Anomaly resolved successfully.');
    }

    public function dismiss(Request $request, PayrollAnomaly $anomaly): RedirectResponse
    {
        $request->validate([
            'resolution_notes' => 'nullable|string|max:2000',
        ]);

        $anomaly->update([
            'status'           => 'dismissed',
            'resolution_notes' => $request->input('resolution_notes', 'Dismissed by reviewer.'),
            'resolved_by'      => auth()->id(),
            'resolved_at'      => now(),
        ]);

        return back()->with('success', 'Anomaly dismissed.');
    }
}
