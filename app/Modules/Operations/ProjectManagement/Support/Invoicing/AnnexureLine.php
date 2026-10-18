<?php

namespace App\Modules\Operations\ProjectManagement\Support\Invoicing;

/**
 * The five shapes of annexure line, decoded from the workbook's formulas.
 *
 * Thirteen annexures, but only five calculations between them. Naming them once here is what stops
 * the 10% surcharge being written four slightly different ways across Annexures 4, 5, 12 and 13.
 *
 * Every annexure shares the same three-column tail — previously claimed, this invoice, total — so
 * `runningTotal()` serves all of them.
 */
final class AnnexureLine
{
    /** The surcharge the client applies to a sub-consultant's invoice, written in Excel as × 110%. */
    public const DEFAULT_SURCHARGE = '0.10';

    /**
     * The workbook derives travelling *time* from travelling *distance* at this speed.
     *
     * `'Annexure 3'!E10 = 'Annexure 2'!E9 / 100` — kilometres divided by 100 gives hours, i.e. a
     * flat 100 km/h. It is an assumption about the road rather than a measurement, and it is the
     * single least guessable rule in the workbook: nothing in the field list ("Date, Name, Post
     * Level, Tariff, Hours, Less Hours") suggests hours are not entered by hand.
     */
    public const KM_PER_HOUR = '100';

    private function __construct()
    {
    }

    /**
     * Annexure 1 — printing, copying, acquisition of documents.
     *
     * `F = C × D` — quantity × tariff, per size (A4…A0) and type (black & white, colour).
     */
    public static function documentation(string|float|int|null $quantity, string|float|int|null $tariff): string
    {
        return Money::mul($quantity, $tariff);
    }

    /**
     * Annexure 2 — travelling and subsistence.
     *
     * `H = D × E` — tariff per kilometre × kilometres. The "less 50km" column exists on the sheet
     * but this workbook folds the deduction into the distance before it reaches the formula, so it
     * is a parameter here rather than a separate step: passing `lessKm` subtracts before charging.
     */
    public static function travelling(
        string|float|int|null $kilometres,
        string|float|int|null $tariffPerKm,
        string|float|int|null $lessKm = '0',
    ): string {
        return Money::mul(Money::sub($kilometres, $lessKm), $tariffPerKm);
    }

    /**
     * Annexure 3 — travelling time.
     *
     * `H = (E − F) × D`, where E is hours *derived from the distance* (km ÷ 100), F is the hours
     * deducted, and D is the post-level rate. Worked example from the workbook: 276 km → 2.76 h,
     * less 1 h, at R1 396/h = R2 456.96.
     */
    public static function travellingTime(
        string|float|int|null $kilometres,
        string|float|int|null $lessHours,
        string|float|int|null $ratePerHour,
    ): string {
        $hours = Money::div($kilometres, self::KM_PER_HOUR);

        return Money::mul(Money::sub($hours, $lessHours), $ratePerHour);
    }

    /**
     * Annexures 4, 5, 6, 7, 12, 13 — a sub-consultant's invoice plus the handling surcharge.
     *
     * `G = E × 110%`. Charged on the appointment amount, not on anything already claimed. Worked
     * example: Annexure 4's R113 011.57 appointment becomes R124 312.727.
     *
     * Rows not claimed in a period appear in the workbook as `E × 110% × 0` — the line is kept and
     * zeroed rather than deleted, so the schedule still reads as a complete list of appointments.
     * That is what `$claimedThisPeriod = false` reproduces.
     */
    public static function surcharged(
        string|float|int|null $amount,
        string|float|int|null $surchargeRate = self::DEFAULT_SURCHARGE,
        bool $claimedThisPeriod = true,
    ): string {
        return $claimedThisPeriod ? Money::withSurcharge($amount, $surchargeRate) : '0';
    }

    /**
     * Annexure 11 — time basis for rework.
     *
     * `H = D × E` — rate × hours. The rows themselves are only ever that; the workbook's
     * "additional work = 75% of stage 4" note sizes the claim rather than valuing a line, and is
     * `reworkFromStage()` below.
     */
    public static function timeBasis(
        string|float|int|null $ratePerHour,
        string|float|int|null $hours,
    ): string {
        return Money::mul($ratePerHour, $hours);
    }

    /** The proportion of a stage the workbook treats as additional work: 75% of stage 4. */
    public const REWORK_SHARE_OF_STAGE = '0.75';

    /**
     * Annexure 11's "Additional work = 75% of stage 4".
     *
     * The workbook states the rule beside the rows rather than in them: rework is valued at three
     * quarters of the stage 4 fee. Whoever prepares the account then works the hours back from
     * that figure, which is why the sheet's rows are still rate × hours.
     *
     * Offered as a calculation so nobody has to do it on a calculator and type the answer — the
     * hours it suggests are what `timeBasis` would need to reach the same amount.
     *
     * @return array{amount:string, hours:string}
     */
    public static function reworkFromStage(
        string|float|int|null $stageFee,
        string|float|int|null $ratePerHour,
        string|float|int|null $share = self::REWORK_SHARE_OF_STAGE,
    ): array {
        $amount = Money::mul($stageFee, $share);

        return [
            'amount' => $amount,
            // Zero rate would be a division by zero; Money::div answers 0 rather than raising.
            'hours'  => Money::div($amount, $ratePerHour),
        ];
    }

    /**
     * The tail every annexure shares: previously claimed + this invoice = total claimed to date.
     *
     * @return array{previously_claimed:string, this_invoice:string, total:string}
     */
    public static function runningTotal(
        string|float|int|null $previouslyClaimed,
        string|float|int|null $thisInvoice,
    ): array {
        return [
            'previously_claimed' => Money::of($previouslyClaimed),
            'this_invoice'       => Money::of($thisInvoice),
            'total'              => Money::add($previouslyClaimed, $thisInvoice),
        ];
    }

    /**
     * A monthly projection spread evenly, as the workbook does it (`= 10500 / 6`).
     *
     * @return list<string>
     */
    public static function evenProjection(string|float|int|null $amount, int $months): array
    {
        if ($months < 1) {
            return [];
        }

        $each = Money::div($amount, (string) $months);

        return array_fill(0, $months, $each);
    }
}
