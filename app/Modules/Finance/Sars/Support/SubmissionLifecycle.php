<?php

namespace App\Modules\Finance\Sars\Support;

/**
 * The order a SARS return moves through, and what may follow what.
 *
 * SarsSubmission::STATUSES has listed this lifecycle since the table was created, but nothing
 * enforced it: setStatus() validated only that the value was one of the six, so any status could
 * follow any other. A return could go from draft straight to accepted without ever being validated
 * or filed, and — the one that matters — a return already submitted to SARS could be moved back to
 * draft, leaving the record disagreeing with what the revenue service holds.
 *
 * ── On the vocabulary ───────────────────────────────────────────────────────
 *
 * The requirement asks for Draft → Reviewed → Finalised → Submitted. These six names are the same
 * four stages under the names this table has used since it was created, and they are shared with
 * VAT201, EMP501, IRP5 and UI-19:
 *
 *   draft      the shell of a return, before figures are drawn
 *   generated  built from payroll, figures snapshotted        (Draft)
 *   validated  checked against the SARS profile, no errors    (Reviewed)
 *   submitted  filed with SARS                                (Finalised / Submitted)
 *   accepted   SARS acknowledged it
 *   rejected   SARS refused it, or review found it wrong
 *
 * Renaming them would rewrite every stored row and every other return type for a vocabulary
 * difference, so the order is enforced under the existing names instead.
 */
final class SubmissionLifecycle
{
    /**
     * What may follow what.
     *
     * `rejected` is deliberately terminal. A refused return is corrected by generating a new
     * version — an amendment chain, which the table already models through `amends_id` — and not
     * by editing the refused one back into life. Reviving it would lose the fact that SARS refused
     * something, which is exactly the fact an auditor comes looking for.
     */
    public const TRANSITIONS = [
        'draft'     => ['generated'],
        'generated' => ['validated', 'rejected'],
        'validated' => ['submitted', 'rejected'],
        'submitted' => ['accepted', 'rejected'],
        'accepted'  => [],
        'rejected'  => [],
    ];

    /** Statuses from which nothing further may happen to this version of the return. */
    public const TERMINAL = ['accepted', 'rejected'];

    public static function canMove(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> the statuses this return may legitimately be moved to next */
    public static function nextFrom(?string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array((string) $status, self::TERMINAL, true);
    }

    /**
     * Why a move is refused, phrased for the person who attempted it.
     *
     * "Invalid status transition" tells somebody nothing about what to do next, and the answer is
     * usually a different action entirely — amend rather than edit — so it is said here.
     */
    public static function refusalReason(?string $from, string $to): string
    {
        if ($from === $to) {
            return "This return is already {$to}.";
        }

        if (self::isTerminal($from)) {
            return $from === 'accepted'
                ? 'SARS has accepted this return. Correct it by generating a new version, which files as an amendment.'
                : 'This return was rejected. Generate a new version to correct and re-file it — the rejected one stays as it is, as the record of what SARS refused.';
        }

        $allowed = self::nextFrom($from);

        if ($allowed === []) {
            return "A return that is {$from} cannot be moved.";
        }

        $reason = sprintf('A return that is %s can only move to %s.', $from, implode(' or ', $allowed));

        // Said only when it is true. Every refused move is a move backwards or a skipped step, and
        // telling somebody their return "has already been filed with SARS" when it has not sends
        // them looking for a filing that does not exist.
        if ($from === 'submitted') {
            $reason .= ' A return already filed with SARS cannot be moved back.';
        }

        return $reason;
    }
}
