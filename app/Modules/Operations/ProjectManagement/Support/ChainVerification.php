<?php

namespace App\Modules\Operations\ProjectManagement\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The result of auditing one project's evidence chain.
 *
 * A broken chain names the first event where the break was detected and says what was wrong, so an
 * auditor has somewhere to start rather than a boolean. It stops at the first break by design: once
 * a link is broken, everything after it is unverifiable and reporting a hundred consequential
 * failures would bury the one that matters.
 */
final class ChainVerification implements Arrayable
{
    private function __construct(
        public readonly bool $intact,
        public readonly int $eventsChecked,
        public readonly ?string $brokenAtUuid = null,
        public readonly ?string $reason = null,
    ) {
    }

    public static function intact(int $eventsChecked): self
    {
        return new self(true, $eventsChecked);
    }

    public static function broken(int $eventsChecked, string $uuid, string $reason): self
    {
        return new self(false, $eventsChecked, $uuid, $reason);
    }

    public function toArray(): array
    {
        return [
            'intact'         => $this->intact,
            'events_checked' => $this->eventsChecked,
            'broken_at'      => $this->brokenAtUuid,
            'reason'         => $this->reason,
        ];
    }
}
