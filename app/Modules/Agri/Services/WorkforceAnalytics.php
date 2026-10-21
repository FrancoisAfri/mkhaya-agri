<?php

namespace App\Modules\Agri\Services;

use App\Modules\HR\Hr\Attendance\Services\Workforce;
use App\Modules\HR\Hr\Attendance\Services\HrSources;

use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\Farm;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift as FarmShift;
use App\Modules\HR\Hr\Attendance\Models\ShiftAssignment as FarmShiftAssignment;
use App\Modules\HR\Hr\Attendance\Models\Timesheet as FarmTimesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker as FarmWorker;
use App\Modules\Agri\Models\FarmWorkOrder;
use App\Modules\Agri\Models\FieldActivity;
use App\Modules\HR\Hr\Attendance\Models\PieceRate;
use App\Modules\HR\Hr\Attendance\Models\PieceworkEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 §1.7: workforce analytics for managers — attendance and absence, planned against actual
 * hours, overtime spend, output and cost per unit, comparable productivity, certification gaps and
 * the labour the coming fortnight needs.
 *
 * Everything reads approved records only (approved timesheets, approved piecework), so the figures
 * reconcile to what payroll was given. Every query runs through the farm-owned models, so a manager
 * sees only the farms they may see.
 *
 * Productivity is a prompt for a conversation, never a verdict: it compares a worker only with
 * others doing the same activity, in the same unit, on the same field and day, and needs at least
 * three of them before it says anything.
 */
class WorkforceAnalytics
{
    /** Below this many people doing the same work on the same day, a comparison says nothing. */
    public const MIN_PEERS = 3;

    public function __construct(private readonly HrSources $hr) {}

    public function report(?Farm $farm, Carbon $from, Carbon $to): array
    {
        return [
            'totals' => $this->totals($farm, $from, $to),
            'weeks' => $this->weeks($farm, $from, $to),
            'absence' => $this->absenceByWorker($farm, $from, $to),
            'output' => $this->output($farm, $from, $to),
            'productivity' => $this->productivity($farm, $from, $to),
            'crops' => $this->costByCrop($farm, $from, $to),
            'budget' => $this->budget($farm, $from, $to),
            'certification_gaps' => $this->certificationGaps($farm, today(), today()->addDays(30)),
            'demand' => $this->demand($farm, today(), today()->addDays(13)),
        ];
    }

    /** Headline figures for the period. */
    public function totals(?Farm $farm, Carbon $from, Carbon $to): array
    {
        $sheets = $this->sheets($farm, $from, $to)->get();
        $pieces = $this->pieces($farm, $from, $to)->get();
        $assignments = $this->pastAssignments($farm, $from, $to);
        $absent = $assignments->whereNull('timesheet_id')->count();
        $workers = FarmWorker::query()->when($farm, fn ($q) => $q->where('farm_id', $farm->id))->get(['id', 'category', 'hourly_cost_rate', 'labour_rule_set_id', 'company_id']);
        $seasonal = $workers->whereIn('category', ['seasonal', 'casual'])->pluck('id')->all();
        $timeCost = (float) $sheets->sum('cost_amount');
        $pieceCost = (float) $pieces->sum('amount');

        return [
            'planned_hours' => round($assignments->sum('planned_minutes') / 60, 1),
            'actual_hours' => round($sheets->sum('worked_minutes') / 60, 1),
            'overtime_hours' => round($sheets->sum('overtime_minutes') / 60, 1),
            'overtime_cost' => round($sheets->sum(fn ($s) => $this->overtimeCost($s, $workers)), 2),
            'labour_cost' => round($timeCost + $pieceCost, 2),
            'piecework_cost' => round($pieceCost, 2),
            'seasonal_cost' => round((float) $sheets->whereIn('worker_id', $seasonal)->sum('cost_amount') + (float) $pieces->whereIn('worker_id', $seasonal)->sum('amount'), 2),
            'shifts_planned' => $assignments->count(),
            'shifts_missed' => $absent,
            'absence_rate' => $assignments->count() ? round($absent / $assignments->count() * 100, 1) : null,
            'late' => $sheets->filter(fn ($s) => in_array('late', $s->flags ?? [], true))->count(),
        ];
    }

