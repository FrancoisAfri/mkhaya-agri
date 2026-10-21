<?php

namespace App\Modules\HR\Hr\Attendance\Http\Controllers;

use App\Modules\HR\Hr\Attendance\Support\Sites;
use App\Modules\HR\Hr\Attendance\Models\Correction;
use App\Modules\HR\Hr\Attendance\Models\ClockEvent;
use App\Modules\HR\Hr\Attendance\Models\OvertimeRequest;
use App\Modules\HR\Hr\Attendance\Models\Timesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Attendance\Services\Attendance;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Phase 2 §1.3–1.4: timesheets, the kiosk, corrections, overtime and approvals. */
class AttendanceController extends EngineController
{
    public function __construct(private readonly Attendance $attendance) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_WORKFORCE, R::APPROVE_ATTENDANCE, R::RECORD_ATTENDANCE);
        $farm = $this->farmFilter($request);
        $from = Carbon::parse($request->query('from', today()->subDays(6)->toDateString()));
        $to = Carbon::parse($request->query('to', today()->toDateString()));
        $status = $request->query('status', 'all');

        $sheets = Timesheet::query()->when($farm || $request->query('farm') === 'organisation', fn ($q) => $q->where('farm_id', $farm?->id))
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->when($status === 'flagged', fn ($q) => $q->whereRaw("jsonb_array_length(COALESCE(flags, '[]'::jsonb)) > 0"))
            ->when(! in_array($status, ['all', 'flagged'], true), fn ($q) => $q->where('status', $status))
            ->with(['worker.person', 'farm'])->orderByDesc('work_date')->orderBy('clock_in_at')->limit(500)->get();

        return Inertia::render('HR/Attendance/Time/Timesheets', [
            'timesheets' => $sheets->map(fn (Timesheet $t) => $this->row($t)),
            'corrections' => Correction::query()->where('status', 'pending')->with('timesheet.worker.person')->get()
                ->map(fn ($c) => ['id' => $c->id, 'uuid' => $c->uuid, 'worker' => $c->timesheet?->worker?->displayName(), 'date' => $c->timesheet?->work_date?->toDateString(),
                    'field' => $c->field, 'original_at' => $c->original_at?->format('Y-m-d H:i'), 'corrected_at' => $c->corrected_at->format('Y-m-d H:i'),
                    'reason' => $c->reason, 'mine' => (int) $c->requested_by === (int) $request->user()->id]),
            'overtime' => OvertimeRequest::query()->where('status', 'requested')->get()
                ->map(fn ($o) => ['id' => $o->id, 'uuid' => $o->uuid, 'worker' => Worker::query()->find($o->worker_id)?->displayName(), 'date' => $o->work_date->toDateString(),
                    'hours' => round($o->minutes / 60, 2), 'reason' => $o->reason, 'emergency' => $o->emergency]),
            'summary' => $farm ? $this->attendance->plannedVersusActual($farm, $from, $to) : null,
            'filters' => ['farm' => $farm?->uuid ?? ($request->query('farm') === 'organisation' ? 'organisation' : null), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'status' => $status],
            'options' => ['farms' => $this->farmOptions(), 'workers' => Worker::query()->where('status', 'active')->with('person')->get()
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->displayName()])->sortBy('name')->values()],
            'can' => ['approve' => $request->user()->can(R::APPROVE_ATTENDANCE), 'record' => $request->user()->can(R::RECORD_ATTENDANCE)],
        ]);
    }

    /** A farm device for clocking crews: scan a badge or pick a name. */
    public function kiosk(Request $request): Response
    {
        $this->allow($request, R::RECORD_ATTENDANCE);
        $first = $this->farmOptions(true)[0] ?? null;
        $farm = $this->farmFilter($request) ?? ($request->query('farm') === 'organisation' || ! $first ? null : Sites::byUuid($first['id']));
        $today = Timesheet::query()->when($farm || $request->query('farm') === 'organisation', fn ($q) => $q->where('farm_id', $farm?->id))->whereDate('work_date', today())
            ->with('worker.person')->orderByDesc('clock_in_at')->get();

        return Inertia::render('HR/Attendance/Time/BadgeKiosk', [
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $this->farmOptions(true),
            'workers' => Worker::query()->when($farm || $request->query('farm') === 'organisation', fn ($q) => $q->where('farm_id', $farm?->id))->where('status', 'active')->with(['person', 'crew'])->get()
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->displayName(), 'crew' => $w->crew?->code,
                    'in' => $today->contains(fn ($t) => (int) $t->worker_id === $w->id && $t->clock_in_at && ! $t->clock_out_at)])
                ->sortBy('name')->values(),
            'today' => $today->map(fn ($t) => $this->row($t)),
        ]);
    }

    /** Clock somebody in or out from a supervisor's device or the kiosk (by worker, or by badge). */
    public function clock(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_ATTENDANCE);
        $data = $request->validate([
            'worker_id' => ['nullable', 'integer'], 'badge' => ['nullable', 'string', 'max:120'], 'kind' => ['required', Rule::in(['in', 'out', 'auto'])],
            'method' => ['nullable', Rule::in(['kiosk', 'qr', 'supervisor', 'biometric'])], 'occurred_at' => ['nullable', 'date'],
            'farm_id' => ['nullable', 'string'], 'client_id' => ['nullable', 'uuid'],
        ]);
        $badge = isset($data['badge']) ? preg_replace('/^MKAGRI:/', '', trim($data['badge'])) : null;
        $worker = $badge ? Worker::query()->where('badge_token', $badge)->first()
            : (! empty($data['worker_id']) ? Worker::query()->find($data['worker_id']) : null);
        if (! $worker) {
            throw ValidationException::withMessages(['badge' => 'Badge not recognised, or the worker is on a farm you cannot reach.']);
        }
        $this->assertWritable($request, $worker->farm_id);
        $farm = ! empty($data['farm_id']) ? $this->farm($data['farm_id']) : null;
        if ($data['kind'] === 'auto') {
            $data['kind'] = $this->attendance->nextKind($worker, Carbon::parse($data['occurred_at'] ?? now()));
        }
        $event = $this->attendance->clock($worker, $data['kind'], Carbon::parse($data['occurred_at'] ?? now()), $data['method'] ?? ($badge ? 'qr' : 'kiosk'),
            ['farm_id' => $farm?->id, 'client_id' => $data['client_id'] ?? null, 'supervisor_confirmed' => true], $request->user());

        return back()->with('success', "{$worker->displayName()} clocked {$data['kind']} at ".$event->occurred_at->format('H:i')
            .($event->verification !== 'verified' ? ' — flagged: '.$event->verification_note : '.'));
    }

    public function requestCorrection(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->allow($request, R::RECORD_ATTENDANCE, R::APPROVE_ATTENDANCE);
        $data = $request->validate(['field' => ['required', Rule::in(['clock_in', 'clock_out'])], 'corrected_at' => ['required', 'date', 'before_or_equal:now'],
            'reason' => ['required', 'string', 'max:500']]);
        $this->attendance->requestCorrection($timesheet, $data['field'], Carbon::parse($data['corrected_at']), $data['reason'], $request->user());

        return back()->with('success', 'Correction requested; somebody else approves it.');
    }

    public function reviewCorrection(Request $request, Correction $correction): RedirectResponse
    {
        $this->allow($request, R::APPROVE_ATTENDANCE);
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $this->attendance->reviewCorrection($correction, (bool) $data['approve'], $request->user());

        return back()->with('success', $data['approve'] ? 'Correction approved; the original stays on record.' : 'Correction rejected.');
    }

    public function decide(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'confirm'])], 'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:300']]);
        $this->allow($request, $data['action'] === 'confirm' ? R::RECORD_ATTENDANCE : R::APPROVE_ATTENDANCE);
        $this->assertWritable($request, $timesheet->farm_id);
        $this->notOwn($request, $timesheet);
        match ($data['action']) {
            'approve' => $this->attendance->approve($timesheet, $request->user()),
            'reject' => $this->attendance->reject($timesheet, $data['reason'], $request->user()),
            'confirm' => $this->attendance->confirm($timesheet, $request->user()),
        };

        return back()->with('success', 'Timesheet '.($data['action'] === 'confirm' ? 'confirmed' : $data['action'].'d').'.');
    }

    /** Approve many at once — only the clean ones; anything flagged needs a look first. */
    public function approveMany(Request $request): RedirectResponse
    {
        $this->allow($request, R::APPROVE_ATTENDANCE);
        $ids = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']])['ids'];
        $done = 0;
        $skipped = 0;
        foreach (Timesheet::query()->whereIn('id', $ids)->get() as $t) {
            $flags = array_diff($t->flags ?? [], ['no_shift']);
            if ($t->status !== Timesheet::COMPLETE || $flags || $this->isOwn($request, $t)) {
                $skipped++;

                continue;
            }
            $this->attendance->approve($t, $request->user());
            $done++;
        }

        return back()->with('success', "{$done} approved".($skipped ? ", {$skipped} left for a look (flagged, incomplete or your own)" : '').'.');
    }

    public function requestOvertime(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_ATTENDANCE, R::MANAGE_ROSTER);
        $data = $request->validate(['worker_id' => ['required', 'integer'], 'work_date' => ['required', 'date'], 'hours' => ['required', 'numeric', 'gt:0', 'max:12'],
            'reason' => ['required', 'string', 'max:300'], 'emergency' => ['boolean']]);
        $w = Worker::query()->findOrFail($data['worker_id']);
        $ot = OvertimeRequest::create(['company_id' => $w->company_id, 'farm_id' => $w->farm_id, 'worker_id' => $w->id, 'work_date' => $data['work_date'],
            'minutes' => (int) round($data['hours'] * 60), 'reason' => $data['reason'], 'emergency' => (bool) ($data['emergency'] ?? false),
            'status' => ($data['emergency'] ?? false) && $request->user()->can(R::APPROVE_ATTENDANCE) ? 'approved' : 'requested',
            'requested_by' => $request->user()->id]);
        app(\App\Modules\Agri\Services\AgriNotifier::class)->overtime($ot, $ot->status);

        return back()->with('success', 'Overtime request recorded.');
    }

    public function reviewOvertime(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->allow($request, R::APPROVE_ATTENDANCE);
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        if ((int) $overtime->requested_by === (int) $request->user()->id) {
            throw ValidationException::withMessages(['overtime' => 'Somebody else approves an overtime request you made.']);
        }
        $overtime->update(['status' => $data['approve'] ? 'approved' : 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        app(\App\Modules\Agri\Services\AgriNotifier::class)->overtime($overtime, $overtime->status);
        foreach (Timesheet::query()->where('worker_id', $overtime->worker_id)->whereDate('work_date', $overtime->work_date)->get() as $t) {
            $this->attendance->measure($t);
        }

        return back()->with('success', 'Overtime '.($data['approve'] ? 'approved' : 'rejected').'.');
    }

    public function events(Request $request, Timesheet $timesheet)
    {
        $this->allow($request, R::VIEW_WORKFORCE, R::APPROVE_ATTENDANCE);

        return response()->json(ClockEvent::query()->where('worker_id', $timesheet->worker_id)
            ->whereBetween('occurred_at', [$timesheet->work_date->copy()->subDay(), $timesheet->work_date->copy()->addDays(2)])->orderBy('occurred_at')->get()
            ->map(fn ($e) => ['id' => $e->id, 'kind' => $e->kind, 'at' => $e->occurred_at->format('Y-m-d H:i'), 'method' => $e->method,
                'verification' => $e->verification, 'note' => $e->verification_note, 'offline' => $e->captured_offline,
                'superseded' => (bool) $e->superseded_by_id, 'confirmed' => $e->supervisor_confirmed]));
    }

    private function row(Timesheet $t): array
    {
        return ['id' => $t->id, 'uuid' => $t->uuid, 'worker' => $t->worker?->displayName(), 'worker_id' => $t->worker_id, 'farm' => $t->farm?->name,
            'date' => $t->work_date->toDateString(), 'scheduled' => $t->scheduled_start ? $t->scheduled_start->format('H:i').'–'.$t->scheduled_end?->format('H:i') : null,
            'in' => $t->clock_in_at?->format('H:i'), 'out' => $t->clock_out_at?->format($t->clock_out_at && $t->clock_in_at && ! $t->clock_out_at->isSameDay($t->clock_in_at) ? 'D H:i' : 'H:i'),
            'hours' => round($t->worked_minutes / 60, 2), 'overtime' => round($t->overtime_minutes / 60, 2), 'late' => $t->late_minutes,
            'flags' => $t->flags ?? [], 'status' => $t->status, 'cost' => $t->cost_amount, 'by_date' => $t->minutes_by_date, 'sent' => (bool) $t->handoff_line_id];
    }

    private function isOwn(Request $request, Timesheet $t): bool
    {
        $w = Worker::withoutGlobalScopes()->with('person')->find($t->worker_id);

        return $w && (int) $w->person?->user_id === (int) $request->user()->id;
    }

    private function notOwn(Request $request, Timesheet $t): void
    {
        if ($this->isOwn($request, $t)) {
            throw ValidationException::withMessages(['timesheet' => 'Somebody else approves your own time.']);
        }
    }
}
