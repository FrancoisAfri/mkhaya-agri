<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\FarmTask;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\Agri\Support\RoleCatalog as R;
use App\Modules\Agri\Services\OfflineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module K: the phone screen for people in the field — today's tasks and activities assigned to
 * me, with one-tap start / submit / complete and a quick scouting report. Uses the same endpoints
 * and rules as the full screens; it only lays them out for a small screen.
 */
class MyWorkController extends AgriController
{
    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_TASKS, R::RECORD_ACTIVITIES, R::VIEW_WORK_ORDERS);
        $user = $request->user();
        $worker = \App\Modules\HR\Hr\Attendance\Models\Worker::query()->whereHas('person', fn ($q) => $q->withoutGlobalScopes()->where('user_id', $user->id))->first();
        $open = $worker ? \App\Modules\HR\Hr\Attendance\Models\Timesheet::query()->where('worker_id', $worker->id)->whereNotNull('clock_in_at')
            ->whereNull('clock_out_at')->where('clock_in_at', '>=', now()->subHours(20))->latest('clock_in_at')->first() : null;

        return Inertia::render('Modules/Agri/MyWork', [
            'tasks' => FarmTask::query()->where('assigned_to', $user->id)->whereIn('status', [...FarmTask::PENDING, FarmTask::SUBMITTED])
                ->with(['farm', 'field'])->orderByRaw('due_on IS NULL')->orderBy('due_on')->get()
                ->map(fn ($t) => ['id' => $t->uuid, 'title' => $t->title, 'description' => $t->description, 'status' => $t->status,
                    'priority' => $t->priority, 'due_on' => $t->due_on?->toDateString(), 'overdue' => $t->isOverdue(),
                    'farm' => $t->farm?->name, 'field' => $t->field?->code, 'review_note' => $t->review_note]),
            'activities' => FieldActivity::query()->where('assigned_to', $user->id)->whereIn('status', ['planned', 'in_progress'])
                ->with(['farm', 'field', 'cropPlan', 'inputs.stockItem'])->orderBy('planned_on')->get()
                ->map(fn ($a) => ['id' => $a->uuid, 'title' => $a->title, 'type' => $a->activity_type, 'status' => $a->status,
                    'planned_on' => $a->planned_on->toDateString(), 'farm' => $a->farm?->name, 'field' => $a->field?->code,
                    'plan' => $a->cropPlan?->code, 'inputs' => $a->inputs->map(fn ($i) => rtrim(rtrim(number_format((float) $i->quantity, 3, '.', ' '), '0'), '.')." {$i->uom} {$i->stockItem?->name}")]),
            'farms' => $this->farmOptions(true),
            'me' => $user->id,
            'worker' => $worker ? ['name' => $worker->displayName(), 'clocked_in_at' => $open?->clock_in_at?->toIso8601String(), 'clocked_in_time' => $open?->clock_in_at?->format('H:i'),
                'gps_allowed' => $worker->gps_allowed, 'farm_id' => $worker->farm?->uuid, 'farm' => $worker->farm?->name] : null,
            // What can be claimed as piecework today: the approved rates for this worker's farm.
            'rates' => $worker ? \App\Modules\HR\Hr\Attendance\Models\PieceRate::query()->where('status', 'approved')
                ->whereDate('valid_from', '<=', today())->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', today()))
                ->where(fn ($q) => $q->whereNull('farm_id')->orWhere('farm_id', $worker->farm_id))->get(['activity', 'unit', 'rate'])
                ->unique(fn ($r) => "{$r->activity}|{$r->unit}")->values()->map(fn ($r) => ['activity' => $r->activity, 'unit' => $r->unit, 'rate' => (float) $r->rate]) : [],
            'shifts' => $worker ? \App\Modules\HR\Hr\Attendance\Models\ShiftAssignment::query()->where('worker_id', $worker->id)->where('attendance_shift_assignments.status', 'assigned')
                ->join('attendance_rostered_shifts as s', 's.id', '=', 'attendance_shift_assignments.shift_id')->where('s.status', 'published')
                ->where('s.ends_at', '>=', now())->where('s.starts_at', '<=', now()->addDays(7))->orderBy('s.starts_at')
                ->get(['s.title', 's.starts_at', 's.ends_at'])->map(fn ($x) => ['title' => $x->title,
                    'when' => \App\Modules\Agri\Support\FarmTime::local(\Illuminate\Support\Carbon::parse($x->starts_at, 'UTC'), $worker)->format('D j M H:i').'–'
                        .\App\Modules\Agri\Support\FarmTime::local(\Illuminate\Support\Carbon::parse($x->ends_at, 'UTC'), $worker)->format('H:i')]) : [],
            'jobs' => $worker ? \App\Modules\Agri\Models\FarmWorkOrder::query()->whereIn('status', ['approved', 'in_progress', 'paused', 'rejected'])
                ->whereHas('workers', fn ($q) => $q->where('worker_id', $worker->id))->with(['farm', 'materials.stockItem', 'machines.machine'])->orderBy('planned_start')->get()
                ->map(fn ($o) => ['id' => $o->uuid, 'reference' => $o->reference, 'title' => $o->title, 'status' => $o->status, 'farm' => $o->farm?->name,
                    'instructions' => $o->instructions, 'when' => $o->planned_start->format('D j M H:i'),
                    'map' => WorkOrderController::mapPoint($o->field_id ? \App\Modules\Agri\Models\FarmField::query()->find($o->field_id) : null, $o->farm),
                    'materials' => $o->materials->map(fn ($m) => ['id' => $m->id, 'label' => rtrim(rtrim(number_format($m->planned_quantity, 3, '.', ' '), '0'), '.')." {$m->stockItem?->uom} {$m->stockItem?->name}"]),
                    'machines' => $o->machines->map(fn ($m) => ['id' => $m->machine_id, 'label' => "{$m->machine?->code} {$m->machine?->name}"])]) : [],
            'can' => ['observe' => $user->can(R::RECORD_ACTIVITIES)],
        ]);
    }

    /**
     * Changes the phone queued while it had no signal, sent in one batch when it reconnects. Each
     * comes back applied or rejected with the reason; a change already received is answered again,
     * never applied twice.
     */
    public function sync(Request $request, OfflineSync $sync): JsonResponse
    {
        $this->allow($request, R::VIEW_TASKS, R::RECORD_ACTIVITIES, R::VIEW_WORK_ORDERS);
        $data = $request->validate([
            'ops' => ['required', 'array', 'max:200'],
            'ops.*.client_id' => ['required', 'uuid'],
            'ops.*.op' => ['required', Rule::in(OfflineSync::OPS)],
            'ops.*.payload' => ['nullable', 'array'],
            'ops.*.captured_at' => ['nullable', 'date'],
        ]);

        return response()->json(['results' => $sync->apply($data['ops'], $request->user())]);
    }
}
