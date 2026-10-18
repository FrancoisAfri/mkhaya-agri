<?php

namespace App\Modules\SupplierManagement\DueDiligence\Support;

/**
 * The outcome of a check.
 *
 * The distinction that matters most is between UNAVAILABLE and everything else. A check that could
 * not be run is not a pass, is not a failure, and must never be presented as either — it is a
 * visible gap. Systems that collapse "we could not check" into "nothing found" are how a supplier
 * with an active judgment gets onboarded on a clean-looking file.
 */
final class VerificationStatus
{
    /** The source confirmed what the supplier claimed. */
    public const VERIFIED = 'verified';

    /** The source answered, and it does not agree with what the supplier claimed. */
    public const MISMATCH = 'mismatch';

    /** The source answered, and has no record of this subject at all. */
    public const NOT_FOUND = 'not_found';

    /** No provider is configured, or the provider could not be reached. Not a pass. */
    public const UNAVAILABLE = 'unavailable';

    /** The provider answered ambiguously, or a person must adjudicate before this counts. */
    public const MANUAL_REVIEW = 'manual_review';

    /** The provider was reached and failed. Distinct from unavailable so the registry can show it. */
    public const ERROR = 'error';

    public const ALL = [
        self::VERIFIED, self::MISMATCH, self::NOT_FOUND,
        self::UNAVAILABLE, self::MANUAL_REVIEW, self::ERROR,
    ];

    /** Statuses that satisfy an approval gate. Deliberately just the one. */
    public static function passes(string $status): bool
    {
        return $status === self::VERIFIED;
    }

    /** Statuses a person has to do something about. */
    public static function needsAttention(string $status): bool
    {
        return in_array($status, [self::MISMATCH, self::NOT_FOUND, self::MANUAL_REVIEW], true);
    }

    /**
     * How this reads in the UI. Paired with a word everywhere it is shown — a colour alone cannot
     * distinguish "clear" from "never checked", and that is the one distinction a reader must not
     * get wrong.
     */
    public static function severity(string $status): string
    {
        return match ($status) {
            self::VERIFIED => 'clear',
            self::MISMATCH, self::NOT_FOUND => 'high',
            self::MANUAL_REVIEW => 'watch',
            default => 'unknown',
        };
    }
}
