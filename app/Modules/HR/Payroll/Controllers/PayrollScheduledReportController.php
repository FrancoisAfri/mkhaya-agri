<?php

namespace App\Modules\HR\Payroll\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Payroll\Models\PayrollScheduledReport;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollScheduledReportController extends Controller
{
    public function index(): Response
    {
        // The BelongsToCompany trait scopes regular tenants; a platform admin has no
        // active company context, so scope explicitly to their own company too.
        $cid = app(CurrentCompany::class)->id() ?? auth()->user()?->company_id;

        $reports = PayrollScheduledReport::when($cid, fn ($q) => $q->where('company_id', $cid))
            ->latest()->get()->map(fn($r) => $this->format($r));

        return Inertia::render('Modules/HR/Payroll/ScheduledReports/Index', [
            'reports'    => $reports,
            'reportKeys' => $this->availableReportKeys(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:150',
            'report_key'    => 'required|string|in:' . implode(',', array_keys($this->availableReportKeys())),
            'cadence'       => 'required|in:daily,weekly,monthly',
            'run_time'      => 'required|regex:/^\d{2}:\d{2}$/',
            'day_of_week'   => 'nullable|integer|min:1|max:7',
            'day_of_month'  => 'nullable|integer|min:1|max:28',
            'format'        => 'required|in:pdf,excel,both',
            'recipients'    => 'required|string|max:1000',
            'active'        => 'boolean',
        ]);

        $scheduled = PayrollScheduledReport::create(array_merge($data, [
            'created_by' => auth()->id(),
            'next_run_at' => null,
        ]));

        $scheduled->update(['next_run_at' => $scheduled->computeNextRun()]);

        return back()->with('success', "Scheduled report \"{$scheduled->name}\" created.");
    }

    public function update(Request $request, PayrollScheduledReport $scheduledReport): RedirectResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:150',
            'report_key'   => 'required|string|in:' . implode(',', array_keys($this->availableReportKeys())),
            'cadence'      => 'required|in:daily,weekly,monthly',
            'run_time'     => 'required|regex:/^\d{2}:\d{2}$/',
            'day_of_week'  => 'nullable|integer|min:1|max:7',
            'day_of_month' => 'nullable|integer|min:1|max:28',
            'format'       => 'required|in:pdf,excel,both',
            'recipients'   => 'required|string|max:1000',
            'active'       => 'boolean',
        ]);

        $scheduledReport->fill($data);
        $scheduledReport->next_run_at = $scheduledReport->computeNextRun();
        $scheduledReport->save();

        return back()->with('success', "Scheduled report \"{$scheduledReport->name}\" updated.");
    }

    public function destroy(PayrollScheduledReport $scheduledReport): RedirectResponse
    {
        $name = $scheduledReport->name;
        $scheduledReport->delete();
        return back()->with('success', "Scheduled report \"{$name}\" deleted.");
    }

    private function format(PayrollScheduledReport $r): array
    {
        $keys = $this->availableReportKeys();
        return [
            'id'           => $r->id,
            'name'         => $r->name,
            'report_key'   => $r->report_key,
            'report_label' => $keys[$r->report_key] ?? $r->report_key,
            'cadence'      => $r->cadence,
            'run_time'     => $r->run_time,
            'day_of_week'  => $r->day_of_week,
            'day_of_month' => $r->day_of_month,
            'format'       => $r->format,
            'recipients'   => $r->recipients,
            'active'       => $r->active,
            'last_run_at'  => $r->last_run_at?->format('d M Y H:i'),
            'next_run_at'  => $r->next_run_at?->format('d M Y H:i'),
            'cadence_label' => match ($r->cadence) {
                'weekly'  => 'Weekly on ' . ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$r->day_of_week ?? 1] . ' at ' . $r->run_time,
                'monthly' => 'Monthly on day ' . ($r->day_of_month ?? 1) . ' at ' . $r->run_time,
                default   => 'Daily at ' . $r->run_time,
            },
        ];
    }

    private function availableReportKeys(): array
    {
        return [
            'payslips'        => 'Payslip Summary',
            'gross_breakdown' => 'Gross Salary Breakdown',
            'deductions'      => 'Deductions Detail',
            'net_pay'         => 'Net Pay Report',
            'overtime'        => 'Overtime Report',
            'headcount'       => 'Headcount by Department',
            'variance'        => 'Month-on-Month Variance',
            'tax'             => 'Tax Liability',
            'anomalies'       => 'Payroll Anomalies',
            'corrections'     => 'Auto-Corrections',
            'leakage'         => 'Leakage & Fraud Risk',
        ];
    }
}
