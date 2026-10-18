<?php

namespace App\Modules\HR\Payroll\Support;

use App\Modules\HR\Payroll\Models\Bureau\BureauPayRun;

/**
 * The states a client's payroll passes through, and the only moves allowed between them.
 *
 * Written down in one place rather than as a scatter of `if ($run->status !== 'review')` checks,
 * because the checks were what actually defined the lifecycle and no two of them agreed. Reading
 * the old code, it was genuinely unclear whether a finalised run could be reprocessed — one method
 * said no and another never asked.
 *
 *   draft      nothing calculated yet; inputs can still be added
 *   review     calculated, and somebody has to look at it
 *   approved   a named person has signed the figures off
 *   finalised  payslips locked and encrypted; the figures cannot move
 *   paid       the money has been released
 *   completed  paid and reported to SARS; the month is closed
 *
 * Approval is deliberately its own state rather than part of finalising. Finalising used to do four
 * jobs at once — approve the figures, lock the payslips, and implicitly clear the run for payment —
 * so whoever calculated a run could also release the money, with no name recorded against the
 * decision. For a bureau paying other people's staff that is the whole control missing.
 */
class PayRunLifecycle
{
    public const DRAFT = BureauPayRun::STATUS_DRAFT;
    public const REVIEW = BureauPayRun::STATUS_REVIEW;
    public const APPROVED = 'approved';
    public const FINALISED = BureauPayRun::STATUS_FINALISED;
    public const PAID = 'paid';
    public const COMPLETED = 'completed';

    /** In the order they happen, which is the order a screen should show them. */
    public const ORDER = [
        self::DRAFT, self::REVIEW, self::APPROVED, self::FINALISED, self::PAID, self::COMPLETED,
    ];

    /**
     * Where each state can go.
     *
     * Forwards only. Reprocessing a run in review is allowed and expected — that is what fixing an
     * input and pressing calculate again is — but nothing here walks a run backwards. Once it is
     * approved the only way back is `reopen`, which demands a reason and does not consult this map;
     * listing review as a destination from approved would have let a plain recalculate undo an
     * approval silently, which is precisely the door this whole lifecycle exists to close.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::DRAFT     => [self::REVIEW],
        self::REVIEW    => [self::REVIEW, self::APPROVED],
        self::APPROVED  => [self::FINALISED],
        self::FINALISED => [self::PAID],
        self::PAID      => [self::COMPLETED],
        self::COMPLETED => [],
    ];

    /** The moves that are only reachable by reopening, with the reason that requires. */
    private const REOPENABLE = [self::APPROVED, self::FINALISED, self::PAID, self::COMPLETED];

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Can this state be moved backwards at all, and only through an authorised reopen? */
    public static function isReopenable(string $status): bool
    {
        return in_array($status, self::REOPENABLE, true);
    }

    /** Past approval, the figures are somebody's signed-off record and are not edited in place. */
    public static function isLocked(string $status): bool
    {
        return in_array($status, [self::APPROVED, self::FINALISED, self::PAID, self::COMPLETED], true);
    }

    /** How far along, for a progress indicator. */
    public static function position(string $status): int
    {
        $index = array_search($status, self::ORDER, true);

        return $index === false ? 0 : $index;
    }

    /** What a person would call it. */
    public static function label(string $status): string
    {
        return match ($status) {
            self::DRAFT     => 'Draft',
            self::REVIEW    => 'Calculated — awaiting approval',
            self::APPROVED  => 'Approved',
            self::FINALISED => 'Finalised and locked',
            self::PAID      => 'Payment released',
            self::COMPLETED => 'Completed',
            default         => ucfirst($status),
        };
    }
}
