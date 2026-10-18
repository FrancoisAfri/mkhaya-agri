<?php

namespace App\Modules\HR\Payroll\Support;

use App\Modules\HR\Payroll\Services\Bureau\PayrollTaxEngine;
use Illuminate\Support\Carbon;

/**
 * Where a pay period starts and ends, given how often somebody is paid.
 *
 * Every date on a pay run was typed by hand: period start, period end and pay date were three blank
 * boxes on a form, with nothing checking that they described a real cycle. A weekly run covering
 * five weeks, or a monthly run ending mid-month, was accepted without comment — and since the money
 * is annualised from the frequency rather than from the dates, the payslip came out right while the
 * period it claimed to cover was wrong. That is the shape of error nobody notices until a SARS
 * reconciliation, because it is the *label* that is wrong, not the arithmetic.
 *
 * So the dates are derived here from the one fact that decides them, and offered to the officer to
 * accept or override rather than left blank.
 *
 * Deliberately not a second vocabulary of frequencies: PayrollTaxEngine already owns that, aliases
 * and all, and a screen that agreed with one and not the other would be worse than either.
 */
class PayPeriod
{
    /**
     * The last day of the period that begins on $start.
     *
     * Weekly and fortnightly are counted in days, because a week is seven days wherever it falls.
     * Monthly follows the calendar rather than adding thirty days, so a period beginning on the 1st
     * ends on the 28th, 30th or 31st as that month actually has — which is what a payslip has to say
     * and what UIF and PAYE are reconciled against.
     */
    public static function endFor(string $frequency, Carbon|string $start): Carbon
    {
        $from = Carbon::parse($start)->startOfDay();

        return match (PayrollTaxEngine::canonicalFrequency($frequency)) {
            'weekly'      => $from->copy()->addDays(6),
            'fortnightly' => $from->copy()->addDays(13),
            // A cycle that starts mid-month ends the day before the same date next month — the 15th
            // to the 14th — rather than jumping to month end, because that is the cycle somebody
            // chose by starting it there.
            default       => $from->day === 1
                ? $from->copy()->endOfMonth()
                : $from->copy()->addMonthNoOverflow()->subDay(),
        };
    }

    /**
     * The day the money lands, if nobody says otherwise.
     *
     * The end of the period, which is the safe default: paying earlier than the period it covers is
     * a decision an employer makes deliberately, not something a system should assume for them.
     */
    public static function payDateFor(string $frequency, Carbon|string $start): Carbon
    {
        return self::endFor($frequency, $start);
    }

    /** The period immediately following the one that started on $start. */
    public static function nextStart(string $frequency, Carbon|string $start): Carbon
    {
        return self::endFor($frequency, $start)->addDay();
    }

    /**
     * Do two periods cover any of the same days?
     *
     * The question behind "has this employee already been paid for this week". Inclusive at both
     * ends, because a period runs to the end of its last day.
     */
    public static function overlaps(
        Carbon|string $aStart,
        Carbon|string $aEnd,
        Carbon|string $bStart,
        Carbon|string $bEnd,
    ): bool {
        $aFrom = Carbon::parse($aStart)->startOfDay();
        $aTo   = Carbon::parse($aEnd)->endOfDay();
        $bFrom = Carbon::parse($bStart)->startOfDay();
        $bTo   = Carbon::parse($bEnd)->endOfDay();

        return $aFrom->lessThanOrEqualTo($bTo) && $bFrom->lessThanOrEqualTo($aTo);
    }

    /**
     * How the period reads on a report.
     *
     * Carries the frequency, because "1–7 March" and "1–31 March" are different runs and a report
     * that shows only dates leaves the reader to work out which cycle they are looking at.
     */
    public static function label(string $frequency, Carbon|string $start, Carbon|string $end): string
    {
        $canonical = PayrollTaxEngine::canonicalFrequency($frequency) ?? $frequency;

        return sprintf(
            '%s %s – %s',
            ucfirst($canonical),
            Carbon::parse($start)->format('j M Y'),
            Carbon::parse($end)->format('j M Y'),
        );
    }
}
