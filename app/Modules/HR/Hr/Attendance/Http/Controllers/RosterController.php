<?php

namespace App\Modules\HR\Hr\Attendance\Http\Controllers;

use App\Modules\HR\Hr\Attendance\Support\Sites;
use App\Modules\HR\Hr\Attendance\Models\Crew;
use App\Modules\HR\Hr\Attendance\Models\RosterPattern;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift;
use App\Modules\HR\Hr\Attendance\Models\ShiftAssignment;
use App\Modules\HR\Hr\Attendance\Models\ShiftChange;
use App\Modules\HR\Hr\Attendance\Models\ShiftSwap;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Attendance\Models\ShiftTemplate;
use App\Modules\HR\Hr\Attendance\Services\HrSources;
use App\Modules\HR\Hr\Attendance\Services\Rostering;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Phase 2 §1.2: the roster planner — a week (or two) of shifts by worker, with checks, versions and swaps. */
class RosterController extends EngineController
{
    public function __construct(private readonly Rostering $roster) {}

    public function index(Request $request): Response
    {
        $this->allow($request, R::VIEW_WORKFORCE, R::MANAGE_ROSTER);
        // A farm's roster, or the organisation's own (no site): someone who runs attendance for the
        // organisation starts there; a farm manager starts on their first farm.
        $farms = collect($this->farmOptions(true));
        $farm = $this->farmFilter($request)
            ?? ($request->query('farm') === 'organisation' || $this->may($request, 'attendance.roster.view') || $farms->isEmpty() ? null : Sites::byUuid($farms->first()['id']));
        $from = Carbon::parse($request->query('from', today()->startOfWeek()->toDateString()))->startOfDay();
        $days = min(28, max(1, (int) $request->query('days', 7)));
        $to = $from->copy()->addDays($days)->subSecond();

        $shifts = RosteredShift::query()->where('farm_id', $farm?->id)->whereBetween('starts_at', [$from, $to])
            ->with(['assignments' => fn ($q) => $q->with('worker.person'), 'template'])->orderBy('starts_at')->get();
        $workers = Worker::query()->where('farm_id', $farm?->id)->where('status', Worker::ACTIVE)->with(['person', 'crew'])->get()
            ->map(fn ($w) => ['id' => $w->id, 'name' => $w->displayName(), 'category' => $w->category, 'crew' => $w->crew?->code])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        return Inertia::render('HR/Attendance/Time/Roster', [
            'farm' => $farm ? ['id' => $farm->uuid, 'name' => $farm->name] : null,
            'farms' => $farms->map(fn ($f) => ['id' => $f['id'], 'name' => $f['name']])->values(),
            'period' => ['from' => $from->toDateString(), 'days' => $days],
            'shifts' => $shifts->map(fn (RosteredShift $s) => [
                'id' => $s->id, 'uuid' => $s->uuid, 'title' => $s->title, 'status' => $s->status, 'revision' => $s->revision,
                'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(),
                'date' => $s->starts_at->toDateString(), 'label' => $s->starts_at->format('H:i').'–'.$s->ends_at->format('H:i').($s->ends_at->toDateString() !== $s->starts_at->toDateString() ? ' (+1)' : ''),
                'min_staff' => $s->min_staff, 'required_certifications' => $s->required_certifications ?? [], 'template' => $s->template?->code,
                'assignments' => $s->assignments->map(fn ($a) => ['id' => $a->id, 'uuid' => $a->uuid, 'worker_id' => $a->worker_id, 'name' => $a->worker?->displayName(),
                    'status' => $a->status, 'warnings' => $a->warnings])->values(),
            ])->values(),
            'gaps' => $this->roster->gaps($shifts),
            // What the farm has planned in the same days, so the roster is set against the work.
            'demand' => $farm ? app(\App\Modules\Agri\Services\WorkforceAnalytics::class)->demand($farm, $from, $to) : [],
            'crews' => Crew::query()->where('farm_id', $farm?->id)->where('status', 'active')->withCount(['workers' => fn ($q) => $q->where('status', Worker::ACTIVE)])
                ->orderBy('code')->get()->map(fn ($c) => ['id' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'workers' => $c->workers_count]),
            'patterns' => RosterPattern::query()->where('farm_id', $farm?->id)->where('is_active', true)->with('crew')->orderBy('season')->orderBy('name')->get()
                ->map(fn ($p) => ['id' => $p->uuid, 'name' => $p->name, 'season' => $p->season, 'cycle' => $p->cycle, 'crew' => $p->crew?->code,
                    'people' => $p->crew_id ? null : count($p->workers ?? [])]),
            'workers' => $workers,
            'swaps' => ShiftSwap::query()->where('farm_id', $farm?->id)->where('status', 'requested')->get()
                ->map(fn ($s) => ['id' => $s->id, 'uuid' => $s->uuid, 'assignment_id' => $s->assignment_id, 'to' => Worker::query()->find($s->to_worker_id)?->displayName(), 'reason' => $s->reason]),
            'options' => [
                'templates' => ShiftTemplate::query()->where('is_active', true)->orderBy('starts')->get(['id', 'code', 'name', 'starts', 'ends', 'break_minutes', 'min_staff']),
                'competencies' => app(HrSources::class)->competencies((int) $request->user()->company_id),
                'farms' => $this->farmOptions(true),
            ],
            'can' => ['manage' => $request->user()->can(R::MANAGE_ROSTER)],
        ]);
    }

