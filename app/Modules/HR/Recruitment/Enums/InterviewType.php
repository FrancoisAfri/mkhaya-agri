<?php

namespace App\Modules\HR\Recruitment\Enums;

enum InterviewType: string
{
    case Technical    = 'technical';
    case Behavioural  = 'behavioural';
    case Leadership   = 'leadership';
    case Cultural     = 'cultural';
    case Panel        = 'panel';
    case Final        = 'final';

    public function label(): string
    {
        return match($this) {
            self::Technical   => 'Technical',
            self::Behavioural => 'Behavioural',
            self::Leadership  => 'Leadership',
            self::Cultural    => 'Cultural Fit',
            self::Panel       => 'Panel',
            self::Final       => 'Final',
        };
    }
}
