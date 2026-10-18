<?php

namespace App\Modules\Operations\ProjectManagement\Services;

use App\Modules\Operations\ProjectManagement\Models\EvidenceDeadLetter;
use App\Modules\Operations\ProjectManagement\Models\EvidenceEvent;

/**
 * What happened to one submission.
 *
 * Three outcomes, kept distinct because a client must treat them differently: accepted means stop
 * retrying, duplicate means stop retrying *and* the earlier one already counts, rejected means the
 * record is being held and needs a person. Collapsing duplicate into accepted would be simpler and
 * would hide a device double-sending; collapsing rejected into a failure would invite a retry loop
 * that never succeeds.
 */
final class IngestResult
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $status,
        public readonly ?EvidenceEvent $event = null,
        public readonly ?EvidenceDeadLetter $deadLetter = null,
    ) {
    }

    public static function accepted(EvidenceEvent $event): self
    {
        return new self(self::ACCEPTED, $event);
    }

    public static function duplicate(EvidenceEvent $existing): self
    {
        return new self(self::DUPLICATE, $existing);
    }

    public static function rejected(EvidenceDeadLetter $letter): self
    {
        return new self(self::REJECTED, null, $letter);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status'         => $this->status,
            'evidence_id'    => $this->event?->uuid,
            'dead_letter_id' => $this->deadLetter?->uuid,
            'errors'         => $this->deadLetter?->errors,
        ], fn ($v) => $v !== null);
    }
}
