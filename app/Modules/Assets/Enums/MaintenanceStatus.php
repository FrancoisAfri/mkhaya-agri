<?php

namespace App\Modules\Assets\Enums;

enum MaintenanceStatus: string
{
    case Scheduled  = 'scheduled';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Overdue    = 'overdue';
    case Cancelled  = 'cancelled';
    case Skipped    = 'skipped';

    public function label(): string
    {
        return match($this) {
            self::Scheduled  => 'Scheduled',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Overdue    => 'Overdue',
            self::Cancelled  => 'Cancelled',
            self::Skipped    => 'Skipped',
        };
    }
}
