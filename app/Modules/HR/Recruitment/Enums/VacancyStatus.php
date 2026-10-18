<?php

namespace App\Modules\HR\Recruitment\Enums;

enum VacancyStatus: string
{
    case Draft        = 'draft';
    case Published    = 'published';
    case InternalOnly = 'internal_only';
    case External     = 'external';
    case Closed       = 'closed';
    case Expired      = 'expired';
    case OnHold       = 'on_hold';
    case Filled       = 'filled';

    public function label(): string
    {
        return match($this) {
            self::Draft        => 'Draft',
            self::Published    => 'Published',
            self::InternalOnly => 'Internal Only',
            self::External     => 'External',
            self::Closed       => 'Closed',
            self::Expired      => 'Expired',
            self::OnHold       => 'On Hold',
            self::Filled       => 'Filled',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Draft        => 'default',
            self::Published    => 'success',
            self::InternalOnly => 'info',
            self::External     => 'primary',
            self::Closed       => 'secondary',
            self::Expired      => 'secondary',
            self::OnHold       => 'warning',
            self::Filled       => 'success',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [
            self::Published,
            self::InternalOnly,
            self::External,
        ]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Closed,
            self::Expired,
            self::Filled,
        ]);
    }
}
