<?php

namespace App\Modules\HR\Recruitment\Enums;

/**
 * The kind of step a vacancy's pipeline stage is.
 *
 * These are the five the column allows — the migration declares
 * `enum('type', ['screening', 'assessment', 'interview', 'offer', 'onboarding'])` — and they are
 * in the order a candidate meets them, which is what `sortOrder()` is for.
 *
 * ── Why this was rewritten ───────────────────────────────────────────────────
 *
 * It previously listed `new, screening, shortlisted, interview_1, interview_2, final_interview,
 * offer, hired, rejected`: the vocabulary of an *application's status*, not of a pipeline stage.
 * Only `screening` and `offer` appear in both, so a stage of any other kind threw the moment it
 * was read — 45 of the 75 stages on a seeded install — and that took the vacancy's pipeline screen
 * down with it.
 *
 * Nothing referenced the cases that did not belong here, which is the clearest sign they were
 * never the right list. The factory only ever built `Screening`, the one value the two
 * vocabularies happen to share, which is why a hundred and thirty-five passing tests never saw it.
 */
enum PipelineStageType: string
{
    case Screening  = 'screening';
    case Assessment = 'assessment';
    case Interview  = 'interview';
    case Offer      = 'offer';
    case Onboarding = 'onboarding';

    public function label(): string
    {
        return match ($this) {
            self::Screening  => 'Screening',
            self::Assessment => 'Assessment',
            self::Interview  => 'Interview',
            self::Offer      => 'Offer',
            self::Onboarding => 'Onboarding',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Screening  => 'warning',
            self::Assessment => 'info',
            self::Interview  => 'primary',
            self::Offer      => 'success',
            // The candidate has the job; this is the handover rather than part of the selection.
            self::Onboarding => 'secondary',
        };
    }

    public function sortOrder(): int
    {
        return match ($this) {
            self::Screening  => 1,
            self::Assessment => 2,
            self::Interview  => 3,
            self::Offer      => 4,
            self::Onboarding => 5,
        };
    }
}
