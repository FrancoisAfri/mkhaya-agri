<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Jobs\GenerateCrmReportJob;
use App\Modules\CRM\Models\CrmScheduledReport;
use App\Modules\CRM\Services\CrmReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRM scheduled reports (mirrors the leave-management Setup/ScheduledReports
 * pattern) — delivered on a cadence by a queued background job.
 */
class CrmScheduledReportController extends Controller
{
    public function index(): Response
    {
        $reports = CrmScheduledReport::orderBy('cadence')->orderBy('name')->get()
            ->map(fn ($r) => $this->present($r));

        return Inertia::render('Modules/CRM/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => CrmReportService::reportKeys(),
            'cadences'   => ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);

        $report = new CrmScheduledReport($data);
        $report->created_by  = Auth::id();
        $report->next_run_at = $report->computeNextRun();
        $report->save();

        return back()->with('success', 'Scheduled report created.');
    }

    public function update(Request $request, CrmScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->fill($this->validateData($request));
        $scheduledReport->next_run_at = $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return back()->with('success', 'Scheduled report updated.');
    }

    public function toggle(CrmScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->update(['active' => ! $scheduledReport->active]);

        return back()->with('success', $scheduledReport->active ? 'Schedule resumed.' : 'Schedule paused.');
    }

    public function runNow(CrmScheduledReport $scheduledReport): RedirectResponse
    {
        GenerateCrmReportJob::dispatch($scheduledReport->report_key, $scheduledReport->recipientList(), $scheduledReport->ownerScope());
        $scheduledReport->update(['last_run_at' => now()]);

        return back()->with('success', 'Report queued for delivery.');
    }

    public function destroy(CrmScheduledReport $scheduledReport): RedirectResponse
    {
        $scheduledReport->delete();

        return back()->with('success', 'Scheduled report deleted.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name'         => 'required|string|max:120',
            'report_key'   => 'required|in:' . implode(',', array_keys(CrmReportService::reportKeys())),
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'format'       => 'nullable|in:csv',
            'recipients'   => 'required|string|max:500',
            'active'       => 'boolean',
        ]);
    }

    private function present(CrmScheduledReport $r): array
    {
        return [
            'id'           => $r->id,
            'name'         => $r->name,
            'report_key'   => $r->report_key,
            'report_label' => CrmReportService::reportKeys()[$r->report_key] ?? $r->report_key,
            'cadence'      => $r->cadence,
            'run_time'     => $r->run_time,
            'day_of_week'  => $r->day_of_week,
            'day_of_month' => $r->day_of_month,
            'recipients'   => $r->recipients,
            'active'       => $r->active,
            'next_run'     => $r->next_run_at?->format('D d M Y H:i') ?? '—',
            'last_run'     => $r->last_run_at?->format('d M Y H:i'),
        ];
    }
}
