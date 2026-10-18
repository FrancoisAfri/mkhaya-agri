<?php

namespace App\Modules\HR\LMS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\LMS\Models\LmsScheduledReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LmsScheduledReportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Modules/LMS/Admin/ScheduledReports', [
            'scheduled'        => LmsScheduledReport::orderBy('name')->get(),
            'availableReports' => LmsScheduledReport::availableReports(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = Auth::id();
        $report = LmsScheduledReport::create($data);
        $report->update(['next_run_at' => $report->computeNextRun()]);
        return back()->with('success', 'Scheduled report created.');
    }

    public function update(Request $request, LmsScheduledReport $scheduledReport): RedirectResponse
    {
        $data = $this->validated($request);
        $scheduledReport->update($data);
        $scheduledReport->update(['next_run_at' => $scheduledReport->computeNextRun()]);
        return back()->with('success', 'Scheduled report updated.');
    }

    public function destroy(LmsScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->delete();
        return back()->with('success', 'Scheduled report removed.');
    }

    public function toggle(LmsScheduledReport $scheduledReport): JsonResponse
    {
        $scheduledReport->update(['active' => ! $scheduledReport->active]);
        return response()->json(['active' => $scheduledReport->active]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'          => 'required|string|max:120',
            'report_key'    => 'required|string|max:60',
            'cadence'       => 'required|in:daily,weekly,monthly',
            'run_time'      => 'required|date_format:H:i',
            'day_of_week'   => 'nullable|integer|min:1|max:7',
            'day_of_month'  => 'nullable|integer|min:1|max:28',
            'format'        => 'required|in:pdf,excel,both',
            'recipients'    => 'required|string|max:2000',
            'active'        => 'boolean',
        ]);
    }
}