    /** Week by week: planned against worked hours, overtime and absence. */
    public function weeks(?Farm $farm, Carbon $from, Carbon $to): array
    {
        $sheets = $this->sheets($farm, $from, $to)->get()->groupBy(fn ($s) => $s->work_date->copy()->startOfWeek()->toDateString());
        $assignments = $this->pastAssignments($farm, $from, $to)->groupBy(fn ($a) => \App\Modules\Agri\Support\FarmTime::local(Carbon::parse($a->starts_at, 'UTC'), $farm)->startOfWeek()->toDateString());
        $out = [];
        for ($w = $from->copy()->startOfWeek(); $w->lte($to); $w->addWeek()) {
            $k = $w->toDateString();
            $s = $sheets->get($k, collect());
            $a = $assignments->get($k, collect());
            $out[] = ['week' => $k, 'planned_hours' => round($a->sum('planned_minutes') / 60, 1), 'actual_hours' => round($s->sum('worked_minutes') / 60, 1),
                'overtime_hours' => round($s->sum('overtime_minutes') / 60, 1), 'shifts' => $a->count(), 'missed' => $a->whereNull('timesheet_id')->count(),
                'absence_rate' => $a->count() ? round($a->whereNull('timesheet_id')->count() / $a->count() * 100, 1) : null];
        }

        return $out;
    }

    /** Who missed rostered shifts, most first. A count to look into, not a judgement. */
    public function absenceByWorker(?Farm $farm, Carbon $from, Carbon $to): array
    {
        $a = $this->pastAssignments($farm, $from, $to);
        $names = $this->names($a->pluck('worker_id'));

        return $a->groupBy('worker_id')->map(fn ($rows, $id) => ['worker' => $names[$id] ?? "#{$id}", 'rostered' => $rows->count(),
            'missed' => $rows->whereNull('timesheet_id')->count(), 'rate' => round($rows->whereNull('timesheet_id')->count() / $rows->count() * 100, 1)])
            ->filter(fn ($r) => $r['missed'] > 0)->sortByDesc('missed')->values()->take(15)->all();
    }

    /**
     * Approved output per worker and per crew, against the rate's daily target, with what was
     * measured but not approved (rejected or reworked) kept visible.
     */
    public function output(?Farm $farm, Carbon $from, Carbon $to): array
    {
        $entries = $this->pieces($farm, $from, $to)->get();
        $targets = PieceRate::query()->whereIn('id', $entries->pluck('piece_rate_id')->filter()->unique())->pluck('daily_target', 'id');
        $names = $this->names($entries->pluck('worker_id')->filter());
        $crews = DB::table('attendance_crews')->whereIn('id', $entries->pluck('crew_id')->filter()->unique())->pluck('name', 'id');

        return $entries->groupBy(fn ($e) => ($e->worker_id ? 'w'.$e->worker_id : 'c'.$e->crew_id).'|'.$e->activity.'|'.$e->unit)
            ->map(function ($rows) use ($targets, $names, $crews) {
                $e = $rows->first();
                $days = $rows->pluck('work_date')->map(fn ($d) => $d->toDateString())->unique()->count();
                $approved = (float) $rows->sum('approved_quantity');
                $target = $e->piece_rate_id ? (float) ($targets[$e->piece_rate_id] ?? 0) : 0;

                return ['who' => $e->worker_id ? ($names[$e->worker_id] ?? "#{$e->worker_id}") : ('Crew '.($crews[$e->crew_id] ?? $e->crew_id)),
                    'team' => (bool) $e->crew_id, 'activity' => $e->activity, 'unit' => $e->unit, 'days' => $days,
                    'measured' => round((float) $rows->sum('measured_quantity'), 2), 'approved' => round($approved, 2),
                    'not_paid' => round((float) $rows->sum('rejected_quantity') + (float) $rows->sum('rework_quantity'), 2),
                    'per_day' => $days ? round($approved / $days, 1) : null, 'target_per_day' => $target ?: null,
                    'against_target' => $target && $days ? round($approved / $days / $target * 100) : null,
                    'earnings' => round((float) $rows->sum('amount'), 2)];
            })->sortByDesc('approved')->values()->all();
    }

