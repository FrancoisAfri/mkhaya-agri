<?php

namespace App\Modules\SupplierManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Services\SupplierReportService;
use App\Shared\Procurement\Models\ProcurementScheduledReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierScheduledReportController extends Controller
{
    private const MODULE = 'suppliers';

    public function index(): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $reports = ProcurementScheduledReport::module(self::MODULE)
            ->orderBy('cadence')
            ->orderBy('name')
            ->get()
            ->map(fn (ProcurementScheduledReport $r) => [
                'id'           => $r->id,
                'module'       => $r->module,
                'name'         => $r->name,
                'report_key'   => $r->report_key,
                'cadence'      => $r->cadence,
                'run_time'     => $r->run_time,
                'day_of_week'  => $r->day_of_week,
                'day_of_month' => $r->day_of_month,
                'format'       => $r->format,
                'recipients'   => $r->recipients,
                'active'       => (bool) $r->active,
                'last_run_at'  => optional($r->last_run_at)->toDateTimeString(),
                'next_run_at'  => optional($r->next_run_at)->toDateTimeString(),
            ])
            ->all();

        return Inertia::render('Modules/SupplierManagement/Setup/ScheduledReports', [
            'reports'    => $reports,
            'reportKeys' => (new SupplierReportService)->reportKeys(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('update', Supplier::class);

        $data = $this->validateData($request);

        $report = new ProcurementScheduledReport($data);
        $report->module     = self::MODULE;
        $report->created_by = $request->user()?->id;
        $report->next_run_at = $report->computeNextRun();
        $report->save();

        return back()->with('success', 'Scheduled report created.');
    }

    public function update(Request $request, ProcurementScheduledReport $scheduledReport): RedirectResponse
    {
        $this->authorize('update', Supplier::class);
        abort_unless($scheduledReport->module === self::MODULE, 404);

        $data = $this->validateData($request);

        $scheduledReport->fill($data);
        $scheduledReport->next_run_at = $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return back()->with('success', 'Scheduled report updated.');
    }

    public function destroy(ProcurementScheduledReport $scheduledReport): RedirectResponse
    {
        $this->authorize('update', Supplier::class);
        abort_unless($scheduledReport->module === self::MODULE, 404);

        $scheduledReport->delete();

        return back()->with('success', 'Scheduled report deleted.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validateData(Request $request): array
    {
        $reportKeys = array_keys((new SupplierReportService)->reportKeys());

        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'report_key'   => ['required', Rule::in($reportKeys)],
            'cadence'      => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'run_time'     => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'day_of_week'  => ['nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'format'       => ['required', Rule::in(['pdf', 'excel', 'both'])],
            'recipients'   => ['required', 'string'],
            'active'       => ['required', 'boolean'],
        ]);

        // Recipients arrive as a comma-separated string; only keep day relevant to cadence.
        if ($validated['cadence'] !== 'weekly') {
            $validated['day_of_week'] = null;
        }
        if ($validated['cadence'] !== 'monthly') {
            $validated['day_of_month'] = null;
        }

        return $validated;
    }
}
