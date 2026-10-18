<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\OperationRequirement as Req;
use App\Modules\Operations\Manufacturing\Models\OperationTimeLog;
use App\Modules\Operations\Manufacturing\Models\ProductionOrderOperation as Op;
use App\Modules\Operations\Manufacturing\Models\RosterEntry;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\WorkCentre;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Labour and workforce (MFG-016).
 *
 * Qualifications are read from where they are kept -- HR skills, learning certifications, SHEQ
 * competencies -- and an operator missing a mandatory one, or holding an expired one, cannot be
 * given the job or start it. Planned labour is the roster; actual labour is the floor's time logs;
 * the gap between the labour the schedule needs and the labour rostered is the overtime or hiring
 * the plan implies. Pay is payroll's: this offers hours, never writes pay.
 */
class Workforce
{
    /** Requirements that apply to an operation: its routing operation's own, and its work centre's. */
    public function requirementsFor(Op $op): Collection
    {
        return Req::query()->where(fn ($q) => $q->where('routing_operation_id', $op->routing_operation_id)
            ->when($op->work_centre_id, fn ($x) => $x->orWhere('work_centre_id', $op->work_centre_id)))->get();
    }

    /**
     * Whether a person meets each requirement, and if not, why.
     *
     * @return list<array{requirement: string, kind: string, mandatory: bool, met: bool, detail: ?string, expires_on: ?string}>
     */
    public function eligibility(User $user, Collection $requirements): array
    {
        $personId = DB::table('hr_people')->where('user_id', $user->id)->value('id');
        $today = now()->toDateString();

        return $requirements->map(function (Req $r) use ($user, $personId, $today) {
            [$met, $detail, $expires] = match ($r->kind) {
                'SKILL' => (function () use ($r, $user) {
                    $level = DB::table('employee_skills')->where('user_id', $user->id)->where('skill_id', $r->reference_id)->value('current_level');
                    if ($level === null) {
                        return [false, 'not held', null];
                    }

                    return (int) $level >= (int) ($r->min_level ?? 1) ? [true, "level {$level}", null] : [false, "level {$level}, needs {$r->min_level}", null];
                })(),
                'CERTIFICATION' => (function () use ($r, $user, $today) {
                    $c = DB::table('employee_certifications')->where('user_id', $user->id)->where('certification_id', $r->reference_id)
                        ->orderByDesc('expires_at')->first(['status', 'expires_at']);
                    if (! $c) {
                        return [false, 'not held', null];
                    }
                    $expired = $c->status !== 'active' || ($c->expires_at && Carbon::parse($c->expires_at)->toDateString() < $today);

                    return [! $expired, $expired ? 'expired' : null, $c->expires_at ? Carbon::parse($c->expires_at)->toDateString() : null];
                })(),
                'COMPETENCY' => (function () use ($r, $personId, $today) {
                    $c = $personId ? DB::table('sheq_competency_records')->where('hr_person_id', $personId)->where('competency_id', $r->reference_id)
                        ->whereNull('deleted_at')->whereNull('revoked_at')->orderByDesc('expires_at')->first(['status', 'expires_at']) : null;
                    if (! $c) {
                        return [false, 'not held', null];
                    }
                    $expired = $c->status !== 'COMPLIANT' || ($c->expires_at && Carbon::parse($c->expires_at)->toDateString() < $today);

                    return [! $expired, $expired ? 'expired' : null, $c->expires_at ? Carbon::parse($c->expires_at)->toDateString() : null];
                })(),
                default => [false, 'unknown requirement', null],
            };

            return ['requirement' => $this->label($r), 'kind' => $r->kind, 'mandatory' => (bool) $r->is_mandatory, 'met' => $met,
                'detail' => $detail, 'expires_on' => $expires];
        })->values()->all();
    }

    /** Refuse a job to somebody missing, or holding an expired, mandatory qualification. */
    public function assertQualified(?User $user, Op $op): void
    {
        if (! $user) {
            return;
        }
        $requirements = $this->requirementsFor($op);
        if ($requirements->isEmpty()) {
            return;
        }

        $missing = collect($this->eligibility($user, $requirements))->filter(fn ($e) => $e['mandatory'] && ! $e['met']);
        if ($missing->isNotEmpty()) {
            throw new DomainException(sprintf('%s is not qualified for operation %d %s: %s.', $this->name($user), $op->sequence, $op->name,
                $missing->map(fn ($e) => "{$e['requirement']} ({$e['detail']})")->implode('; ')));
        }
    }

