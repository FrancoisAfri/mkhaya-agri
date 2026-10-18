<?php

namespace App\Modules\Assets\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Assets\Models\AssetScheduledReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AssetScheduledReportController extends Controller
{
    private const REPORT_KEYS = [
        'register'    => 'Asset Register',
        'assignments' => 'Asset Assignments',
        'maintenance' => 'Maintenance Schedule',
        'depreciation'=> 'Depreciation Report',
        'incidents'   => 'Asset Incidents',
        'movements'   => 'Asset Movements',
        'clearances'  => 'Exit Clearances',
    ];

    public function index(): Response
    {
        $reports = AssetScheduledReport::orderBy('cadence')->orderBy('name')->get()
            ->map(fn($r) => $this->format($r));

        return Inertia::render('Modules/Assets/Settings/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => self::REPORT_KEYS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $report = new AssetScheduledReport($data);
        $report->created_by  = Auth::id();
        $report->next_run_at = $report->computeNextRun();
        $report->save();

        return back()->with('success', 'Scheduled report created.');
    }

    public function update(Request $request, AssetScheduledReport $scheduledReport): RedirectResponse
    {
        $data = $this->validated($request);

        $scheduledReport->fill($data);
        $scheduledReport->next_run_at = $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return back()->with('success', 'Scheduled report updated.');
    }

    public function destroy(AssetScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->delete();
        return back()->with('success', 'Scheduled report deleted.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'         => 'required|string|max:120',
            'report_key'   => 'required|string|in:' . implode(',', array_keys(self::REPORT_KEYS)),
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'format'       => 'required|in:pdf,excel,both',
            'recipients'   => 'required|string|max:500',
            'active'       => 'boolean',
        ]);
    }

    private function format(AssetScheduledReport $r): array
    {
        return [
            'id'           => $r->id,
            'name'         => $r->name,
            'report_key'   => $r->report_key,
            'cadence'      => ucfirst($r->cadence),
            'run_time'     => $r->run_time,
            'day_of_week'  => $r->day_of_week,
            'day_of_month' => $r->day_of_month,
            'format'       => $r->format,
            'recipients'   => $r->recipients,
            'active'       => $r->active,
            'next_run'     => $r->next_run_at?->format('D d M Y H:i') ?? '—',
            'last_run_at'  => $r->last_run_at?->format('d M Y H:i'),
        ];
    }
}
