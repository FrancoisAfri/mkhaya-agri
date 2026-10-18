<?php

namespace App\Modules\HR\Onboarding\Enums;

enum OnboardingTaskStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Skipped    = 'skipped';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Pending',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Skipped    => 'Skipped',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Skipped]);
    }
}
