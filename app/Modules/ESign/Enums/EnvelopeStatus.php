<?php

namespace App\Modules\ESign\Enums;

enum EnvelopeStatus: string
{
    case Draft      = 'draft';
    case Sent       = 'sent';
    case InProgress = 'in_progress';
    case Completed  = 'completed';
    case Expired    = 'expired';
    case Voided     = 'voided';

    public function label(): string
    {
        return match($this) {
            self::Draft      => 'Draft',
            self::Sent       => 'Sent',
            self::InProgress => 'In Progress',
            self::Completed  => 'Completed',
            self::Expired    => 'Expired',
            self::Voided     => 'Voided',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft      => 'default',
            self::Sent       => 'info',
            self::InProgress => 'warning',
            self::Completed  => 'success',
            self::Expired    => 'error',
            self::Voided     => 'error',
        };
    }
}
