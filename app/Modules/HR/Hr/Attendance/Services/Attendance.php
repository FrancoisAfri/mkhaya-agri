<?php

namespace App\Modules\HR\Hr\Attendance\Services;

use App\Core\User\Models\User;
use App\Modules\HR\Hr\Attendance\Support\Sites;
use Illuminate\Database\Eloquent\Model;
use App\Modules\HR\Hr\Attendance\Models\Correction;
use App\Modules\HR\Hr\Attendance\Models\ClockEvent;
use App\Modules\HR\Hr\Attendance\Models\OvertimeRequest;
use App\Modules\HR\Hr\Attendance\Models\ShiftAssignment;
use App\Modules\HR\Hr\Attendance\Models\Timesheet;
use App\Modules\HR\Hr\Attendance\Models\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clock-in, clock-out and timesheets (Phase 2 §1.3–1.4).
 *
 *  - Every event is kept as it happened: who, when, how (phone, kiosk, QR, biometric, supervisor),
 *    where when the worker consented to location, and whether it was captured offline. An event is
 *    never edited; a correction is a new event that supersedes it, approved by somebody else, and
 *    the original stays as evidence (LAB-006).
 *  - Events carry the phone's id, so a queue sent twice records them once (LAB-005).
 *  - A timesheet pairs a clock-in with the next clock-out and is measured against the shift:
 *    lateness, early departure, unpaid breaks, overtime, Sunday, public-holiday and night minutes,
 *    and — for a shift across midnight — the minutes on each calendar date (LAB-003, LAB-004).
 *  - Thresholds and multipliers come from the worker's labour rule set; nothing is assumed.
 */
class Attendance
{
    /** A clock event within this long of the same kind of event is a duplicate, not a new one. */
    private const DUPLICATE_MINUTES = 3;

    /** How far a GPS reading may be from the farm before it needs a look (metres). */
    private const GPS_TOLERANCE_M = 5000;

    public function __construct(private readonly Workforce $workforce, private readonly HrSources $hr) {}

    /**
     * @param  array{latitude?: float, longitude?: float, device_ref?: string, client_id?: string, offline?: bool, supervisor_confirmed?: bool}  $meta
     */
    /** For a badge or a reader that only says "this person": out when they have an open timesheet from the last 20 hours, otherwise in. */
    public function nextKind(Worker $worker, Carbon $at): string
    {
        return Timesheet::withoutGlobalScopes()->where('worker_id', $worker->id)->whereNotNull('clock_in_at')->whereNull('clock_out_at')
            ->where('clock_in_at', '<=', $at)->where('clock_in_at', '>=', $at->copy()->subHours(20))->exists() ? 'out' : 'in';
    }

    public function clock(Worker $worker, string $kind, Carbon $at, string $method, array $meta, ?User $actor): ClockEvent
    {
        if (! in_array($kind, ['in', 'out'], true) || ! in_array($method, ClockEvent::METHODS, true)) {
            throw ValidationException::withMessages(['kind' => 'Unknown clock event.']);
        }
        if (! empty($meta['client_id'])) {
            $seen = ClockEvent::withoutGlobalScopes()->where('company_id', $worker->company_id)->where('client_id', $meta['client_id'])->first();
            if ($seen) {
                return $seen; // the same tap, sent again
            }
        }
        if ($method === 'biometric' && ! $worker->biometric_allowed) {
            throw ValidationException::withMessages(['method' => "{$worker->displayName()} has not consented to biometric clocking; use the badge instead."]);
        }
        if (! $worker->isActive()) {
            throw ValidationException::withMessages(['worker' => "{$worker->displayName()} is {$worker->status} and cannot clock."]);
        }
        if ($at->isAfter(now()->addMinutes(10))) {
            throw ValidationException::withMessages(['occurred_at' => 'A clock time cannot be in the future.']);
        }

        return DB::transaction(function () use ($worker, $kind, $at, $method, $meta, $actor) {
            [$lat, $lng, $gpsOk, $verification, $note] = $this->verify($worker, $meta);

            $last = ClockEvent::withoutGlobalScopes()->where('worker_id', $worker->id)->whereNull('superseded_by_id')
                ->where('verification', '!=', 'suspicious')->orderByDesc('occurred_at')->first();
            $duplicate = $last && $last->kind === $kind && abs($last->occurred_at->diffInMinutes($at)) <= self::DUPLICATE_MINUTES;
            if ($duplicate) {
                $verification = 'suspicious';
                $note = trim(($note ?? '').' Duplicate clock-'.$kind.' '.abs($last->occurred_at->diffInMinutes($at)).' min after the last one.');
            }

            $event = ClockEvent::create([
                'company_id' => $worker->company_id, 'farm_id' => $meta['farm_id'] ?? $worker->farm_id, 'worker_id' => $worker->id,
                'kind' => $kind, 'occurred_at' => $at, 'method' => $method, 'device_ref' => $meta['device_ref'] ?? null,
                'latitude' => $lat, 'longitude' => $lng, 'gps_verified' => $gpsOk, 'verification' => $verification,
                'verification_note' => $note ? mb_strimwidth($note, 0, 300) : null, 'client_id' => $meta['client_id'] ?? null,
                'captured_offline' => (bool) ($meta['offline'] ?? false), 'recorded_by' => $actor?->id,
                'supervisor_confirmed' => (bool) ($meta['supervisor_confirmed'] ?? in_array($method, ['kiosk', 'supervisor'], true)),
            ]);

            // A duplicate tap is kept as evidence but not counted; time recorded away from the farm
            // is counted and flagged for a look.
            if (! $duplicate) {
                $this->place($event, $worker);
            }

            return $event;
        });
    }