    public function storeShift(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $data = $request->validate([
            'farm_id' => ['nullable', 'string'], 'template_id' => ['nullable', 'integer'], 'date' => ['required_with:template_id', 'nullable', 'date'],
            'title' => ['required_without:template_id', 'nullable', 'string', 'max:120'], 'starts_at' => ['required_without:template_id', 'nullable', 'date'],
            'ends_at' => ['required_without:template_id', 'nullable', 'date'], 'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'min_staff' => ['nullable', 'integer', 'min:1'], 'required_certifications' => ['nullable', 'array'], 'field_id' => ['nullable', 'string'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        if (! empty($data['template_id'])) {
            $this->roster->fromTemplate($farm, ShiftTemplate::query()->findOrFail($data['template_id']), Carbon::parse($data['date']), $request->user(), ['field_id' => $field?->id]);
        } else {
            $this->roster->createShift($farm, ['field_id' => $field?->id] + collect($data)->except(['farm_id', 'field_id', 'template_id', 'date'])->all(), $request->user());
        }

        return back()->with('success', 'Shift added.');
    }

    public function updateShift(Request $request, RosteredShift $shift): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $this->assertWritable($request, $shift->farm_id);
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:120'], 'starts_at' => ['sometimes', 'date'], 'ends_at' => ['sometimes', 'date'],
            'break_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'], 'min_staff' => ['sometimes', 'integer', 'min:1'],
            'required_certifications' => ['sometimes', 'nullable', 'array'], 'reason' => ['nullable', 'string', 'max:300']]);
        $this->roster->update($shift, collect($data)->except('reason')->all(), $data['reason'] ?? null, $request->user());

        return back()->with('success', 'Shift updated'.($shift->status === RosteredShift::PUBLISHED ? '; the people on it are told.' : '.'));
    }

    public function cancelShift(Request $request, RosteredShift $shift): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $this->assertWritable($request, $shift->farm_id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $this->roster->cancel($shift, $data['reason'], $request->user());

        return back()->with('success', 'Shift cancelled.');
    }

    public function assign(Request $request, RosteredShift $shift): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $this->assertWritable($request, $shift->farm_id);
        $data = $request->validate(['worker_ids' => ['required_without:crew_id', 'array'], 'worker_ids.*' => ['integer'], 'crew_id' => ['nullable', 'string'],
            'accept_warnings' => ['boolean'], 'reason' => ['nullable', 'string', 'max:300']]);
        if (! empty($data['crew_id'])) {
            // A whole crew at once: everyone who can go on is placed, and anyone who cannot is named.
            $crew = Crew::query()->where('uuid', $data['crew_id'])->firstOrFail();
            $placed = 0;
            $skipped = [];
            foreach ($crew->workers()->where('status', Worker::ACTIVE)->with('person')->get() as $w) {
                if ($shift->assignments()->where('worker_id', $w->id)->where('status', 'assigned')->exists()) {
                    continue;
                }
                try {
                    $this->roster->assign($shift, $w, $request->user(), (bool) ($data['accept_warnings'] ?? false), $data['reason'] ?? null);
                    $placed++;
                } catch (\Illuminate\Validation\ValidationException $e) {
                    $skipped[] = $w->displayName().': '.collect($e->errors())->flatten()->first();
                }
            }

            return back()->with($placed ? 'success' : 'error', "{$placed} of crew {$crew->code} assigned."
                .($skipped ? ' Not placed — '.implode('; ', array_slice($skipped, 0, 4)).(count($skipped) > 4 ? '…' : '') : ''));
        }
        foreach (Worker::query()->whereIn('id', $data['worker_ids'])->get() as $w) {
            $this->roster->assign($shift, $w, $request->user(), (bool) ($data['accept_warnings'] ?? false), $data['reason'] ?? null);
        }

        return back()->with('success', 'Assigned.');
    }

    /** What would go wrong if these people were put on this shift — for the planner to show before assigning. */
    public function check(Request $request, RosteredShift $shift): JsonResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $ids = array_map('intval', (array) $request->query('worker_ids', []));

        return response()->json(Worker::query()->whereIn('id', $ids)->get()
            ->mapWithKeys(fn ($w) => [$w->id => $this->roster->check($shift, $w)]));
    }

    public function unassign(Request $request, ShiftAssignment $assignment): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $this->assertWritable($request, $assignment->farm_id);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $this->roster->unassign($assignment, $data['reason'] ?? 'Removed from shift', $request->user());

        return back()->with('success', 'Removed from the shift.');
    }

    public function rotate(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $data = $request->validate([
            'farm_id' => ['nullable', 'string'], 'cycle' => ['required', 'array', 'min:1', 'max:56'], 'cycle.*' => ['string', 'max:20'],
            'from' => ['required', 'date'], 'days' => ['required', 'integer', 'min:1', 'max:366'],
            'workers' => ['required', 'array', 'min:1'], 'workers.*.id' => ['required', 'integer'], 'workers.*.offset' => ['nullable', 'integer', 'min:0'],
            'field_id' => ['nullable', 'string'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
        $field = $this->fieldOn($farm, $data['field_id'] ?? null);
        $workers = collect($data['workers'])->mapWithKeys(fn ($w) => [(int) $w['id'] => (int) ($w['offset'] ?? 0)])->all();
        $r = $this->roster->generateRotating($farm, $data['cycle'], Carbon::parse($data['from']), (int) $data['days'], $workers, $request->user(), true,
            ['field_id' => $field?->id]);

        return back()->with('success', "{$r['shifts']} shifts and {$r['assignments']} assignments drafted."
            .($r['skipped'] ? ' Not placed: '.implode('; ', array_slice($r['skipped'], 0, 5)).(count($r['skipped']) > 5 ? '…' : '') : ''));
    }

    /** Keep a rotating cycle for a season or production cycle, for the whole crew or named people. */
    public function savePattern(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $data = $request->validate([
            'farm_id' => ['nullable', 'string'], 'name' => ['required', 'string', 'max:120'], 'season' => ['nullable', 'string', 'max:60'],
            'cycle' => ['required', 'array', 'min:1', 'max:56'], 'cycle.*' => ['string', 'max:20'],
            'crew_id' => ['nullable', 'string'], 'workers' => ['required_without:crew_id', 'array'], 'workers.*.id' => ['integer'], 'workers.*.offset' => ['nullable', 'integer', 'min:0'],
            'field_id' => ['nullable', 'string'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
        $crew = ! empty($data['crew_id']) ? Crew::query()->where('uuid', $data['crew_id'])->where('farm_id', $farm?->id)->firstOrFail() : null;
        $codes = ShiftTemplate::query()->pluck('code')->map(fn ($c) => strtoupper($c))->push('OFF')->all();
        foreach ($data['cycle'] as $c) {
            abort_unless(in_array(strtoupper($c), $codes, true), 422, "No shift template with code {$c}.");
        }
        RosterPattern::create(['company_id' => $request->user()->company_id, 'farm_id' => $farm?->id, 'name' => $data['name'], 'season' => $data['season'] ?? null,
            'cycle' => array_map('strtoupper', $data['cycle']), 'crew_id' => $crew?->id,
            'workers' => $crew ? null : collect($data['workers'])->map(fn ($w) => ['id' => (int) $w['id'], 'offset' => (int) ($w['offset'] ?? 0)])->values()->all(),
            'field_id' => $this->fieldOn($farm, $data['field_id'] ?? null)?->id, 'notes' => $data['notes'] ?? null, 'created_by' => $request->user()->id]);

        return back()->with('success', "Pattern \"{$data['name']}\" kept. Apply it to any period from the roster.");
    }

    /** Draft a period's shifts from a kept pattern. The crew is read as it is today. */
    public function applyPattern(Request $request, RosterPattern $pattern): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $this->assertWritable($request, $pattern->farm_id);
        $data = $request->validate(['from' => ['required', 'date'], 'days' => ['required', 'integer', 'min:1', 'max:366']]);
        $workers = $pattern->crew_id
            ? Worker::query()->where('crew_id', $pattern->crew_id)->where('status', Worker::ACTIVE)->pluck('id')->mapWithKeys(fn ($id) => [$id => 0])->all()
            : collect($pattern->workers ?? [])->mapWithKeys(fn ($w) => [(int) $w['id'] => (int) ($w['offset'] ?? 0)])->all();
        abort_if(! $workers, 422, 'Nobody active is on this pattern any more.');
        $r = $this->roster->generateRotating($pattern->farm, $pattern->cycle, Carbon::parse($data['from']), (int) $data['days'], $workers, $request->user(), true,
            ['field_id' => $pattern->field_id]);

        return back()->with('success', "{$pattern->name}: {$r['shifts']} shifts and {$r['assignments']} assignments drafted."
            .($r['skipped'] ? ' Not placed: '.implode('; ', array_slice($r['skipped'], 0, 5)).(count($r['skipped']) > 5 ? '…' : '') : ''));
    }

    public function retirePattern(Request $request, RosterPattern $pattern): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $this->assertWritable($request, $pattern->farm_id);
        $pattern->update(['is_active' => false]);

        return back()->with('success', "Pattern \"{$pattern->name}\" retired.");
    }

    public function publish(Request $request): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $data = $request->validate(['farm_id' => ['nullable', 'string'], 'from' => ['required', 'date'], 'days' => ['required', 'integer', 'min:1', 'max:366']]);
        $farm = $this->workingFarm($request, $data['farm_id'] ?? null);
        $from = Carbon::parse($data['from'])->startOfDay();
        $n = $this->roster->publish(RosteredShift::query()->where('farm_id', $farm?->id)->where('status', RosteredShift::DRAFT)
            ->whereBetween('starts_at', [$from, $from->copy()->addDays((int) $data['days'])])->get(), $request->user());

        return back()->with('success', "{$n} shift(s) published; the people on them are told.");
    }

    public function history(Request $request, RosteredShift $shift): JsonResponse
    {
        $this->allow($request, R::VIEW_WORKFORCE, R::MANAGE_ROSTER);

        return response()->json(ShiftChange::query()->where('shift_id', $shift->id)->orderBy('revision')->get(['revision', 'change', 'before', 'reason', 'changed_by', 'created_at']));
    }

    public function requestSwap(Request $request, ShiftAssignment $assignment): RedirectResponse
    {
        $this->allow($request, R::VIEW_WORKFORCE, R::MANAGE_ROSTER);
        $data = $request->validate(['to_worker_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:300']]);
        $this->roster->requestSwap($assignment, Worker::query()->findOrFail($data['to_worker_id']), $data['reason'], $request->user());

        return back()->with('success', 'Swap requested; a supervisor approves it.');
    }

    public function reviewSwap(Request $request, ShiftSwap $swap): RedirectResponse
    {
        $this->allow($request, R::MANAGE_ROSTER);
        $data = $request->validate(['approve' => ['required', 'boolean'], 'accept_warnings' => ['boolean']]);
        $this->roster->reviewSwap($swap, (bool) $data['approve'], $request->user(), (bool) ($data['accept_warnings'] ?? false));

        return back()->with('success', $data['approve'] ? 'Swap approved.' : 'Swap rejected.');
    }
}
