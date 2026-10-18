<?php

namespace App\Modules\Assets\Enums;

enum ApprovalType: string
{
    case Assignment  = 'assignment';
    case Transfer    = 'transfer';
    case Disposal    = 'disposal';
    case Maintenance = 'maintenance';
    case Procurement = 'procurement';

    public function label(): string
    {
        return match($this) {
            self::Assignment  => 'Asset Assignment',
            self::Transfer    => 'Asset Transfer',
            self::Disposal    => 'Asset Disposal',
            self::Maintenance => 'Maintenance Request',
            self::Procurement => 'Asset Procurement',
        };
    }
}
