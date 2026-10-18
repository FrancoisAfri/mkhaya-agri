<?php

namespace App\Modules\HR\Recruitment\Enums;

enum VacancyVisibility: string
{
    case InternalOnly               = 'internal_only';
    case ExternalOnly               = 'external_only';
    case InternalFirstThenExternal  = 'internal_first_then_external';

    public function label(): string
    {
        return match($this) {
            self::InternalOnly              => 'Internal Only',
            self::ExternalOnly              => 'External Only',
            self::InternalFirstThenExternal => 'Internal First, Then External',
        };
    }

    public function description(): string
    {
        return match($this) {
            self::InternalOnly              => 'Visible only to current employees. External candidates cannot apply.',
            self::ExternalOnly              => 'Published to external job portals and public application URL only.',
            self::InternalFirstThenExternal => 'First advertised internally. After the internal deadline, it opens to external candidates.',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::InternalOnly              => 'info',
            self::ExternalOnly              => 'primary',
            self::InternalFirstThenExternal => 'warning',
        };
    }
}
