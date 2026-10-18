<?php

namespace App\Modules\Assets\Enums;

enum SarsAllowanceSection: string
{
    case S11e  = 's11e';   // general wear & tear
    case S12C  = 's12c';   // manufacturing plant
    case S12E  = 's12e';   // small business assets

    public function label(): string
    {
        return match($this) {
            self::S11e => 'Section 11(e) — Wear & Tear',
            self::S12C => 'Section 12C — Manufacturing Plant',
            self::S12E => 'Section 12E — Small Business',
        };
    }
}