    /**
     * Output relative to people doing the same work in the same conditions: same activity and unit,
     * same field, same day. 100 = the middle of that group. Groups smaller than MIN_PEERS are left
     * out, and the screen says these figures are for review by a person, never a decision on their own.
     */
    public function productivity(?Farm $farm, Carbon $from, Carbon $to): array
    {
        $entries = $this->pieces($farm, $from, $to)->whereNotNull('worker_id')->get();
        $index = [];
        foreach ($entries->groupBy(fn ($e) => $e->work_date->toDateString().'|'.$e->activity.'|'.$e->unit.'|'.($e->field_id ?? 0)) as $group) {
            if ($group->pluck('worker_id')->unique()->count() < self::MIN_PEERS) {
                continue;
            }
            $perWorker = $group->groupBy('worker_id')->map(fn ($r) => (float) $r->sum('approved_quantity'));
            $median = $this->median($perWorker->values()->all());
            if ($median <= 0) {
                continue;
            }
            foreach ($perWorker as $worker => $qty) {
                $index[$worker][] = $qty / $median * 100;
            }
        }
        $names = $this->names(collect(array_keys($index)));

        return collect($index)->map(fn ($v, $w) => ['worker' => $names[$w] ?? "#{$w}", 'comparisons' => count($v),
            'index' => round(array_sum($v) / count($v)), 'low' => round(min($v)), 'high' => round(max($v))])
            ->sortByDesc('index')->values()->all();
    }

    /** Labour cost per crop: approved time and piecework booked to it, per hectare and per unit produced. */
    public function costByCrop(?Farm $farm, Carbon $from, Carbon $to): array
    {
        $time = $this->sheets($farm, $from, $to)->whereNotNull('crop_plan_id')->get()->groupBy('crop_plan_id');
        $pieces = $this->pieces($farm, $from, $to)->whereNotNull('crop_plan_id')->get()->groupBy('crop_plan_id');
        $ids = $time->keys()->merge($pieces->keys())->unique();
        $plans = CropPlan::query()->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(function ($id) use ($time, $pieces, $plans) {
            $p = $plans[$id] ?? null;
            $t = $time->get($id, collect());
            $pc = $pieces->get($id, collect());
            $cost = (float) $t->sum('cost_amount') + (float) $pc->sum('amount');
            $units = $pc->groupBy('unit')->map(fn ($r) => (float) $r->sum('approved_quantity'));
            $mainUnit = $units->sortDesc()->keys()->first();

            return ['crop' => $p?->code ?? "#{$id}", 'hours' => round($t->sum('worked_minutes') / 60, 1), 'labour_cost' => round($cost, 2),
                'area' => $p?->planned_area ? (float) $p->planned_area : null,
                'per_ha' => $p?->planned_area > 0 ? round($cost / (float) $p->planned_area, 2) : null,
                'unit' => $mainUnit, 'units' => $mainUnit ? round($units[$mainUnit], 2) : null,
                'per_unit' => $mainUnit && $units[$mainUnit] > 0 ? round($cost / $units[$mainUnit], 2) : null];
        })->sortByDesc('labour_cost')->values()->all();
    }

    /** Labour budget lines overlapping the period, pro rata by days, against approved labour cost. */
    public function budget(?Farm $farm, Carbon $from, Carbon $to): array
    {
        $lines = \App\Modules\Agri\Models\FarmBudgetLine::query()->where('category', 'labour')->where('status', 'approved')
            ->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from)->get();
        $budget = $lines->sum(function ($l) use ($from, $to) {
            $days = max(1, $l->period_start->diffInDays($l->period_end) + 1);
            $start = $l->period_start->max($from);
            $end = $l->period_end->min($to);

            return (float) $l->amount * max(0, $start->diffInDays($end) + 1) / $days;
        });
        $actual = (float) $this->sheets($farm, $from, $to)->sum('cost_amount') + (float) $this->pieces($farm, $from, $to)->sum('amount');