    /**
     * A clock-in or clock-out T&A recorded itself (the self-service clock, a GPS or biometric capture,
     * an ERS device) joins the engine: the person's profile is found or made, the clocking is marked as
     * theirs, and it opens or closes a timesheet like any other event. Biometric clockings from
     * devices T&A already ran are kept, with a note when no consent is on record.
     */
    public function adopt(int $clockingId): ?Timesheet
    {
        $event = ClockEvent::withoutGlobalScopes()->find($clockingId);
        if (! $event || ! in_array($event->getAttributes()['type'] ?? null, ['clock_in', 'clock_out'], true) || $event->worker_id) {
            return null;
        }
        $worker = $this->workforce->profileFor((int) $event->employee_id);
        $note = $event->method === 'biometric' && ! $worker->biometric_allowed ? 'No biometric consent on record for this person.' : null;
        $event->forceFill(['worker_id' => $worker->id, 'farm_id' => $event->farm_id ?? $worker->farm_id, 'uuid' => $event->uuid ?? (string) \Illuminate\Support\Str::uuid(),
            'verification' => $event->verification ?? ($event->is_verified ? 'verified' : 'unverified'),
            'verification_note' => $note ?? $event->verification_note])->saveQuietly();

        return $this->place($event->fresh(), $worker);
    }

    /** Put an event on its timesheet: a clock-in opens one, a clock-out closes the open one. */
    private function place(ClockEvent $event, Worker $worker): Timesheet
    {
        if ($event->kind === 'in') {
            $assignment = $this->assignmentAround($worker, $event->occurred_at);
            $shift = $assignment?->shift;
            $wo = $shift?->work_order_id ? Sites::model('work_order')::withoutGlobalScopes()->find($shift->work_order_id) : null;
            $sheet = Timesheet::create([
                'company_id' => $worker->company_id, 'farm_id' => $shift?->farm_id ?? $event->farm_id, 'worker_id' => $worker->id,
                'assignment_id' => $assignment?->id, 'work_date' => ($shift?->starts_at ?? $event->occurred_at)->toDateString(),
                'scheduled_start' => $shift?->starts_at, 'scheduled_end' => $shift?->ends_at,
                'in_event_id' => $event->id, 'clock_in_at' => $event->occurred_at, 'status' => Timesheet::OPEN,
                'field_id' => $shift?->field_id ?? $wo?->field_id, 'work_order_id' => $wo?->id,
                'crop_plan_id' => $wo?->crop_plan_id, 'livestock_group_id' => $wo?->livestock_group_id,
            ]);

            return $this->measure($sheet);
        }

        $open = Timesheet::withoutGlobalScopes()->where('worker_id', $worker->id)->whereNull('out_event_id')->whereNotNull('clock_in_at')
            ->where('clock_in_at', '<=', $event->occurred_at)->where('clock_in_at', '>=', $event->occurred_at->copy()->subHours(20))
            ->whereIn('status', [Timesheet::OPEN])->orderByDesc('clock_in_at')->first();
        if (! $open) {
            // A clock-out with nothing to close: kept and flagged for a supervisor to resolve.
            $assignment = $this->assignmentAround($worker, $event->occurred_at);
            $open = Timesheet::create([
                'company_id' => $worker->company_id, 'farm_id' => $assignment?->shift?->farm_id ?? $event->farm_id, 'worker_id' => $worker->id,
                'assignment_id' => $assignment?->id, 'work_date' => ($assignment?->shift?->starts_at ?? $event->occurred_at)->toDateString(),
                'scheduled_start' => $assignment?->shift?->starts_at, 'scheduled_end' => $assignment?->shift?->ends_at, 'status' => Timesheet::OPEN,
            ]);
        }
        $open->update(['out_event_id' => $event->id, 'clock_out_at' => $event->occurred_at]);

        return $this->measure($open->fresh());
    }

