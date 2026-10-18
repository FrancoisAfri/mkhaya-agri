<?php

namespace App\Modules\HR\Recruitment\Services\Screening;

/**
 * Discovers public professional items from GitHub. Not yet configured — a real
 * integration needs the candidate's consent, GitHub's authorized API and legal
 * review. Returns [] until then.
 */
class GithubDiscoveryProvider extends NotConfiguredDiscoveryProvider
{
    protected function providerName(): string
    {
        return 'GitHub';
    }
}
