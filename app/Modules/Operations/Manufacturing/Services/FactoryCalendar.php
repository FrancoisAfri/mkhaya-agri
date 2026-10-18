<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\HR\Leave\Services\WorkingDaysCalculator;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Shift;
use App\Modules\Operations\Manufacturing\Models\Shutdown;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The working calendar of a factory (MFG-001.04): how many minutes it can actually run on a day.
 *
 * Shifts, public holidays and shutdowns were each recorded and nothing combined them, which left
 * "working calendars" as three lists rather than a calendar. This is the combination, and it is
 * what capacity planning will ask: on this date, at this factory (or on this line), how long can
 * production run?
 *
 * The rules, each of which is a decision rather than an accident:
 *
 *  - A shift belongs to the day it STARTS. A night shift from Friday 22:00 is Friday's capacity,
 *    even though most of it falls on Saturday.
 *  - Holidays come from the platform's own public-holiday register, through the same calculator
 *    Leave uses, so the two modules can never disagree about whether a day is a holiday. A shift
 *    that does not observe holidays runs anyway.
 *  - A shutdown removes the time it overlaps. With no line asked about, only factory-wide shutdowns
 *    count: a stopped line does not stop the factory.
 *  - A shift wholly inside a shutdown is worth nothing, break included. A partly covered one loses
 *    the overlap and still takes its break, because people still stop for it.
 *
 * Times are interpreted in the factory's own timezone, which is why the factory carries one.
 */
class FactoryCalendar
{
    private ?WorkingDaysCalculator $holidays = null;

    /**
     * @return array{date: string, weekday: int, is_holiday: bool, minutes: int,
     *               shifts: list<array{code: string, name: string, minutes: int, reason: ?string}>}
     */
    public function day(Factory $factory, DateTimeInterface $date, ?int $lineId = null): array
    {
        return $this->days($factory, $date, $date, $lineId)[0];
    }

    /**
     * Each day from $from to $to inclusive.
     *
     * @return list<array<string, mixed>>
     */
    public function days(Factory $factory, DateTimeInterface $from, DateTimeInterface $to, ?int $lineId = null): array
    {
        $tz = $factory->timezone ?: config('app.timezone');
        $start = Carbon::parse(Carbon::instance($from)->toDateString(), $tz)->startOfDay();
        $end = Carbon::parse(Carbon::instance($to)->toDateString(), $tz)->startOfDay();

        $shifts = Shift::query()->where('factory_id', $factory->id)->where('is_active', true)->get();

        // Everything that could touch the window, loaded once rather than per day.
        $shutdowns = Shutdown::query()
            ->where('factory_id', $factory->id)
            ->where('starts_at', '<', $end->copy()->addDays(2))
            ->where('ends_at', '>', $start->copy()->subDay())
            ->get()
            ->filter(fn (Shutdown $s) => $lineId === null
                ? $s->production_line_id === null
                : $s->affectsLine($lineId))
            ->values();

        $out = [];

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $out[] = $this->resolveDay($day->copy(), $shifts, $shutdowns);
        }

        return $out;
    }

    /** Total runnable minutes across a window -- the number capacity planning divides by. */
    public function minutesBetween(Factory $factory, DateTimeInterface $from, DateTimeInterface $to, ?int $lineId = null): int
    {
        return array_sum(array_column($this->days($factory, $from, $to, $lineId), 'minutes'));
    }

    private function resolveDay(Carbon $day, Collection $shifts, Collection $shutdowns): array
    {
        $isHoliday = $this->holidays()->isPublicHoliday($day->copy());
        $weekday = $day->isoWeekday();

        $rows = [];

        foreach ($shifts as $shift) {
            if (! in_array($weekday, array_map('intval', $shift->days_of_week ?? []), true)) {
                continue;
            }

            if ($isHoliday && $shift->observes_public_holidays) {
                $rows[] = ['code' => $shift->code, 'name' => $shift->name, 'minutes' => 0, 'reason' => 'HOLIDAY'];

                continue;
            }

            [$startsAt, $endsAt] = $this->window($shift, $day);
            $length = (int) $startsAt->diffInMinutes($endsAt);
            $lost = $this->overlap($startsAt, $endsAt, $shutdowns);

            if ($lost >= $length) {
                $rows[] = ['code' => $shift->code, 'name' => $shift->name, 'minutes' => 0, 'reason' => 'SHUTDOWN'];

                continue;
            }

            $minutes = max(0, $length - $lost - (int) $shift->break_minutes);

            $rows[] = [
                'code' => $shift->code,
                'name' => $shift->name,
                'minutes' => $minutes,
                'reason' => $lost > 0 ? 'PARTIAL_SHUTDOWN' : null,
            ];
        }

        return [
            'date' => $day->toDateString(),
            'weekday' => $weekday,
            'is_holiday' => $isHoliday,
            'minutes' => array_sum(array_column($rows, 'minutes')),
            'shifts' => $rows,
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function window(Shift $shift, Carbon $day): array
    {
        $raw = $shift->getAttributes();
        $startsAt = Carbon::parse($day->toDateString().' '.substr((string) $raw['starts_at'], 0, 8), $day->getTimezone());
        $endsAt = Carbon::parse($day->toDateString().' '.substr((string) $raw['ends_at'], 0, 8), $day->getTimezone());

        if ($shift->crosses_midnight || $endsAt->lessThanOrEqualTo($startsAt)) {
            $endsAt->addDay();
        }

        return [$startsAt, $endsAt];
    }

    /** Minutes of [start, end) covered by any shutdown, without double-counting overlaps between them. */
    private function overlap(Carbon $start, Carbon $end, Collection $shutdowns): int
    {
        $intervals = [];

        foreach ($shutdowns as $s) {
            $from = max($start->getTimestamp(), $s->starts_at->getTimestamp());
            $to = min($end->getTimestamp(), $s->ends_at->getTimestamp());

            if ($to > $from) {
                $intervals[] = [$from, $to];
            }
        }

        if ($intervals === []) {
            return 0;
        }

        // Two shutdowns covering the same hour must not remove it twice.
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [array_shift($intervals)];

        foreach ($intervals as [$from, $to]) {
            $last = &$merged[count($merged) - 1];

            if ($from <= $last[1]) {
                $last[1] = max($last[1], $to);
            } else {
                $merged[] = [$from, $to];
            }

            unset($last);
        }

        return (int) round(array_sum(array_map(fn ($i) => $i[1] - $i[0], $merged)) / 60);
    }

    private function holidays(): WorkingDaysCalculator
    {
        // Built lazily, inside whatever tenant is active, so it reads that tenant's holidays.
        return $this->holidays ??= new WorkingDaysCalculator();
    }
}
