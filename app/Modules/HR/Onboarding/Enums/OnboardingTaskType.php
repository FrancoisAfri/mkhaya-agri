<?php

namespace App\Modules\HR\Onboarding\Enums;

enum OnboardingTaskType: string
{
    case Meeting              = 'meeting';
    case Training             = 'training';
    case Reading              = 'reading';
    case SystemSetup          = 'system_setup';
    case Introduction         = 'introduction';
    case PolicyAcknowledgement = 'policy_acknowledgement';

    public function label(): string
    {
        return match($this) {
            self::Meeting               => 'Meeting',
            self::Training              => 'Training',
            self::Reading               => 'Reading',
            self::SystemSetup           => 'System Setup',
            self::Introduction          => 'Introduction',
            self::PolicyAcknowledgement => 'Policy Acknowledgement',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::Meeting               => 'mdi-calendar-account',
            self::Training              => 'mdi-school-outline',
            self::Reading               => 'mdi-book-open-outline',
            self::SystemSetup           => 'mdi-monitor-account',
            self::Introduction          => 'mdi-account-multiple-outline',
            self::PolicyAcknowledgement => 'mdi-file-check-outline',
        };
    }
}
