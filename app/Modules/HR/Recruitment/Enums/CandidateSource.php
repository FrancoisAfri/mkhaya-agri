<?php

namespace App\Modules\HR\Recruitment\Enums;

enum CandidateSource: string
{
    case Internal     = 'internal';
    case External     = 'external';
    case LinkedIn     = 'linkedin';
    case Indeed       = 'indeed';
    case Referral     = 'referral';
    case CareerPortal = 'career_portal';
    case Agency       = 'agency';
    case Direct       = 'direct';
    case CareerFair   = 'career_fair';
    case SocialMedia  = 'social_media';

    public function label(): string
    {
        return match($this) {
            self::Internal     => 'Internal',
            self::External     => 'External',
            self::LinkedIn     => 'LinkedIn',
            self::Indeed       => 'Indeed',
            self::Referral     => 'Referral',
            self::CareerPortal => 'Career Portal',
            self::Agency       => 'Agency',
            self::Direct       => 'Direct',
            self::CareerFair   => 'Career Fair',
            self::SocialMedia  => 'Social Media',
        };
    }
}
