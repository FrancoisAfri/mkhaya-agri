<?php

namespace App\Modules\HR\Recruitment\Enums;

enum SocialProvider: string
{
    case Linkedin = 'linkedin';
    case Facebook = 'facebook';
    case Indeed   = 'indeed';

    public function label(): string
    {
        return match ($this) {
            self::Linkedin => 'LinkedIn',
            self::Facebook => 'Facebook',
            self::Indeed   => 'Indeed',
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
