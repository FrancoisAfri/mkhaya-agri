<?php

namespace App\Modules\HR\Recruitment\Services\Screening;

/**
 * Discovers public professional items from the general web. Not yet configured —
 * a real integration needs the candidate's consent, an authorized search/data API
 * and legal review. Returns [] until then.
 */
class WebDiscoveryProvider extends NotConfiguredDiscoveryProvider
{
    protected function providerName(): string
    {
        return 'Web';
    }
}
