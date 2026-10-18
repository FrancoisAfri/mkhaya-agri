<?php

namespace App\Modules\SupplierManagement\DueDiligence\Data;

use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;

/**
 * A provider's answer, normalised.
 *
 * Providers return this and nothing else — no writing to the database, no raising of flags. Keeping
 * connectors free of side effects is what makes them safe to add: a new provider cannot corrupt the
 * observation log or skip the evidence ledger, because it never touches either.
 */
class VerificationResult
{
    /**
     * @param  array<string,mixed>  $observed  what the source said, keyed by the same field names as the claim
     * @param  array<string,mixed>|null  $raw   the provider's payload as received, for the evidence ledger
     */
    public function __construct(
        public readonly string $status,
        public readonly array $observed = [],
        public readonly ?string $summary = null,
        public readonly ?int $confidence = null,
        public readonly ?string $reference = null,
        public readonly ?array $raw = null,
        public readonly ?float $cost = null,
    ) {}

    public static function verified(array $observed, ?string $summary = null, ?array $raw = null, ?float $cost = null): self
    {
        return new self(VerificationStatus::VERIFIED, $observed, $summary, 100, null, $raw, $cost);
    }

    public static function mismatch(array $observed, string $summary, ?array $raw = null, ?float $cost = null): self
    {
        return new self(VerificationStatus::MISMATCH, $observed, $summary, null, null, $raw, $cost);
    }

    public static function notFound(string $summary, ?array $raw = null, ?float $cost = null): self
    {
        return new self(VerificationStatus::NOT_FOUND, [], $summary, null, null, $raw, $cost);
    }

    /**
     * No provider, no credentials, or the provider could not be reached. The reason is required:
     * an unavailable check is shown to a human who has to decide what to do about it, and "provider
     * unavailable" with no explanation gives them nothing to act on.
     */
    public static function unavailable(string $reason): self
    {
        return new self(VerificationStatus::UNAVAILABLE, [], $reason);
    }

    public static function manualReview(string $summary, array $observed = [], ?array $raw = null): self
    {
        return new self(VerificationStatus::MANUAL_REVIEW, $observed, $summary, null, null, $raw);
    }

    public static function error(string $summary, ?array $raw = null): self
    {
        return new self(VerificationStatus::ERROR, [], $summary, null, null, $raw);
    }

    public function passed(): bool
    {
        return VerificationStatus::passes($this->status);
    }
}
