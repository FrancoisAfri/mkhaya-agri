<?php

namespace App\Modules\HR\Hr\Attendance\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Model;
use App\Modules\HR\Hr\Attendance\Models\RosteredShift;
use App\Modules\HR\Hr\Attendance\Models\ShiftAssignment;
use App\Modules\HR\Hr\Attendance\Models\ShiftChange;
use App\Modules\HR\Hr\Attendance\Models\ShiftSwap;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use App\Modules\HR\Hr\Attendance\Models\ShiftTemplate;
use App\Modules\HR\Hr\Attendance\Models\Unavailability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shifts and rosters (Phase 2 §1.2).
 *
 * Every assignment is checked against the worker's rules before it is made: overlapping shifts,
 * rest between shifts, approved leave and declared unavailability, required competencies, maximum
 * shift length. Whether a problem blocks the assignment or is accepted as a recorded warning is the
 * labour rule set's policy, not this code's.
 *
 * Once a shift is published, every change to it — times, people, cancellation — first stores the
 * shift as it was, so the original roster and each revision stay available beside the attendance.
 */
class Rostering
{
    public function __construct(private readonly Workforce $workforce, private readonly HrSources $hr) {}

    public function createShift(?Model $site, array $data, User $actor): RosteredShift
    {
        Sites::assertOperational($site);
        [$start, $end] = $this->times($data);

        return RosteredShift::create([
            'company_id' => $site?->company_id ?? $actor->company_id, 'farm_id' => $site?->id, 'template_id' => $data['template_id'] ?? null,
            'field_id' => $data['field_id'] ?? null, 'location_id' => $data['location_id'] ?? null, 'work_order_id' => $data['work_order_id'] ?? null,
            'title' => $data['title'], 'starts_at' => $start, 'ends_at' => $end, 'break_minutes' => $data['break_minutes'] ?? 30,
            'paid_break' => (bool) ($data['paid_break'] ?? false), 'required_certifications' => $data['required_certifications'] ?? null,
            'min_staff' => $data['min_staff'] ?? 1, 'status' => RosteredShift::DRAFT, 'created_by' => $actor->id, 'batch' => $data['batch'] ?? null,
        ]);
    }

    public function fromTemplate(?Model $site, ShiftTemplate $tpl, Carbon $date, User $actor, array $extra = []): RosteredShift
    {
        // "06:00" on the template is the farm's clock, whatever zone the date arrived in.
        $start = Sites::at($date->toDateString(), $tpl->starts, $site);
        $end = Sites::at($date->toDateString(), $tpl->ends, $site);
        if ($end->lte($start)) {
            $end->addDay(); // a night shift ends the next morning
        }

        return $this->createShift($site, $extra + [
            'template_id' => $tpl->id, 'title' => $tpl->name, 'starts_at' => $start, 'ends_at' => $end,
            'break_minutes' => $tpl->break_minutes, 'paid_break' => $tpl->paid_break,
            'required_certifications' => $tpl->required_certifications, 'min_staff' => $tpl->min_staff,
        ], $actor);
    }

