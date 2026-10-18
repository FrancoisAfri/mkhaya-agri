<?php

namespace App\Modules\HR\Recruitment\Contracts;

/**
 * A source that discovers PUBLIC professional items about a candidate (LinkedIn,
 * GitHub, general web, ...). Real integrations require the candidate's consent,
 * authorized API access and legal review before they may return live data.
 */
interface ProfileDiscoveryProvider
{
    /**
     * @param array $candidate {full_name, email?, linkedin_url?, ...}
     *
     * @return array<int, array{source: string, url: string, text: string, confidence: float, posted_at: ?string}>
     */
    public function discover(array $candidate): array;
}