        return ['budget' => $lines->isEmpty() ? null : round($budget, 2), 'actual' => round($actual, 2),
            'variance' => $lines->isEmpty() ? null : round($budget - $actual, 2), 'lines' => $lines->count()];
    }

    /**
     * People rostered (or on a job) in the coming weeks for work needing a competency they do not
     * hold, or that runs out before the work.
     */
    public function certificationGaps(?Farm $farm, Carbon $from, Carbon $to): array
    {
        $gaps = [];
        $rows = FarmShiftAssignment::query()->where('attendance_shift_assignments.status', 'assigned')
            ->join('attendance_rostered_shifts as s', 's.id', '=', 'attendance_shift_assignments.shift_id')->where('s.status', '!=', FarmShift::CANCELLED)
            ->whereNotNull('s.required_certifications')->whereBetween('s.starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($farm, fn ($q) => $q->where('s.farm_id', $farm->id))
            ->get(['attendance_shift_assignments.worker_id', 's.farm_id', 's.title', 's.starts_at', 's.required_certifications'])
            ->map(fn ($r) => ['worker_id' => $r->worker_id, 'what' => $r->title, 'needs' => json_decode($r->required_certifications, true) ?: [],
                // Read from the join as stored (UTC), shown on the farm's clock.
                'when' => \App\Modules\Agri\Support\FarmTime::local(Carbon::parse($r->starts_at, 'UTC'), (int) $r->farm_id)]);
        $jobs = FarmWorkOrder::query()->whereIn('status', FarmWorkOrder::OPEN)->whereNotNull('required_certifications')
            ->whereBetween('planned_start', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->with('workers')->get();
        foreach ($jobs as $j) {
            foreach ($j->workers as $w) {
                $rows->push(['worker_id' => $w->worker_id, 'what' => "{$j->reference} {$j->title}", 'when' => $j->planned_start, 'needs' => $j->required_certifications ?? []]);
            }
        }
        $workers = FarmWorker::query()->whereIn('id', $rows->pluck('worker_id')->unique())->with('person')->get()->keyBy('id');
        foreach ($rows as $r) {
            $w = $workers[$r['worker_id']] ?? null;
            foreach ($w ? $r['needs'] : [] as $code) {
                $held = $this->hr->holds((int) $w->hr_person_id, $code, $r['when']);
                if (! $held['ok']) {
                    $gaps[] = ['worker' => $w->displayName(), 'competency' => $code, 'for' => $r['what'], 'on' => $r['when']->format('Y-m-d H:i'), 'reason' => $held['reason']];
                }
            }
        }

        return collect($gaps)->unique(fn ($g) => $g['worker'].$g['competency'].$g['for'])->sortBy('on')->values()->all();
    }

    /**
     * The coming days, one row each: hours rostered, staff short on shifts, and the work planned —
     * jobs with their estimated hours, field activities and harvests due — so a manager sees a
     * shortage before it happens.
     */
    public function demand(?Farm $farm, Carbon $from, Carbon $to): array
    {
        // Days are the farm's days: midnight on the farm, not in UTC.
        $tz = \App\Modules\Agri\Support\FarmTime::zone($farm);
        $from = Carbon::parse($from->toDateString(), $tz)->startOfDay();
        $to = Carbon::parse($to->toDateString(), $tz)->endOfDay();
        $shifts = FarmShift::query()->where('status', '!=', FarmShift::CANCELLED)->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->whereBetween('starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->withCount(['assignments as staffed' => fn ($q) => $q->where('status', 'assigned')])->get();
        $jobs = FarmWorkOrder::query()->whereIn('status', FarmWorkOrder::OPEN)->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->where('planned_start', '<=', $to->copy()->endOfDay())->where('planned_end', '>=', $from->copy()->startOfDay())->get();
        $activities = FieldActivity::query()->where('status', 'planned')->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->whereBetween('planned_on', [$from->toDateString(), $to->toDateString()])->get(['planned_on', 'activity_type']);
        $harvests = CropPlan::query()->whereIn('status', CropPlan::LIVE)->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->whereBetween('planned_harvest_on', [$from->toDateString(), $to->toDateString()])->get(['code', 'planned_harvest_on']);

        $days = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $k = $d->toDateString();
            $today = $shifts->filter(fn ($s) => $s->starts_at->toDateString() === $k);
            $rostered = $today->sum(fn ($s) => $s->staffed * max(0, $s->starts_at->diffInMinutes($s->ends_at) - ($s->paid_break ? 0 : $s->break_minutes)));
            // A job's estimated hours are spread evenly over the days it runs.
            $jobHours = $jobs->sum(function ($j) use ($d) {
                $start = $j->planned_start->copy()->startOfDay();
                $end = $j->planned_end->copy()->startOfDay();
                if ($d->lt($start) || $d->gt($end) || ! $j->estimated_hours) {
                    return 0;
                }

                return (float) $j->estimated_hours / ($start->diffInDays($end) + 1);
            });
            $days[] = ['date' => $k, 'rostered_hours' => round($rostered / 60, 1), 'shifts' => $today->count(),
                'short' => $today->sum(fn ($s) => max(0, $s->min_staff - $s->staffed)),
                'job_hours' => round($jobHours, 1), 'jobs' => $jobs->filter(fn ($j) => $d->between($j->planned_start->copy()->startOfDay(), $j->planned_end->copy()->endOfDay()))->count(),
                'activities' => $activities->filter(fn ($a) => $a->planned_on->toDateString() === $k)->count(),
                'harvests' => $harvests->filter(fn ($h) => $h->planned_harvest_on->toDateString() === $k)->pluck('code')->values()->all()];
            $days[array_key_last($days)]['gap_hours'] = round(max(0, $days[array_key_last($days)]['job_hours'] - $days[array_key_last($days)]['rostered_hours']), 1);
        }

        return $days;
    }

    // ── Sources ───────────────────────────────────────────────────────────────────────────────

    private function sheets(?Farm $farm, Carbon $from, Carbon $to)
    {
        return FarmTimesheet::query()->where('status', FarmTimesheet::APPROVED)->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]);
    }

    private function pieces(?Farm $farm, Carbon $from, Carbon $to)
    {
        return PieceworkEntry::query()->where('status', 'approved')->when($farm, fn ($q) => $q->where('farm_id', $farm->id))
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Published assignments whose shift has ended inside the period, each with its planned minutes
     * and the timesheet it produced, if any (none = missed).
     */
    private function pastAssignments(?Farm $farm, Carbon $from, Carbon $to): Collection
    {
        $end = $to->copy()->endOfDay()->min(now());

        return FarmShiftAssignment::query()->where('attendance_shift_assignments.status', 'assigned')
            ->join('attendance_rostered_shifts as s', 's.id', '=', 'attendance_shift_assignments.shift_id')->where('s.status', FarmShift::PUBLISHED)
            ->whereBetween('s.starts_at', [$from->copy()->startOfDay(), $end])->where('s.ends_at', '<=', now())
            ->when($farm, fn ($q) => $q->where('s.farm_id', $farm->id))
            ->leftJoin('attendance_timesheets as t', fn ($j) => $j->on('t.assignment_id', '=', 'attendance_shift_assignments.id')->whereNotIn('t.status', ['rejected']))
            ->get(['attendance_shift_assignments.id', 'attendance_shift_assignments.worker_id', 's.starts_at', 's.ends_at', 's.break_minutes', 's.paid_break', 't.id as timesheet_id'])
            ->unique('id')
            ->map(function ($a) {
                $a->planned_minutes = max(0, Carbon::parse($a->starts_at)->diffInMinutes(Carbon::parse($a->ends_at)) - ($a->paid_break ? 0 : $a->break_minutes));

                return $a;
            });
    }

    private function overtimeCost(FarmTimesheet $s, Collection $workers): float
    {
        $w = $workers->firstWhere('id', $s->worker_id);
        if (! $w || ! $s->overtime_minutes || ! $w->hourly_cost_rate) {
            return 0;
        }
        $rules = app(Workforce::class)->rulesFor($w);

        return $s->overtime_minutes / 60 * (float) $w->hourly_cost_rate * (float) $rules->ot_multiplier;
    }

    private function names(Collection $ids): array
    {
        return FarmWorker::query()->whereIn('id', $ids->unique()->values())->with('person')->get()
            ->mapWithKeys(fn ($w) => [$w->id => $w->displayName()])->all();
    }

    private function median(array $v): float
    {
        sort($v);
        $n = count($v);

        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }
}