    /** @return list<string> problems found for putting this worker on this shift */
    public function check(RosteredShift $shift, Worker $worker, ?int $ignoreAssignmentId = null): array
    {
        $rules = $this->workforce->rulesFor($worker);
        $problems = [];
        $start = $shift->starts_at;
        $end = $shift->ends_at;

        if (! $worker->isActive()) {
            $problems[] = ['block', "{$worker->displayName()} is {$worker->status}."];
        }

        $mine = ShiftAssignment::withoutGlobalScopes()->where('attendance_shift_assignments.worker_id', $worker->id)
            ->where('attendance_shift_assignments.status', 'assigned')
            ->when($ignoreAssignmentId, fn ($q) => $q->where('attendance_shift_assignments.id', '!=', $ignoreAssignmentId))
            ->where('attendance_shift_assignments.shift_id', '!=', $shift->id)
            ->join('attendance_rostered_shifts as s', 's.id', '=', 'attendance_shift_assignments.shift_id')
            ->where('s.status', '!=', RosteredShift::CANCELLED)
            ->get(['s.title', 's.starts_at', 's.ends_at']);
        foreach ($mine as $o) {
            $os = Sites::local(Carbon::parse($o->starts_at, 'UTC'), $shift);
            $oe = Sites::local(Carbon::parse($o->ends_at, 'UTC'), $shift);
            if ($os->lt($end) && $oe->gt($start)) {
                $problems[] = [$rules->overlap_policy, "Overlaps {$o->title} ({$os->format('D j M H:i')}–{$oe->format('H:i')})."];
            } else {
                $gap = $oe->lte($start) ? $oe->diffInMinutes($start) : $end->diffInMinutes($os);
                if ($gap < $rules->min_rest_minutes) {
                    $problems[] = ['warn', 'Only '.round($gap / 60, 1).' h rest next to '.$o->title.' (rule: '.round($rules->min_rest_minutes / 60, 1).' h).'];
                }
            }
        }

        if ($this->hr->onLeave($worker->hr_person_id, $start, $end)) {
            $problems[] = [$rules->overlap_policy, 'On approved leave.'];
        }
        if (Unavailability::withoutGlobalScopes()->where('worker_id', $worker->id)->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists()) {
            $problems[] = ['warn', 'Marked unavailable for that time.'];
        }

        foreach ($shift->required_certifications ?? [] as $code) {
            $h = $this->hr->holds($worker->hr_person_id, $code, $end);
            if (! $h['ok']) {
                $problems[] = [$rules->certification_policy, $h['reason']];
            }
        }

        $length = $start->diffInMinutes($end);
        if ($length > $rules->max_shift_minutes) {
            $problems[] = ['warn', 'Longer than the '.round($rules->max_shift_minutes / 60, 1).' h maximum shift.'];
        } elseif ($length > $rules->fatigue_warn_minutes) {
            $problems[] = ['warn', 'Long shift — fatigue risk ('.round($length / 60, 1).' h).'];
        }

        return array_map(fn ($p) => ['level' => $p[0] === 'block' ? 'block' : 'warn', 'message' => $p[1]], $problems);
    }

    /**
     * Put a worker on a shift. Blocking problems refuse; warnings are accepted only when the caller
     * says so, and are kept on the assignment.
     */
    public function assign(RosteredShift $shift, Worker $worker, User $actor, bool $acceptWarnings = false, ?string $reason = null): ShiftAssignment
    {
        if ($shift->status === RosteredShift::CANCELLED) {
            throw ValidationException::withMessages(['shift' => 'This shift is cancelled.']);
        }
        if (ShiftAssignment::withoutGlobalScopes()->where('shift_id', $shift->id)->where('worker_id', $worker->id)->where('status', 'assigned')->exists()) {
            throw ValidationException::withMessages(['worker_id' => "{$worker->displayName()} is already on this shift."]);
        }
        $problems = $this->check($shift, $worker);
        $blocks = array_filter($problems, fn ($p) => $p['level'] === 'block');
        $warns = array_filter($problems, fn ($p) => $p['level'] === 'warn');
        if ($blocks) {
            throw ValidationException::withMessages(['worker_id' => "{$worker->displayName()}: ".implode(' ', array_column($blocks, 'message'))]);
        }
        if ($warns && ! $acceptWarnings) {
            throw ValidationException::withMessages(['warnings' => "{$worker->displayName()}: ".implode(' ', array_column($warns, 'message')).' Confirm to assign anyway.']);
        }

        return DB::transaction(function () use ($shift, $worker, $actor, $warns, $reason) {
            $this->remember($shift, 'assigned', $reason, $actor);
            $a = ShiftAssignment::withoutGlobalScopes()->firstOrNew(['shift_id' => $shift->id, 'worker_id' => $worker->id]);
            $a->fill(['company_id' => $shift->company_id, 'farm_id' => $shift->farm_id, 'status' => 'assigned', 'assigned_by' => $actor->id,
                'warnings' => $warns ? mb_strimwidth(implode(' ', array_column($warns, 'message')), 0, 500) : null])->save();
            if ($shift->status === RosteredShift::PUBLISHED) {
                app(\App\Modules\Agri\Services\AgriNotifier::class)->shiftChanged($worker, $shift, 'assigned');
            }

            return $a;
        });
    }

    public function unassign(ShiftAssignment $a, string $reason, User $actor): void
    {
        $shift = $a->shift;
        DB::transaction(function () use ($a, $shift, $reason, $actor) {
            $this->remember($shift, 'unassigned', $reason, $actor);
            $a->update(['status' => 'cancelled']);
        });
        if ($shift->status === RosteredShift::PUBLISHED && $a->worker) {
            app(\App\Modules\Agri\Services\AgriNotifier::class)->shiftChanged($a->worker, $shift, 'removed');
        }
    }

