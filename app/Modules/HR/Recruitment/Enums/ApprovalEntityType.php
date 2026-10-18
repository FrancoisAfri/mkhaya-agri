<?php

namespace App\Modules\HR\Recruitment\Enums;

enum ApprovalEntityType: string
{
    case Requisition = 'requisition';
    case Vacancy     = 'vacancy';
    case Offer       = 'offer';
    case Hire        = 'hire';

    public function label(): string
    {
        return match ($this) {
            self::Requisition => 'Requisition',
            self::Vacancy     => 'Vacancy',
            self::Offer       => 'Offer',
            self::Hire        => 'Hire / Final Selection',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
