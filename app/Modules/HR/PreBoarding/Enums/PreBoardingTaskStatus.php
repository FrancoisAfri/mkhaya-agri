<?php

namespace App\Modules\HR\PreBoarding\Enums;

enum PreBoardingTaskStatus: string
{
    case Pending   = 'pending';
    case Completed = 'completed';
    case Skipped   = 'skipped';

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'Pending',
            self::Completed => 'Completed',
            self::Skipped   => 'Skipped',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Skipped]);
    }
}
