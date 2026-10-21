<?php

namespace App\Modules\Operations\Manufacturing\Models\Agriculture;

/** A registered source of outside information, with how much it is trusted and whether it is current. */
class Source extends ProductionModel
{
    protected $table = 'prd_sources';

    protected $casts = ['last_attempt_at' => 'datetime', 'last_success_at' => 'datetime', 'next_attempt_at' => 'datetime'];

    /** Whether the last good fetch is older than twice the refresh interval. */
    public function isStale(?\Carbon\CarbonInterface $now = null): bool
    {
        return $this->last_success_at === null || $this->last_success_at->lt(($now ?? now())->copy()->subMinutes(2 * max(1, (int) $this->refresh_minutes)));
    }
}
