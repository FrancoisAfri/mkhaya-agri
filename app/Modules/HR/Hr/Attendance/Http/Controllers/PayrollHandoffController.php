<?php

namespace App\Modules\HR\Hr\Attendance\Http\Controllers;

use App\Modules\HR\Hr\Attendance\Models\PayrollHandoff;
use App\Modules\HR\Hr\Attendance\Services\PayrollHandoffs;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Phase 2 §1.6: handing approved time and piecework to payroll, once, with reconciliation. */
class PayrollHandoffController extends EngineController
{
    public function __construct(private readonly PayrollHandoffs $handoffs) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::PAYROLL_HANDOFF);
        $selected = $request->query('handoff') ? PayrollHandoff::query()->where('uuid', $request->query('handoff'))->first() : PayrollHandoff::query()->latest('id')->first();

        return Inertia::render('HR/Attendance/Time/PayrollHandoff', [
            'handoffs' => PayrollHandoff::query()->latest('id')->limit(50)->get()->map(fn ($h) => ['id' => $h->uuid, 'reference' => $h->reference,
                'period' => $h->period_start->toDateString().' → '.$h->period_end->toDateString(), 'status' => $h->status, 'totals' => $h->totals]),
            'selected' => $selected ? ['id' => $selected->uuid, 'reference' => $selected->reference, 'status' => $selected->status, 'totals' => $selected->totals,
                'period_start' => $selected->period_start->toDateString(), 'period_end' => $selected->period_end->toDateString(),
                'lines' => $selected->lines()->with('worker.person')->get()->map(fn ($l) => ['worker' => $l->worker?->displayName(),
                    'regular' => round($l->regular_minutes / 60, 2), 'overtime' => round($l->overtime_minutes / 60, 2), 'sunday' => round($l->sunday_minutes / 60, 2),
                    'holiday' => round($l->holiday_minutes / 60, 2), 'piecework' => $l->piecework_amount, 'timesheets' => $l->timesheets,
                    'delivered_hours' => $l->delivered_minutes !== null ? round($l->delivered_minutes / 60, 2) : null, 'delivered_piecework' => $l->delivered_piecework,
                    'to' => $l->delivered_to, 'status' => $l->status, 'note' => $l->note])] : null,
            'defaults' => ['period_start' => today()->startOfMonth()->toDateString(), 'period_end' => today()->toDateString()],
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $this->allow($request, R::PAYROLL_HANDOFF);
        $data = $request->validate(['period_start' => ['required', 'date'], 'period_end' => ['required', 'date']]);
        $h = $this->handoffs->prepare((int) $request->user()->company_id, Carbon::parse($data['period_start']), Carbon::parse($data['period_end']), $request->user());

        return redirect()->route('attendance.payroll-handoff.index', ['handoff' => $h->uuid])->with('success', "Handoff {$h->reference} prepared. Check it, then send it to payroll.");
    }

    public function send(Request $request, PayrollHandoff $handoff): RedirectResponse
    {
        $this->allow($request, R::PAYROLL_HANDOFF);
        $h = $this->handoffs->send($handoff, $request->user());

        return back()->with('success', "{$h->reference} sent: hours to HR attendance, piecework to the open payroll run; anything without an in-house payslip is ready to export.");
    }

    public function reconcile(Request $request, PayrollHandoff $handoff): RedirectResponse
    {
        $this->allow($request, R::PAYROLL_HANDOFF);
        $this->handoffs->reconcile($handoff, $request->user());

        return back()->with('success', 'Handoff reconciled and closed.');
    }

    public function export(Request $request, PayrollHandoff $handoff): StreamedResponse
    {
        $this->allow($request, R::PAYROLL_HANDOFF);
        $lines = $handoff->lines()->with('worker.person')->get();

        return response()->streamDownload(function () use ($lines, $handoff) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Handoff', 'Employee number', 'Name', 'Regular hours', 'Overtime hours', 'Sunday hours', 'Public holiday hours', 'Night hours', 'Piecework', 'Delivered to', 'Status']);
            foreach ($lines as $l) {
                fputcsv($out, [$handoff->reference, $l->worker?->person?->employee_number, $l->worker?->displayName(), round($l->regular_minutes / 60, 2),
                    round($l->overtime_minutes / 60, 2), round($l->sunday_minutes / 60, 2), round($l->holiday_minutes / 60, 2), round($l->night_minutes / 60, 2),
                    $l->piecework_amount, $l->delivered_to, $l->status]);
            }
            fclose($out);
        }, "{$handoff->reference}.csv", ['Content-Type' => 'text/csv']);
    }
}
