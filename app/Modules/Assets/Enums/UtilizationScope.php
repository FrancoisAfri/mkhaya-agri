<?php

namespace App\Modules\Assets\Enums;

enum UtilizationScope: string
{
    case All      = 'all';
    case Category = 'category';
    case Location = 'location';
    case Single   = 'single';

    public function label(): string
    {
        return match($this) {
            self::All      => 'All Assets',
            self::Category => 'By Category',
            self::Location => 'By Location',
            self::Single   => 'Single Asset',
        };
    }
}
