<?php

namespace App\Modules\Assets\Enums;

enum DepreciationMethod: string
{
    case StraightLine     = 'straight_line';
    case ReducingBalance  = 'reducing_balance';
    case UnitsOfProduction = 'units_of_production';
    case SarsWearAndTear  = 'sars_wear_and_tear';

    public function label(): string
    {
        return match($this) {
            self::StraightLine      => 'Straight Line (IFRS)',
            self::ReducingBalance   => 'Reducing Balance',
            self::UnitsOfProduction => 'Units of Production',
            self::SarsWearAndTear   => 'SARS s11(e) Wear & Tear',
        };
    }
}