    public function update(RosteredShift $shift, array $data, ?string $reason, User $actor): RosteredShift
    {
        if ($shift->status === RosteredShift::CANCELLED) {
            throw ValidationException::withMessages(['shift' => 'A cancelled shift cannot be changed.']);
        }
        if ($shift->status === RosteredShift::PUBLISHED && blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Say why a published shift is changing; the workers are told.']);
        }
        [$start, $end] = $this->times($data + ['starts_at' => $shift->starts_at, 'ends_at' => $shift->ends_at]);

        DB::transaction(function () use ($shift, $data, $start, $end, $reason, $actor) {
            $this->remember($shift, 'edited', $reason, $actor);
            $shift->update(array_intersect_key($data, array_flip(['title', 'break_minutes', 'paid_break', 'required_certifications', 'min_staff', 'field_id', 'location_id']))
                + ['starts_at' => $start, 'ends_at' => $end]);
        });
        if ($shift->status === RosteredShift::PUBLISHED) {
            foreach ($shift->activeAssignments()->with('worker')->get() as $a) {
                app(\App\Modules\Agri\Services\AgriNotifier::class)->shiftChanged($a->worker, $shift, 'changed', $reason);
            }
        }

        return $shift;
    }

    public function cancel(RosteredShift $shift, string $reason, User $actor): RosteredShift
    {
        $wasPublished = $shift->status === RosteredShift::PUBLISHED;
        DB::transaction(function () use ($shift, $reason, $actor) {
            $this->remember($shift, 'cancelled', $reason, $actor);
            $shift->update(['status' => RosteredShift::CANCELLED]);
        });
        if ($wasPublished) {
            foreach ($shift->activeAssignments()->with('worker')->get() as $a) {
                app(\App\Modules\Agri\Services\AgriNotifier::class)->shiftChanged($a->worker, $shift, 'cancelled', $reason);
            }
        }

        return $shift;
    }

    /** Publish shifts: from now on every change is versioned and the workers are told. */
    public function publish(iterable $shifts, User $actor): int
    {
        $n = 0;
        foreach ($shifts as $shift) {
            if ($shift->status !== RosteredShift::DRAFT) {
                continue;
            }
            $shift->update(['status' => RosteredShift::PUBLISHED]);
            foreach ($shift->activeAssignments()->with('worker')->get() as $a) {
                app(\App\Modules\Agri\Services\AgriNotifier::class)->shiftChanged($a->worker, $shift, 'published');
            }
            $n++;
        }

        return $n;
    }

    /**
     * A rotating roster: a cycle of shift templates (or rest days) repeated over the weeks, for each
     * worker from their own point in the cycle so a team covers every day. Shifts with the same
     * template on the same day are shared; each person is checked as they are assigned, and anybody
     * who cannot be placed is reported rather than silently skipped.
     *
     * @param  list<string>  $cycle  template codes, 'OFF' for a rest day
     * @param  array<int, int>  $workers  worker id => offset into the cycle
     * @return array{shifts: int, assignments: int, skipped: list<string>, batch: string}
     */
    public function generateRotating(?Model $site, array $cycle, Carbon $from, int $days, array $workers, User $actor, bool $acceptWarnings = true, array $extra = []): array
    {
        Sites::assertOperational($site);
        if (! $cycle || $days < 1 || $days > 366) {
            throw ValidationException::withMessages(['cycle' => 'Give a cycle and a number of days (up to a year).']);
        }
        $templates = ShiftTemplate::query()->whereIn('code', array_diff($cycle, ['OFF']))->get()->keyBy(fn ($t) => strtoupper($t->code));
        foreach (array_diff($cycle, ['OFF']) as $code) {
            if (! isset($templates[strtoupper($code)])) {
                throw ValidationException::withMessages(['cycle' => "No shift template with code {$code}."]);
            }
        }
        $batch = (string) Str::uuid();

        return DB::transaction(function () use ($site, $cycle, $from, $days, $workers, $actor, $acceptWarnings, $templates, $batch, $extra) {
            $shifts = [];
            $assigned = 0;
            $skipped = [];
            $people = Worker::query()->whereIn('id', array_keys($workers))->get()->keyBy('id');
            for ($d = 0; $d < $days; $d++) {
                $date = $from->copy()->addDays($d);
                foreach ($workers as $workerId => $offset) {
                    $code = strtoupper($cycle[($d + (int) $offset) % count($cycle)]);
                    if ($code === 'OFF' || ! isset($people[$workerId])) {
                        continue;
                    }
                    $key = $date->toDateString().'|'.$code;
                    $shifts[$key] ??= $this->fromTemplate($site, $templates[$code], $date, $actor, $extra + ['batch' => $batch]);
                    try {
                        $this->assign($shifts[$key], $people[$workerId], $actor, $acceptWarnings);
                        $assigned++;
                    } catch (ValidationException $e) {
                        $skipped[] = $date->toDateString().' '.$code.': '.collect($e->errors())->flatten()->first();
                    }
                }
            }

            return ['shifts' => count($shifts), 'assignments' => $assigned, 'skipped' => $skipped, 'batch' => $batch];
        });
    }

