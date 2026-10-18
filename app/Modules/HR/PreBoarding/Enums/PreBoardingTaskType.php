<?php

namespace App\Modules\HR\PreBoarding\Enums;

enum PreBoardingTaskType: string
{
    case DocumentSubmission = 'document_submission';
    case FormCompletion     = 'form_completion';
    case SystemAccess       = 'system_access';
    case BackgroundCheck    = 'background_check';
    case WelcomePack        = 'welcome_pack';

    public function label(): string
    {
        return match($this) {
            self::DocumentSubmission => 'Document Submission',
            self::FormCompletion     => 'Form Completion',
            self::SystemAccess       => 'System Access',
            self::BackgroundCheck    => 'Background Check',
            self::WelcomePack        => 'Welcome Pack',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::DocumentSubmission => 'mdi-file-document-outline',
            self::FormCompletion     => 'mdi-form-select',
            self::SystemAccess       => 'mdi-key-outline',
            self::BackgroundCheck    => 'mdi-shield-check-outline',
            self::WelcomePack        => 'mdi-gift-outline',
        };
    }
}
