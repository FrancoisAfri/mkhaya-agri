<?php

namespace App\Modules\SupplierManagement\DueDiligence\Data;

use App\Modules\SupplierManagement\Models\Supplier;

/**
 * What the engine hands a provider: which check, about whom, and what the supplier claimed.
 *
 * `claimed` is passed in rather than read off the supplier by the provider, so the comparison is
 * always against the values as they stood when the check ran. A provider that read the supplier
 * itself would compare against whatever the record says now, and a mismatch found in March would
 * quietly heal the moment somebody corrected the typo in April.
 */
class VerificationRequest
{
    /**
     * @param  array<string,mixed>  $claimed  the values to verify, keyed by normalised field name
     * @param  array<string,mixed>  $context  anything provider-specific (a TCS PIN, a bank branch code)
     */
    public function __construct(
        public readonly string $checkType,
        public readonly Supplier $supplier,
        public readonly array $claimed = [],
        public readonly array $context = [],
        public readonly string $subjectType = 'supplier',
        public readonly ?int $subjectId = null,
        public readonly ?string $subjectLabel = null,
        public readonly ?string $reference = null,
    ) {}

    public function claim(string $field, mixed $default = null): mixed
    {
        return $this->claimed[$field] ?? $default;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /** The subject as it should be labelled on the observation — the person, or the company. */
    public function label(): string
    {
        return $this->subjectLabel ?: (string) $this->supplier->company_name;
    }
}
