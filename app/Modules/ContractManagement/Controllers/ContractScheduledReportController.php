<?php

namespace App\Modules\ContractManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ContractManagement\Models\Contract;
use App\Modules\ContractManagement\Services\ContractReportService;
use App\Shared\Procurement\Models\ProcurementScheduledReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContractScheduledReportController extends Controller
{
    private const MODULE = 'contracts';

    public function index(): Response
    {
        $this->authorize('viewAny', Contract::class);

        $reports = ProcurementScheduledReport::module(self::MODULE)
            ->orderBy('cadence')
            ->orderBy('name')
            ->get()
            ->map(fn (ProcurementScheduledReport $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'report_key' => $r->report_key,
                'cadence' => $r->cadence,
                'run_time' => $r->run_time,
                'day_of_week' => $r->day_of_week,
                'day_of_month' => $r->day_of_month,
                'format' => $r->format,
                'recipients' => $r->recipients,
                'active' => (bool) $r->active,
                'last_run_at' => optional($r->last_run_at)->toDateTimeString(),
                'next_run_at' => optional($r->next_run_at)->toDateTimeString(),
            ]);

        return Inertia::render('Modules/ContractManagement/Setup/ScheduledReports', [
            'reports' => $reports,
            'reportKeys' => (new ContractReportService)->reportKeys(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('update', Contract::class);

        $data = $this->validateData($request);

        $report = new ProcurementScheduledReport;
        $report->fill($data);
        $report->module = self::MODULE;
        $report->created_by = $request->user()?->id;
        $report->next_run_at = $report->computeNextRun();
        $report->save();

        return back()->with('success', 'Scheduled report created.');
    }

    public function update(Request $request, ProcurementScheduledReport $scheduledReport): RedirectResponse
    {
        $this->authorize('update', Contract::class);
        $this->guardModule($scheduledReport);

        $data = $this->validateData($request);

        $scheduledReport->fill($data);
        $scheduledReport->next_run_at = $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return back()->with('success', 'Scheduled report updated.');
    }

    public function destroy(ProcurementScheduledReport $scheduledReport): RedirectResponse
    {
        $this->authorize('update', Contract::class);
        $this->guardModule($scheduledReport);

        $scheduledReport->delete();

        return back()->with('success', 'Scheduled report deleted.');
    }

    private function validateData(Request $request): array
    {
        $keys = array_keys((new ContractReportService)->reportKeys());

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'report_key' => ['required', 'string', Rule::in($keys)],
            'cadence' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'run_time' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'format' => ['required', Rule::in(['pdf', 'excel', 'both'])],
            'recipients' => ['required', 'string'],
            'active' => ['required', 'boolean'],
        ]);
    }

    private function guardModule(ProcurementScheduledReport $report): void
    {
        abort_unless($report->module === self::MODULE, 404);
    }
}