    /** People who could do an operation, best qualified first, with what each lacks. */
    public function candidates(Op $op, Collection $people): array
    {
        $requirements = $this->requirementsFor($op);

        return $people->map(function (User $u) use ($requirements) {
            $e = $this->eligibility($u, $requirements);

            return ['id' => $u->id, 'name' => $this->name($u), 'eligible' => collect($e)->every(fn ($x) => ! $x['mandatory'] || $x['met']), 'checks' => $e];
        })->sortByDesc('eligible')->values()->all();
    }

    // ── Planned against needed against actual ────────────────────────────────────────────────

    /** Rostered minutes per work centre per day. */
    public function rostered(Carbon $from, Carbon $to): Collection
    {
        $shifts = Shift::query()->get()->keyBy('id');

        return RosterEntry::query()->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy(fn ($r) => ($r->work_centre_id ?? 0).'|'.$r->work_date->toDateString())
            ->map(fn ($g) => $g->sum(fn ($r) => $this->shiftMinutes($shifts[$r->shift_id] ?? null) + (float) $r->overtime_minutes));
    }

    /**
     * Labour the schedule needs per work centre per day (each scheduled operation's planned labour
     * minutes spread over its scheduled days) against what is rostered: a shortfall is overtime,
     * or people the plan does not have.
     */
    public function capacityGaps(Carbon $from, Carbon $to): array
    {
        $needed = [];
        foreach (Op::query()->whereNotNull('sched_start')->whereNotIn('status', [Op::COMPLETED, Op::SKIPPED])
            ->where('sched_end', '>=', $from->toDateString())->where('sched_start', '<=', $to->toDateString())->get() as $op) {
            $centre = $op->sched_work_centre_id ?? $op->work_centre_id;
            $days = max(1, $op->sched_start->diffInDays($op->sched_end) + 1);
            $perDay = (float) $op->planned_labour_minutes / $days;
            for ($d = $op->sched_start->copy(); $d->lte($op->sched_end); $d->addDay()) {
                if ($d->between($from, $to)) {
                    $needed["{$centre}|{$d->toDateString()}"] = ($needed["{$centre}|{$d->toDateString()}"] ?? 0) + $perDay;
                }
            }
        }

        $rostered = $this->rostered($from, $to);
        $centres = WorkCentre::query()->whereIn('id', collect(array_keys($needed))->merge($rostered->keys())->map(fn ($k) => (int) explode('|', $k)[0])->unique())
            ->get()->keyBy('id');

        $rows = [];
        foreach (collect(array_keys($needed))->merge($rostered->keys())->unique()->sort() as $key) {
            [$centreId, $date] = explode('|', $key);
            $need = round($needed[$key] ?? 0, 1);
            $have = round((float) ($rostered[$key] ?? 0), 1);
            $rows[] = ['work_centre' => $centres[$centreId]?->code ?? '—', 'date' => $date, 'needed' => $need, 'rostered' => $have,
                'gap' => round(max(0, $need - $have), 1), 'spare' => round(max(0, $have - $need), 1)];
        }

        return $rows;
    }

    /**
     * Qualifications that will stop people working: missing or expiring within the warning window,
     * for everybody rostered at a centre that needs them.
     */
    public function trainingNeeds(int $warningDays = 30): array
    {
        $requirements = Req::query()->get();
        $people = RosterEntry::query()->where('work_date', '>=', now()->subDays(30)->toDateString())->with('user.person')->get()
            ->groupBy('work_centre_id')->map(fn ($g) => $g->pluck('user')->filter()->unique('id'));
        $limit = now()->addDays($warningDays)->toDateString();
        $out = [];

        foreach ($people as $centreId => $users) {
            $applicable = $requirements->filter(fn (Req $r) => (int) $r->work_centre_id === (int) $centreId
                || ($r->routing_operation_id && DB::table('mfg_routing_operations')->where('id', $r->routing_operation_id)->value('work_centre_id') == $centreId));
            if ($applicable->isEmpty()) {
                continue;
            }
            foreach ($users as $u) {
                foreach ($this->eligibility($u, $applicable) as $e) {
                    if (! $e['met'] || ($e['expires_on'] && $e['expires_on'] <= $limit)) {
                        $out[] = ['person' => $this->name($u), 'user_id' => $u->id, 'requirement' => $e['requirement'], 'kind' => $e['kind'],
                            'mandatory' => $e['mandatory'], 'status' => $e['met'] ? 'EXPIRING' : ($e['detail'] === 'expired' ? 'EXPIRED' : 'MISSING'),
                            'expires_on' => $e['expires_on'], 'work_centre' => WorkCentre::query()->find($centreId)?->code];
                    }
                }
            }
        }

        return collect($out)->unique(fn ($r) => $r['user_id'].$r['requirement'])->sortBy('status')->values()->all();
    }

