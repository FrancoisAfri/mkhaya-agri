<?php

namespace App\Modules\HR\Recruitment\Services\Screening;

/**
 * Discovers public professional items from LinkedIn. Not yet configured — a real
 * integration needs the candidate's consent, LinkedIn's authorized API and legal
 * review. Returns [] until then.
 */
class LinkedinDiscoveryProvider extends NotConfiguredDiscoveryProvider
{
    protected function providerName(): string
    {
        return 'LinkedIn';
    }
}
