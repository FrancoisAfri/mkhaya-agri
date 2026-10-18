<?php

namespace App\Modules\SupplierManagement\DueDiligence\Contracts;

use App\Modules\SupplierManagement\DueDiligence\Data\ProviderHealth;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;

/**
 * A source that can answer a due-diligence question.
 *
 * Deliberately the same shape as `Tenancy\Payments\GatewayContract`, which has carried three real
 * payment providers without the checkout code learning any of their names. The supplier module asks
 * for `company.registry`; who answers it is configuration.
 *
 * Two rules for implementors:
 *
 *  - No side effects. Do not write observations, raise flags or touch the supplier. Return a
 *    VerificationResult and let the engine record it — that is what guarantees every check, from
 *    every provider, lands in the evidence ledger.
 *  - Never invent an answer. If credentials are missing, the endpoint is down, or the payload cannot
 *    be understood, return `unavailable` or `error` with a reason. A fabricated pass is the one
 *    failure mode this whole subsystem exists to prevent.
 */
interface VerificationProvider
{
    /** The provider's catalogue code — 'cipc', 'manual', … */
    public function code(): string;

    /**
     * The check types this provider can answer for the current tenant's configuration.
     *
     * @return array<int,string> CheckType constants
     */
    public function capabilities(): array;

    public function supports(string $checkType): bool;

    public function verify(VerificationRequest $request): VerificationResult;

    /** Can this provider be used right now? Must not throw. */
    public function health(): ProviderHealth;
}
