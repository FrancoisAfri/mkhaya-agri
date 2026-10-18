<?php

namespace App\Modules\HR\Recruitment\Services\Screening;

use App\Modules\HR\Recruitment\Contracts\ProfileDiscoveryProvider;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fans a candidate out across every registered discovery provider and merges the
 * PUBLIC professional items they return. Each provider is isolated: a failing one
 * is logged and skipped so screening still proceeds with whatever was found.
 */
class ProfileDiscoveryManager
{
    /**
     * @return array<int, ProfileDiscoveryProvider>
     */
    public function all(): array
    {
        return [
            app(LinkedinDiscoveryProvider::class),
            app(GithubDiscoveryProvider::class),
            app(WebDiscoveryProvider::class),
        ];
    }

    /**
     * @param array $candidate {full_name, email?, linkedin_url?, ...}
     *
     * @return array<int, array{source: string, url: string, text: string, confidence: float, posted_at: ?string}>
     */
    public function gather(array $candidate): array
    {
        $items = [];

        foreach ($this->all() as $provider) {
            try {
                foreach ($provider->discover($candidate) as $item) {
                    $items[] = $item;
                }
            } catch (Throwable $e) {
                Log::warning('ProfileDiscoveryManager: provider ' . $provider::class . ' failed: ' . $e->getMessage());
            }
        }

        return $items;
    }
}
