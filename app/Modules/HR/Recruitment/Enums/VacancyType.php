<?php

namespace App\Modules\HR\Recruitment\Enums;

enum VacancyType: string
{
    case Permanent   = 'permanent';
    case Contract    = 'contract';
    case Temporary   = 'temporary';
    case Internship  = 'internship';
    case Consultancy = 'consultancy';

    public function label(): string
    {
        return match($this) {
            self::Permanent   => 'Permanent',
            self::Contract    => 'Contract',
            self::Temporary   => 'Temporary',
            self::Internship  => 'Internship',
            self::Consultancy => 'Consultancy',
        };
    }
}
