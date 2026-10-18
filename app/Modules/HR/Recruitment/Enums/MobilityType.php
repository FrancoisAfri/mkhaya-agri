<?php

namespace App\Modules\HR\Recruitment\Enums;

enum MobilityType: string
{
    case Lateral   = 'lateral';
    case Promotion = 'promotion';
    case Transfer  = 'transfer';

    public function label(): string
    {
        return match($this) {
            self::Lateral   => 'Lateral Move',
            self::Promotion => 'Promotion',
            self::Transfer  => 'Transfer',
        };
    }
}
