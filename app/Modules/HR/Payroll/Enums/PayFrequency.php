<?php

namespace App\Modules\HR\Payroll\Enums;

use App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * How often somebody is paid.
 *
 * One company, several frequencies. Until now the direct payroll assumed monthly — `frequency`
 * existed on the period but every employee was implicitly on the same one — while the bureau
 * carried the field per employee and never used it to decide who was in a run. Both arrangements
 * have the same failure: a weekly run that sweeps up monthly staff pays them a month early, and
 * nothing in the schema objects.
 *
 * The values are the strings already in the database ('monthly', 'fortnightly'), so this names
 * what was there rather than migrating it.
 *
 * ── Why the period maths lives here ──────────────────────────────────────────
 *
 * A frequency is not a label; it decides the pay-period boundaries, how many periods make a year,
 * and therefore every annualised statutory figure. PAYE on a weekly wage is calculated by
 * annualising it by 52, not by 12, and a fortnightly employee paid as though monthly is taxed
 * wrongly in a way nobody notices until SARS reconciles. Keeping the arithmetic beside the enum
 * means the two cannot drift.
 */
enum PayFrequency: string
{
    case Weekly      = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly     = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly      => 'Weekly',
            self::Fortnightly => 'Fortnightly',
            self::Monthly     => 'Monthly',
        };
    }

    /** The translation key, so a payslip reads in the employee's language. */
    public function labelKey(): string
    {
        return 'payroll.frequency.' . $this->value;
    }

    /**
     * Periods in a tax year — the annualisation factor for PAYE, UIF and SDL.
     *
     * Delegated rather than restated. PayrollTaxEngine::PERIODS has been the tax engine's answer
     * since weekly payroll was made to work, and a second copy of 52/26/12 sitting here is a
     * second thing to update when somebody adds a frequency — with a wrong PAYE on every payslip
     * of that frequency as the symptom, which is precisely the defect that table was written to
     * end.
     */
    public function periodsPerYear(): int
    {
        return PayrollTaxEngine::periodsFor($this->value);
    }

    /** Ordinary working days in one period, before public holidays and leave. */
    public function workingDays(): int
    {
        return match ($this) {
            self::Weekly      => 5,
            self::Fortnightly => 10,
            self::Monthly     => 22,
        };
    }

    /**
     * The pay period containing $date.
     *
     * Weekly and fortnightly run Monday to Sunday, because a wage week that starts mid-week makes
     * overtime thresholds and the BCEA's weekly limits impossible to reason about. Monthly is the
     * calendar month, which is what every South African payslip and EMP201 assumes.
     *
     * Fortnightly is anchored to an even ISO week so the boundary does not drift: taking "two weeks
     * back from today" moves the period every time it is asked, and two runs a fortnight apart
     * would then overlap by a day or miss one.
     *
     * @return array{0: Carbon, 1: Carbon}  [start, end] — both inclusive
     */
    public function periodFor(Carbon $date): array
    {
        $date = $date->copy()->startOfDay();

        return match ($this) {
            self::Monthly => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            self::Weekly  => [$date->copy()->startOfWeek(Carbon::MONDAY), $date->copy()->endOfWeek(Carbon::SUNDAY)],
            self::Fortnightly => (function () use ($date) {
                $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
                // Odd ISO week means we are in the second half of the fortnight; step back a week
                // so both halves resolve to the same period.
                if ($weekStart->isoWeek() % 2 === 1) {
                    $weekStart->subWeek();
                }

                return [$weekStart, $weekStart->copy()->addDays(13)];
            })(),
        };
    }

    /** The period after this one — how a schedule advances without re-deriving it from today. */
    public function nextPeriod(Carbon $periodStart): array
    {
        return $this->periodFor(match ($this) {
            self::Monthly     => $periodStart->copy()->addMonthNoOverflow(),
            self::Weekly      => $periodStart->copy()->addWeek(),
            self::Fortnightly => $periodStart->copy()->addWeeks(2),
        });
    }

    /** @return array<int, array{value: string, title: string}> for a select */
    public static function options(): array
    {
        return array_map(
            fn (self $f) => ['value' => $f->value, 'title' => $f->label()],
            self::cases(),
        );
    }

    /**
     * Read a frequency that came from the database or a form — or null if it is not one we can tax.
     *
     * Null rather than a quiet monthly, and the aliases come from the tax engine so "biweekly" and
     * "bi-weekly" still resolve. Substituting monthly for an unrecognised value is what kept the
     * original fortnightly bug invisible: the payslip looked ordinary and the PAYE was simply
     * wrong. Callers decide what to do about not knowing, and are expected to say so.
     */
    public static function tryParse(?string $value): ?self
    {
        $canonical = PayrollTaxEngine::canonicalFrequency($value);

        return $canonical ? self::from($canonical) : null;
    }

    /**
     * The same, for a screen rather than a calculation.
     *
     * A list that has to render every employee cannot stop on one blank field, so display falls
     * back to monthly — but it says so in the log, and no money is calculated from this.
     */
    public static function forDisplay(?string $value): self
    {
        $parsed = self::tryParse($value);

        if (! $parsed && ! blank($value)) {
            Log::warning('payroll: unknown pay frequency shown as monthly', ['pay_frequency' => $value]);
        }

        return $parsed ?? self::Monthly;
    }
}
