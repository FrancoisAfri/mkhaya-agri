<?php

namespace App\Modules\HR\Recruitment\Services\Publishers;

use App\Modules\HR\Recruitment\Contracts\SocialPublisher;
use App\Modules\HR\Recruitment\Models\SocialAccount;
use App\Modules\HR\Recruitment\Models\SocialPost;
use App\Modules\HR\Recruitment\Models\Vacancy;

/**
 * Base for a provider that genuinely has no integration.
 *
 * Facebook and LinkedIn used to sit on this class, which made the module look as though it
 * could publish when every attempt recorded a failure. They are real now; what is left
 * here is for a provider that cannot be reached by posting text, and it says why rather
 * than just "not yet configured".
 */
abstract class NotConfiguredPublisher implements SocialPublisher
{
    abstract protected function providerName(): string;

    /** Why this one cannot publish, in words a recruiter can act on. */
    protected function reason(): string
    {
        return "{$this->providerName()} publishing has not been set up on this installation.";
    }

    public function publish(Vacancy $vacancy, SocialAccount $account): SocialPost
    {
        return SocialPost::create([
            'vacancy_id'        => $vacancy->id,
            'social_account_id' => $account->id,
            'status'            => 'failed',
            'error_message'     => $this->reason(),
        ]);
    }
}
