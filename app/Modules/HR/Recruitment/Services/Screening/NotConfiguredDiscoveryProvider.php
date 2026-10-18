<?php

namespace App\Modules\HR\Recruitment\Services\Screening;

use App\Modules\HR\Recruitment\Contracts\ProfileDiscoveryProvider;

/**
 * Base discovery provider used by every source until its real integration ships.
 * Returns an empty list rather than throwing, so screening degrades gracefully to
 * "no public data found".
 *
 * A real integration MUST NOT simply scrape the public web. Before returning live
 * data it requires: the candidate's explicit consent, authorized/official API
 * access for the source, and legal/POPIA review of what may be collected and
 * retained. Protected characteristics must never be gathered.
 */
abstract class NotConfiguredDiscoveryProvider implements ProfileDiscoveryProvider
{
    abstract protected function providerName(): string;

    /**
     * @return array<int, array{source: string, url: string, text: string, confidence: float, posted_at: ?string}>
     */
    public function discover(array $candidate): array
    {
        return [];
    }
}
