<?php

namespace App\Modules\SupplierManagement\DueDiligence\Providers;

use App\Modules\SupplierManagement\DueDiligence\Contracts\VerificationProvider;
use App\Modules\SupplierManagement\DueDiligence\Data\ProviderHealth;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;

/**
 * A person did the check themselves and is recording what they found.
 *
 * This is the provider that makes the whole framework useful on day one, before a single integration
 * is licensed. Somebody logs into CIPC, looks the company up, and records the outcome here — and it
 * lands in the same observation log, with the same evidence entry, the same freshness horizon and the
 * same effect on approval gates as an automated pull would.
 *
 * The difference is recorded honestly rather than hidden: the observation carries `manual` as its
 * provider and the name of the person who looked, so nobody later mistakes a typed-in answer for a
 * machine-verified one.
 */
class ManualProvider implements VerificationProvider
{
    public function code(): string
    {
        return 'manual';
    }

    /** A person can perform any check that exists. */
    public function capabilities(): array
    {
        return array_keys(CheckType::all());
    }

    public function supports(string $checkType): bool
    {
        return CheckType::exists($checkType);
    }

    /**
     * The outcome is supplied by the person, not discovered here. `observed` is whatever they read
     * off the source, so the field-level comparison the engine runs afterwards is exactly the same
     * comparison it would run on an API response.
     */
    public function verify(VerificationRequest $request): VerificationResult
    {
        $status = (string) $request->option('status', VerificationStatus::MANUAL_REVIEW);

        if (! in_array($status, VerificationStatus::ALL, true)) {
            return VerificationResult::error("\"{$status}\" is not a verification outcome.");
        }

        $observed = (array) $request->option('observed', []);
        $note = $request->option('note');

        return new VerificationResult(
            status: $status,
            observed: $observed,
            summary: $note ? (string) $note : $this->defaultSummary($status),
            // A person looking at a registry is not expressing a probability, so no confidence is
            // claimed on their behalf — an invented 100% would read as machine certainty.
            confidence: null,
            reference: $request->reference,
            raw: ['recorded_manually' => true, 'observed' => $observed, 'note' => $note],
            cost: null,
        );
    }

    public function health(): ProviderHealth
    {
        return ProviderHealth::ok('Checks are recorded by a person; no connection required.');
    }

    private function defaultSummary(string $status): string
    {
        return match ($status) {
            VerificationStatus::VERIFIED => 'Confirmed against the source by a person.',
            VerificationStatus::MISMATCH => 'A person found the source disagrees with the supplier.',
            VerificationStatus::NOT_FOUND => 'A person could not find this subject at the source.',
            VerificationStatus::UNAVAILABLE => 'A person could not reach the source.',
            default => 'Recorded by a person for review.',
        };
    }
}