    /** Per operator: rostered against logged time, and the operations they worked. */
    public function utilisation(Carbon $from, Carbon $to): array
    {
        $shifts = Shift::query()->get()->keyBy('id');
        $rostered = RosterEntry::query()->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get()
            ->groupBy('user_id')->map(fn ($g) => $g->sum(fn ($r) => $this->shiftMinutes($shifts[$r->shift_id] ?? null) + (float) $r->overtime_minutes));
        $logged = OperationTimeLog::query()->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->whereNotNull('minutes')
            ->get()->groupBy('user_id');
        $ids = $rostered->keys()->merge($logged->keys())->filter()->unique();
        $users = User::query()->with('person')->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => [
            'user_id' => (int) $id, 'name' => isset($users[$id]) ? $this->name($users[$id]) : '—',
            'rostered_minutes' => round((float) ($rostered[$id] ?? 0), 1),
            'logged_minutes' => round((float) ($logged[$id] ?? collect())->sum('minutes'), 1),
            'operations' => ($logged[$id] ?? collect())->pluck('order_operation_id')->unique()->count(),
            'utilisation' => ($rostered[$id] ?? 0) > 0 ? round(($logged[$id] ?? collect())->sum('minutes') / $rostered[$id] * 100, 1) : null,
        ])->sortByDesc('logged_minutes')->values()->all();
    }

    /** Planned against actual labour on the operations completed in a period. */
    public function plannedVsActual(Carbon $from, Carbon $to): array
    {
        $ops = Op::query()->where('status', Op::COMPLETED)->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->with(['order:id,uuid,number', 'workCentre:id,code'])->get();

        return [
            'planned_minutes' => round((float) $ops->sum('planned_labour_minutes'), 1),
            'actual_minutes' => round((float) $ops->sum('actual_labour_minutes'), 1),
            'efficiency' => $ops->sum('actual_labour_minutes') > 0 ? round($ops->sum('planned_labour_minutes') / $ops->sum('actual_labour_minutes') * 100, 1) : null,
            'operations' => $ops->sortByDesc(fn ($o) => (float) $o->actual_labour_minutes - (float) $o->planned_labour_minutes)->take(50)->map(fn ($o) => [
                'order_id' => $o->order?->uuid, 'order' => $o->order?->number, 'operation' => "{$o->sequence} {$o->name}", 'work_centre' => $o->workCentre?->code,
                'planned' => (float) $o->planned_labour_minutes, 'actual' => (float) $o->actual_labour_minutes,
                'variance' => round((float) $o->actual_labour_minutes - (float) $o->planned_labour_minutes, 1)])->values()->all(),
        ];
    }

    /**
     * Hours worked per person per day, for costing and for payroll to read. Manufacturing does not
     * create payroll records; this is an input, nothing more.
     */
    public function hours(Carbon $from, Carbon $to): array
    {
        return OperationTimeLog::query()->whereBetween('started_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->whereNotNull('minutes')
            ->with('user.person')->get()
            ->groupBy(fn ($l) => $l->user_id.'|'.$l->started_at->toDateString())
            ->map(fn ($g) => ['user_id' => $g->first()->user_id, 'name' => $g->first()->user ? $this->name($g->first()->user) : '—',
                'date' => $g->first()->started_at->toDateString(), 'hours' => round($g->sum('minutes') / 60, 2)])
            ->sortBy([['date', 'asc'], ['name', 'asc']])->values()->all();
    }

    // ── Internals ────────────────────────────────────────────────────────────────────────────

    public function label(Req $r): string
    {
        return match ($r->kind) {
            'SKILL' => (DB::table('skills')->where('id', $r->reference_id)->value('name') ?? 'Skill').($r->min_level ? " (level {$r->min_level})" : ''),
            'CERTIFICATION' => DB::table('lms_certifications')->where('id', $r->reference_id)->value('name') ?? 'Certification',
            'COMPETENCY' => DB::table('sheq_competencies')->where('id', $r->reference_id)->value('title') ?? 'Competency',
            default => $r->kind,
        };
    }

    private function shiftMinutes(?Shift $shift): float
    {
        if (! $shift) {
            return 0;
        }
        $start = Carbon::parse($shift->starts_at);
        $end = Carbon::parse($shift->ends_at);
        if ($end->lte($start)) {
            $end->addDay();
        }

        return max(0, $start->diffInMinutes($end) - (int) $shift->break_minutes);
    }

    private function name(User $u): string
    {
        $p = $u->person;

        return $p ? trim("{$p->first_name} {$p->surname}") : (string) $u->email;
    }
}