    /** The shift a clock event belongs to: the one it falls in, or starts within three hours of. */
    private function assignmentAround(Worker $worker, Carbon $at): ?ShiftAssignment
    {
        return ShiftAssignment::withoutGlobalScopes()->where('worker_id', $worker->id)->where('status', 'assigned')
            ->whereHas('shift', fn ($q) => $q->withoutGlobalScopes()->where('status', '!=', 'cancelled')
                ->where('starts_at', '<=', $at->copy()->addHours(3))->where('ends_at', '>=', $at->copy()->subHours(3)))
            ->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])->get()
            ->sortBy(fn ($a) => abs($a->shift->starts_at->diffInMinutes($at)))->first();
    }

    /** Work out everything a timesheet says, from its two events and the rules. */
    public function measure(Timesheet $sheet): Timesheet
    {
        $worker = Worker::withoutGlobalScopes()->findOrFail($sheet->worker_id);
        $rules = $this->workforce->rulesFor($worker);
        $flags = [];
        $in = $sheet->clock_in_at;
        $out = $sheet->clock_out_at;
        $data = ['worked_minutes' => 0, 'break_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0,
            'sunday_minutes' => 0, 'holiday_minutes' => 0, 'night_minutes' => 0, 'minutes_by_date' => null];

        if (! $sheet->assignment_id) {
            $flags[] = 'no_shift';
        }
        if ($in && $sheet->scheduled_start) {
            $late = (int) max(0, $sheet->scheduled_start->diffInMinutes($in, false) - $rules->late_grace_minutes);
            if ($late > 0) {
                $data['late_minutes'] = $late + $rules->late_grace_minutes;
                $flags[] = 'late';
            }
        }
        if (! $in) {
            $flags[] = 'out_without_in';
        }
        if ($in && ! $out) {
            $due = ($sheet->scheduled_end ?? $in->copy()->addHours(12))->copy()->addHours(4);
            if (now()->gt($due)) {
                $flags[] = 'missing_out';
            }
        }

        if ($in && $out) {
            $raw = max(0, (int) $in->diffInMinutes($out));
            $shift = $sheet->assignment_id ? ShiftAssignment::withoutGlobalScopes()->with(['shift' => fn ($q) => $q->withoutGlobalScopes()])->find($sheet->assignment_id)?->shift : null;
            // Unpaid break: the shift's own; without a shift, the 30-minute meal interval after 5 hours.
            $break = $shift ? ($shift->paid_break ? 0 : (int) $shift->break_minutes) : ($raw > 300 ? 30 : 0);
            $break = min($break, $raw);
            $worked = $raw - $break;
            $data['break_minutes'] = $break;
            $data['worked_minutes'] = $worked;

            if ($sheet->scheduled_end && $out->lt($sheet->scheduled_end)) {
                $data['early_minutes'] = (int) $out->diffInMinutes($sheet->scheduled_end);
                $flags[] = 'early';
            }
            $data['overtime_minutes'] = max(0, $worked - (int) $rules->daily_ot_after_minutes);
            if ($sheet->scheduled_end && $out->gt($sheet->scheduled_end->copy()->addMinutes(15))) {
                $approved = OvertimeRequest::withoutGlobalScopes()->where('worker_id', $worker->id)
                    ->whereDate('work_date', $sheet->work_date)->where('status', 'approved')->exists();
                if (! $approved) {
                    $flags[] = 'unplanned_overtime';
                }
            }
            if ($raw > $rules->max_shift_minutes) {
                $flags[] = 'over_max_shift';
            }

            // Minutes per calendar date (an overnight shift lands on both), scaled for the break.
            $byDate = [];
            $night = 0;
            $cursor = $in->copy();
            while ($cursor->lt($out)) {
                $dayEnd = $cursor->copy()->addDay()->startOfDay();
                $segEnd = $dayEnd->lt($out) ? $dayEnd : $out->copy();
                $byDate[$cursor->toDateString()] = ($byDate[$cursor->toDateString()] ?? 0) + (int) $cursor->diffInMinutes($segEnd);
                $night += $this->nightMinutes($cursor, $segEnd, $rules->night_starts, $rules->night_ends);
                $cursor = $segEnd;
            }
            $scale = $raw > 0 ? $worked / $raw : 0;
            foreach ($byDate as $d => $m) {
                $byDate[$d] = (int) round($m * $scale);
            }
            // Rounding must not create or lose a minute — settled before anything is counted per date.
            if ($byDate) {
                $byDate[array_key_last($byDate)] += $worked - array_sum($byDate);
            }
            foreach ($byDate as $d => $m) {
                $date = Carbon::parse($d);
                if ($this->hr->isPublicHoliday($date)) {
                    $data['holiday_minutes'] += $m;
                } elseif ($date->isSunday()) {
                    $data['sunday_minutes'] += $m;
                }
            }
            $data['minutes_by_date'] = $byDate;
            $data['night_minutes'] = (int) round($night * $scale);
        }

        $events = ClockEvent::withoutGlobalScopes()->whereIn('id', array_filter([$sheet->in_event_id, $sheet->out_event_id]))->get();
        if ($events->contains(fn ($e) => $e->captured_offline)) {
            $flags[] = 'offline';
        }
        if ($events->contains(fn ($e) => $e->verification !== 'verified')) {
            $flags[] = 'unverified';
        }
        if ($events->contains(fn ($e) => $e->captured_offline && ! $e->supervisor_confirmed)) {
            $flags[] = 'needs_confirmation';
        }

        $complete = $in && $out;
        $sheet->update($data + [
            'flags' => array_values(array_unique($flags)),
            'status' => in_array($sheet->status, [Timesheet::APPROVED, Timesheet::REJECTED], true) ? $sheet->status
                : ($complete ? Timesheet::COMPLETE : Timesheet::OPEN),
        ]);

        return $sheet;
    }

    private function nightMinutes(Carbon $from, Carbon $to, string $nightStarts, string $nightEnds): int
    {
        $total = 0;
        foreach ([$from->copy()->subDay(), $from->copy()] as $day) {
            $s = $day->copy()->setTimeFromTimeString($nightStarts);
            $e = $day->copy()->setTimeFromTimeString($nightEnds);
            if ($e->lte($s)) {
                $e->addDay();
            }
            $a = $from->gt($s) ? $from : $s;
            $b = $to->lt($e) ? $to : $e;
            if ($b->gt($a)) {
                $total += (int) $a->diffInMinutes($b);
            }
        }

        return $total;
    }

    // ── Corrections ─────────────────────────────────────────────────────────────────────────

    public function requestCorrection(Timesheet $sheet, string $field, Carbon $at, string $reason, User $actor): Correction
    {
        if (! in_array($field, ['clock_in', 'clock_out'], true)) {
            throw ValidationException::withMessages(['field' => 'Correct the clock-in or the clock-out.']);
        }
        if ($sheet->status === Timesheet::APPROVED && $sheet->handoff_line_id) {
            throw ValidationException::withMessages(['field' => 'This time has been sent to payroll. Correct it in payroll.']);
        }
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'A correction needs a reason.']);
        }

        return Correction::create(['company_id' => $sheet->company_id, 'farm_id' => $sheet->farm_id, 'timesheet_id' => $sheet->id,
            'field' => $field, 'original_at' => $sheet->{$field.'_at'}, 'corrected_at' => $at, 'reason' => $reason,
            'status' => 'pending', 'requested_by' => $actor->id,
            'original_event_id' => $field === 'clock_in' ? $sheet->in_event_id : $sheet->out_event_id]);
    }

    /**
     * Approve a correction: a new event at the corrected time takes the old one's place on the
     * timesheet, the old event is marked superseded and kept, and the timesheet is re-measured.
     * Nobody approves a correction they asked for.
     */
    public function reviewCorrection(Correction $c, bool $approve, User $actor): Correction
    {
        if ($c->status !== 'pending') {
            throw ValidationException::withMessages(['correction' => 'This correction has been decided.']);
        }
        if ((int) $c->requested_by === (int) $actor->id) {
            throw ValidationException::withMessages(['correction' => 'You asked for this correction, so somebody else must approve it.']);
        }
        if (! $approve) {
            $c->update(['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

            return $c;
        }

        DB::transaction(function () use ($c, $actor) {
            $sheet = Timesheet::withoutGlobalScopes()->findOrFail($c->timesheet_id);
            $kind = $c->field === 'clock_in' ? 'in' : 'out';
            $event = ClockEvent::create(['company_id' => $sheet->company_id, 'farm_id' => $sheet->farm_id, 'worker_id' => $sheet->worker_id,
                'kind' => $kind, 'occurred_at' => $c->corrected_at, 'method' => 'correction', 'verification' => 'verified',
                'verification_note' => 'Approved correction: '.mb_strimwidth($c->reason, 0, 250), 'recorded_by' => $actor->id, 'supervisor_confirmed' => true]);
            if ($c->original_event_id) {
                ClockEvent::withoutGlobalScopes()->whereKey($c->original_event_id)->update(['superseded_by_id' => $event->id]);
            }
            $sheet->update($kind === 'in'
                ? ['in_event_id' => $event->id, 'clock_in_at' => $event->occurred_at, 'status' => Timesheet::COMPLETE]
                : ['out_event_id' => $event->id, 'clock_out_at' => $event->occurred_at, 'status' => Timesheet::COMPLETE]);
            $this->measure($sheet->fresh());
            $c->update(['status' => 'approved', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'correction_event_id' => $event->id]);
        });

        return $c;
    }

    /** A supervisor vouches for time captured offline or for a whole crew. */
    public function confirm(Timesheet $sheet, User $actor): Timesheet
    {
        ClockEvent::withoutGlobalScopes()->whereIn('id', array_filter([$sheet->in_event_id, $sheet->out_event_id]))
            ->update(['supervisor_confirmed' => true, 'updated_at' => now()]);

        return $this->measure($sheet->fresh());
    }

    /** Approve a timesheet: it becomes the paid, costed record. */
    public function approve(Timesheet $sheet, User $actor): Timesheet
    {
        if ($sheet->status !== Timesheet::COMPLETE) {
            throw ValidationException::withMessages(['timesheet' => $sheet->status === Timesheet::OPEN
                ? 'This timesheet has no clock-out yet; correct it first.' : "This timesheet is {$sheet->status}."]);
        }
        if (in_array('needs_confirmation', $sheet->flags ?? [], true)) {
            throw ValidationException::withMessages(['timesheet' => 'Time captured offline needs a supervisor to confirm it first.']);
        }
        $worker = Worker::withoutGlobalScopes()->findOrFail($sheet->worker_id);
        $sheet->update(['status' => Timesheet::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now(),
            'cost_amount' => $this->cost($sheet, $worker)]);
        // The day's attendance record, which payroll and the rest of HR read, follows the approval.
        app(DailyRecords::class)->sync($worker, $sheet->work_date->toDateString(), $actor);

        return $sheet;
    }

    public function reject(Timesheet $sheet, string $reason, User $actor): Timesheet
    {
        if ($sheet->handoff_line_id) {
            throw ValidationException::withMessages(['timesheet' => 'Already sent to payroll.']);
        }
        $was = $sheet->status;
        $sheet->update(['status' => Timesheet::REJECTED, 'flags' => array_values(array_unique([...($sheet->flags ?? []), 'rejected: '.mb_strimwidth($reason, 0, 80)]))]);
        if ($was === Timesheet::APPROVED) {
            app(DailyRecords::class)->sync(Worker::withoutGlobalScopes()->findOrFail($sheet->worker_id), $sheet->work_date->toDateString(), $actor);
        }

        return $sheet;
    }

    /**
     * What the time cost the farm, for labour costing (not pay — payroll decides pay): every worked
     * minute at the worker's costing rate, plus the premium for overtime, Sunday and public-holiday
     * minutes (the higher one only, never stacked), plus the night allowance.
     */
    public function cost(Timesheet $sheet, Worker $worker): ?float
    {
        $rate = $worker->hourly_cost_rate;
        if (! $rate) {
            return null;
        }
        $r = $this->workforce->rulesFor($worker);
        $h = fn (int $m) => $m / 60;
        $premiumDays = $sheet->holiday_minutes + $sheet->sunday_minutes;
        $otOutsidePremiumDays = max(0, $sheet->overtime_minutes - $premiumDays);
        $cost = $h($sheet->worked_minutes) * $rate
            + $h($sheet->holiday_minutes) * $rate * max(0, $r->holiday_multiplier - 1)
            + $h($sheet->sunday_minutes) * $rate * max(0, $r->sunday_multiplier - 1)
            + $h($otOutsidePremiumDays) * $rate * max(0, $r->ot_multiplier - 1)
            + $h($sheet->night_minutes) * (float) $r->night_allowance_per_hour;

        return round($cost, 2);
    }

    /** Planned against actual for a farm and period: the roster as published beside the clock. */
    public function plannedVersusActual(?Model $site, Carbon $from, Carbon $to): array
    {
        $sheets = Timesheet::query()->when($site, fn ($q) => $q->where('farm_id', $site->id))->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])->get();
        $planned = ShiftAssignment::query()->when($site, fn ($q) => $q->where('attendance_shift_assignments.farm_id', $site->id))->where('attendance_shift_assignments.status', 'assigned')
            ->join('attendance_rostered_shifts as s', 's.id', '=', 'attendance_shift_assignments.shift_id')->where('s.status', 'published')
            ->whereBetween('s.starts_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get(['s.starts_at', 's.ends_at', 's.break_minutes', 's.paid_break']);
        $plannedMinutes = $planned->sum(fn ($p) => max(0, Carbon::parse($p->starts_at)->diffInMinutes(Carbon::parse($p->ends_at)) - ($p->paid_break ? 0 : $p->break_minutes)));

        return [
            'planned_hours' => round($plannedMinutes / 60, 1),
            'actual_hours' => round($sheets->sum('worked_minutes') / 60, 1),
            'overtime_hours' => round($sheets->sum('overtime_minutes') / 60, 1),
            'late_count' => $sheets->filter(fn ($s) => in_array('late', $s->flags ?? [], true))->count(),
            'missing_out' => $sheets->filter(fn ($s) => in_array('missing_out', $s->flags ?? [], true))->count(),
            'shifts_planned' => $planned->count(),
            'shifts_worked' => $sheets->whereNotNull('assignment_id')->count(),
        ];
    }

    /** @return array{0: ?float, 1: ?float, 2: ?bool, 3: string, 4: ?string} */
    private function verify(Worker $worker, array $meta): array
    {
        $lat = $meta['latitude'] ?? null;
        $lng = $meta['longitude'] ?? null;
        if ($lat === null || $lng === null) {
            return [null, null, null, 'verified', null];
        }
        if (! $worker->gps_allowed) {
            // No consent: the location is not kept at all.
            return [null, null, null, 'verified', 'Location not kept: no consent recorded.'];
        }
        $site = Sites::find($meta['farm_id'] ?? $worker->farm_id);
        if (! $site || $site->latitude === null) {
            return [$lat, $lng, null, 'verified', null];
        }
        $d = $this->metres((float) $lat, (float) $lng, (float) $site->latitude, (float) $site->longitude);
        if ($d > self::GPS_TOLERANCE_M) {
            return [$lat, $lng, false, 'unverified', 'Recorded '.round($d / 1000, 1).' km from the farm.'];
        }

        return [$lat, $lng, true, 'verified', null];
    }

    private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
