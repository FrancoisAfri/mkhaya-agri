<?php

namespace App\Modules\HR\Hr\Attendance\Http\Controllers;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\HR\Hr\Attendance\Models\Crew;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\Agri\Models\FarmWorkOrder;
use App\Modules\HR\Hr\Attendance\Models\PieceRate;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use App\Modules\HR\Hr\Attendance\Services\Piecework;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Phase 2 §1.5: piecework — submit, verify, approve, reject, query; output against target. */
class PieceworkController extends EngineController
{
    public function __construct(private readonly Piecework $piecework) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_WORKFORCE, R::APPROVE_PIECEWORK);
        $farm = $this->farmFilter($request);
        $from = Carbon::parse($request->query('from', today()->subDays(13)->toDateString()));
        $to = Carbon::parse($request->query('to', today()->toDateString()));
        $entries = PieceworkEntry::query()->when($farm || $request->query('farm') === 'organisation', fn ($q) => $q->where('farm_id', $farm?->id))
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->with(['worker.person', 'crew', 'farm', 'shares.worker.person'])->orderByDesc('work_date')->limit(500)->get();

        return Inertia::render('HR/Attendance/Time/Piecework', [
            'entries' => $entries->map(fn (PieceworkEntry $e) => [
                'id' => $e->id, 'uuid' => $e->uuid, 'date' => $e->work_date->toDateString(), 'farm' => $e->farm?->name, 'activity' => $e->activity, 'unit' => $e->unit,
                'who' => $e->worker?->displayName() ?? ('Crew '.$e->crew?->code), 'allocation' => $e->allocation,
                'measured' => $e->measured_quantity, 'approved' => $e->approved_quantity, 'rejected' => $e->rejected_quantity, 'rework' => $e->rework_quantity,
                'rate' => $e->rate, 'amount' => $e->amount, 'status' => $e->status, 'note' => $e->review_note, 'sent' => (bool) $e->handoff_line_id,
                'shares' => $e->shares->map(fn ($s) => ['name' => $s->worker?->displayName(), 'percent' => $s->percent, 'quantity' => $s->quantity, 'amount' => $s->amount]),
            ]),
            'summary' => $farm ? $this->piecework->summary($farm->id, $from, $to) : [],
            'filters' => ['farm' => $farm?->uuid ?? ($request->query('farm') === 'organisation' ? 'organisation' : null), 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'options' => [
                'farms' => $this->farmOptions(true), 'activities' => PieceRate::ACTIVITIES, 'units' => PieceRate::UNITS,
                'workers' => Worker::query()->where('status', 'active')->with('person')->get()->map(fn ($w) => ['id' => $w->id, 'name' => $w->displayName(), 'farm_id' => $w->farm_id, 'crew_id' => $w->crew_id])->sortBy('name')->values(),
                'crews' => Crew::query()->orderBy('code')->get(['id', 'code', 'name', 'farm_id']),
                'plans' => CropPlan::query()->whereIn('status', CropPlan::WORKABLE)->get(['id', 'code', 'farm_id']),
                'work_orders' => FarmWorkOrder::query()->whereIn('status', FarmWorkOrder::OPEN)->get(['id', 'reference', 'title', 'farm_id']),
                'rates' => PieceRate::query()->where('status', 'approved')->get(['activity', 'unit', 'rate', 'daily_target', 'farm_id']),
            ],
            'can' => ['approve' => $request->user()->can(R::APPROVE_PIECEWORK), 'record' => $request->user()->can(R::RECORD_ATTENDANCE) || $request->user()->can(R::APPROVE_PIECEWORK)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, R::RECORD_ATTENDANCE, R::APPROVE_PIECEWORK, R::MANAGE_ROSTER);
        $data = $request->validate([
            'farm_id' => ['nullable', 'string'], 'work_date' => ['required', 'date', 'before_or_equal:today'],
            'activity' => ['required', Rule::in(PieceRate::ACTIVITIES)], 'unit' => ['required', Rule::in(PieceRate::UNITS)],
            'worker_id' => ['nullable', 'integer'], 'crew_id' => ['nullable', 'integer'], 'allocation' => ['nullable', Rule::in(PieceworkEntry::ALLOCATIONS)],
            'measured_quantity' => ['required', 'numeric', 'gt:0'], 'field_id' => ['nullable', 'string'], 'crop_plan_id' => ['nullable', 'integer'],
            'work_order_id' => ['nullable', 'integer'], 'shares' => ['nullable', 'array'], 'shares.*.worker_id' => ['required', 'integer'],
            'shares.*.percent' => ['nullable', 'numeric', 'between:0,100'], 'shares.*.quantity' => ['nullable', 'numeric', 'gte:0'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
        foreach (['worker_id' => Worker::class, 'crew_id' => Crew::class, 'crop_plan_id' => CropPlan::class, 'work_order_id' => FarmWorkOrder::class] as $k => $class) {
            if (! empty($data[$k]) && ! $class::query()->whereKey($data[$k])->exists()) {
                throw ValidationException::withMessages([$k => 'Not found among the people and work you can reach.']);
            }
        }
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        $this->piecework->submit($farm, ['field_id' => $field?->id] + collect($data)->except(['farm_id', 'field_id'])->all(), $request->user());

        return back()->with('success', 'Production recorded; it is paid once a supervisor approves the quantity.');
    }

    public function review(Request $request, PieceworkEntry $entry): RedirectResponse
    {
        $this->allow($request, R::APPROVE_PIECEWORK);
        $this->assertWritable($request, $entry->farm_id);
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'reject', 'query'])], 'approved_quantity' => ['required_if:action,approve', 'nullable', 'numeric', 'gte:0'],
            'rework_quantity' => ['nullable', 'numeric', 'gte:0'], 'note' => ['required_unless:action,approve', 'nullable', 'string', 'max:500']]);
        match ($data['action']) {
            'approve' => $this->piecework->approve($entry, (float) $data['approved_quantity'], (float) ($data['rework_quantity'] ?? 0), $data['note'] ?? null, $request->user()),
            'reject' => $this->piecework->reject($entry, $data['note'], $request->user()),
            'query' => $this->piecework->query($entry, $data['note'], $request->user()),
        };

        return back()->with('success', 'Production '.($data['action'] === 'query' ? 'queried' : $data['action'].'d').'.');
    }
}