    /** Shifts short of people, or with nobody holding a competency the shift requires. */
    public function gaps(iterable $shifts): array
    {
        $out = [];
        foreach ($shifts as $shift) {
            if ($shift->status === RosteredShift::CANCELLED) {
                continue;
            }
            $people = $shift->activeAssignments()->with('worker')->get()->pluck('worker')->filter();
            if ($people->count() < $shift->min_staff) {
                $out[] = ['shift_id' => $shift->uuid, 'problem' => "{$people->count()} of {$shift->min_staff} people"];
            }
            foreach ($shift->required_certifications ?? [] as $code) {
                if (! $people->contains(fn ($w) => $this->hr->holds($w->hr_person_id, $code, $shift->ends_at)['ok'])) {
                    $out[] = ['shift_id' => $shift->uuid, 'problem' => "Nobody holding {$code}"];
                }
            }
        }

        return $out;
    }

    public function requestSwap(ShiftAssignment $a, Worker $to, string $reason, User $actor): ShiftSwap
    {
        if ($a->status !== 'assigned') {
            throw ValidationException::withMessages(['assignment' => 'Only a current assignment can be swapped.']);
        }

        return ShiftSwap::create(['company_id' => $a->company_id, 'farm_id' => $a->farm_id, 'assignment_id' => $a->id,
            'to_worker_id' => $to->id, 'reason' => $reason, 'status' => 'requested', 'requested_by' => $actor->id]);
    }

    /** A supervisor approves a swap: the new person passes the same checks as any assignment. */
    public function reviewSwap(ShiftSwap $swap, bool $approve, User $actor, bool $acceptWarnings = false): ShiftSwap
    {
        if ($swap->status !== 'requested') {
            throw ValidationException::withMessages(['swap' => 'This swap has been decided.']);
        }
        if (! $approve) {
            $swap->update(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

            return $swap;
        }
        DB::transaction(function () use ($swap, $actor, $acceptWarnings) {
            $a = ShiftAssignment::query()->findOrFail($swap->assignment_id);
            $to = Worker::query()->findOrFail($swap->to_worker_id);
            $shift = $a->shift;
            $this->remember($shift, 'swapped', $swap->reason, $actor);
            $a->update(['status' => 'swapped']);
            $this->assign($shift, $to, $actor, $acceptWarnings, 'Swap: '.$swap->reason);
            $swap->update(['status' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        });

        return $swap;
    }

    /** Before a published shift changes, keep it as it was (LAB-010). */
    private function remember(RosteredShift $shift, string $change, ?string $reason, User $actor): void
    {
        if ($shift->status !== RosteredShift::PUBLISHED) {
            return;
        }
        // Times kept in farm time with their offset ("2026-10-21T06:00:00+02:00"), so the history reads
        // as the roster did — not as UTC, which JSON would otherwise make of them.
        $before = $shift->only(['title', 'break_minutes', 'min_staff', 'status', 'field_id']) + [
            'starts_at' => $shift->starts_at->toIso8601String(), 'ends_at' => $shift->ends_at->toIso8601String(),
            'workers' => $shift->activeAssignments()->pluck('worker_id')->all(),
        ];
        $shift->increment('revision');
        ShiftChange::create(['company_id' => $shift->company_id, 'farm_id' => $shift->farm_id, 'shift_id' => $shift->id,
            'revision' => $shift->revision, 'change' => $change, 'before' => $before, 'reason' => $reason, 'changed_by' => $actor->id]);
    }

    private function times(array $data): array
    {
        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);
        if ($end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => 'A shift must end after it starts (for a night shift give the next morning\'s date).']);
        }

        return [$start, $end];
    }
}
