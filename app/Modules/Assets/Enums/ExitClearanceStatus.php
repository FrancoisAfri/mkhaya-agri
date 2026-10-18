<?php

namespace App\Modules\Assets\Enums;

enum ExitClearanceStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Cleared    = 'cleared';
    case Blocked    = 'blocked';

    public function label(): string
    {
        return match($this) {
            self::Pending    => 'Pending',
            self::InProgress => 'In Progress',
            self::Cleared    => 'Cleared',
            self::Blocked    => 'Blocked',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending    => 'warning',
            self::InProgress => 'info',
            self::Cleared    => 'success',
            self::Blocked    => 'error',
        };
    }
}
