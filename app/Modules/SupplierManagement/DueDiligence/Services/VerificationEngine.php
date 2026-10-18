<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Data\VerificationRequest;
use App\Modules\SupplierManagement\DueDiligence\Data\VerificationResult;
use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierVerification;
use App\Modules\SupplierManagement\Models\TenantVerificationProvider;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one way a check gets run.
 *
 * Everything a caller needs is `verify($supplier, $checkType)`. Behind that: pick the provider, ask
 * it, compare its answer field-by-field against what the supplier claimed, store the observation,
 * chain the evidence, and record whether the provider worked. Controllers never touch providers
 * directly — that is what guarantees no check can happen without leaving a trail.
 *
 * Three behaviours are deliberate and worth stating, because each one is a way this could quietly go
 * wrong:
 *
 *  - No provider means UNAVAILABLE, recorded. Not skipped, not treated as clean. A gap in the
 *    Passport is the mechanism by which somebody notices and closes it.
 *  - A provider that throws is caught and recorded as an error. A due-diligence check must not be
 *    able to 500 the page that requested it.
 *  - VERIFIED survives the comparison only if the comparison agrees. A provider returning "here is
 *    the company" while the registry name differs from the supplier's is a MISMATCH, whatever the
 *    provider called it.
 */
class VerificationEngine
{
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly FieldComparator $comparator,
        private readonly EvidenceLedger $ledger,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * Run a check and record it.
     *
     * @param  array<string,mixed>  $claimed  what to verify; defaults to the supplier's own fields
     * @param  array<string,mixed>  $context  provider options (a TCS PIN, a manual outcome)
     */
    public function verify(
        Supplier $supplier,
        string $checkType,
        array $claimed = [],
        array $context = [],
        string $subjectType = 'supplier',
        ?int $subjectId = null,
        ?string $subjectLabel = null,
        ?string $reference = null,
        ?string $providerCode = null,
    ): SupplierVerification {
        if (! CheckType::exists($checkType)) {
            throw new \InvalidArgumentException("Unknown due-diligence check type: {$checkType}");
        }

        $request = new VerificationRequest(
            checkType: $checkType,
            supplier: $supplier,
            claimed: $claimed !== [] ? $claimed : $this->claimsFor($supplier, $checkType),
            context: $context,
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $subjectLabel,
            reference: $reference,
        );

        $provider = $providerCode
            ? $this->registry->make($providerCode)
            : $this->registry->forCheck($checkType);

        if (! $provider || ! $provider->supports($checkType)) {
            return $this->record($request, 'none', VerificationResult::unavailable(
                'No source is configured for '.CheckType::label($checkType).'. Configure a provider, or record the check manually.'
            ));
        }

        $config = TenantVerificationProvider::query()->where('provider_code', $provider->code())->first();

        if ($config && $config->quotaExhausted()) {
            return $this->record($request, $provider->code(), VerificationResult::unavailable(
                "This month's allowance for {$provider->code()} is used up, so no call was made."
            ));
        }

        try {
            $result = $provider->verify($request);
            $config?->recordCall();
            $this->noteProviderOutcome($config, $result);
        } catch (\Throwable $e) {
            report($e);
            $config?->recordFailure($e->getMessage());
            $result = VerificationResult::error(Str::limit($e->getMessage(), 200, ''));
        }

        return $this->record($request, $provider->code(), $result);
    }

    /**
     * Record a check a person performed themselves.
     *
     * A thin wrapper on purpose: manual checks must not be a second path into the observation log
     * with its own rules, or they will drift out of agreement with automated ones.
     */
    public function recordManual(
        Supplier $supplier,
        string $checkType,
        string $status,
        array $observed = [],
        ?string $note = null,
        ?string $reference = null,
        array $claimed = [],
        string $subjectType = 'supplier',
        ?int $subjectId = null,
        ?string $subjectLabel = null,
        ?array $comparison = null,
    ): SupplierVerification {
        return $this->verify(
            supplier: $supplier,
            checkType: $checkType,
            claimed: $claimed,
            context: array_filter([
                'status' => $status,
                'observed' => $observed,
                'note' => $note,
                // A domain comparison the caller already made — see record().
                'comparison' => $comparison,
            ], fn ($value) => $value !== null),
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $subjectLabel,
            reference: $reference,
            providerCode: 'manual',
        );
    }

    /**
     * What the supplier has told us, for the fields a given check can speak to.
     *
     * Read here rather than inside providers so the comparison is always against the values as they
     * stood when the check ran — a mismatch found today must not heal itself when somebody edits the
     * supplier tomorrow.
     */
    public function claimsFor(Supplier $supplier, string $checkType): array
    {
        return match ($checkType) {
            CheckType::COMPANY_REGISTRY => array_filter([
                'company_name' => $supplier->company_name,
                'registration_number' => $supplier->company_registration_number,
                'entity_type' => $supplier->business_entity_type,
            ], fn ($v) => $v !== null && $v !== ''),

            CheckType::COMPANY_ADDRESS => array_filter([
                'registered_address' => $supplier->physical_address,
            ], fn ($v) => $v !== null && $v !== ''),

            CheckType::TAX_COMPLIANCE => array_filter([
                'tax_reference_number' => $supplier->tax_reference_number,
                'company_name' => $supplier->company_name,
            ], fn ($v) => $v !== null && $v !== ''),

            CheckType::TAX_VAT_VENDOR => array_filter([
                'vat_number' => $supplier->vat_registration_number,
                'company_name' => $supplier->company_name,
            ], fn ($v) => $v !== null && $v !== ''),

            CheckType::BANK_ACCOUNT => array_filter([
                'account_holder_name' => $supplier->account_holder_name ?: $supplier->company_name,
                'account_number' => $supplier->bank_account_number,
                'bank_name' => $supplier->bank_name,
                'branch_code' => $supplier->branch_code,
                'account_type' => $supplier->account_type,
            ], fn ($v) => $v !== null && $v !== ''),

            CheckType::BBBEE_CERTIFICATE => array_filter([
                'company_name' => $supplier->company_name,
                'bbbee_level' => $supplier->bbbee_status_level,
            ], fn ($v) => $v !== null && $v !== ''),

            CheckType::GOVERNMENT_CSD => array_filter([
                'company_name' => $supplier->company_name,
                'registration_number' => $supplier->company_registration_number,
            ], fn ($v) => $v !== null && $v !== ''),

            default => array_filter([
                'company_name' => $supplier->company_name,
                'registration_number' => $supplier->company_registration_number,
            ], fn ($v) => $v !== null && $v !== ''),
        };
    }

    /** Persist the observation and its evidence entry as one unit. */
    private function record(VerificationRequest $request, string $providerCode, VerificationResult $result): SupplierVerification
    {
        // Some checks are not field-by-field. Ownership is a list whose order means nothing, so
        // comparing it needs set logic the generic comparator cannot express — and letting the
        // comparator loose on "owner: John Smith => 60%" would compare two percentages as if they
        // were names and overrule a correct answer. Where a caller has done that comparison itself
        // it supplies the result, and its verdict stands.
        //
        // Safe to trust because `context` is built by whoever calls the engine, never by a provider:
        // providers only read it. A connector cannot use this to skip being checked.
        $suppliedComparison = $request->option('comparison');

        $comparison = is_array($suppliedComparison)
            ? $suppliedComparison
            : $this->comparator->compare($request->claimed, $result->observed);

        $status = is_array($suppliedComparison)
            ? $result->status
            : $this->reconcile($result, $comparison);

        $summary = $this->summarise($result, $comparison, $status);

        return DB::transaction(function () use ($request, $providerCode, $result, $comparison, $status, $summary) {
            $observation = SupplierVerification::create([
                'supplier_id' => $request->supplier->id,
                'subject_type' => $request->subjectType,
                'subject_id' => $request->subjectId,
                'subject_label' => Str::limit($request->label(), 185, ''),
                'check_type' => $request->checkType,
                'provider_code' => $providerCode,
                'status' => $status,
                'confidence' => $result->confidence,
                'claimed' => $request->claimed,
                'observed' => $result->observed,
                'field_matches' => $comparison,
                'reference' => $result->reference ?? $request->reference,
                'summary' => Str::limit($summary, 490, ''),
                'note' => $request->option('note'),
                'cost' => $result->cost,
                'checked_by' => Auth::id(),
                'checked_at' => Carbon::now(),
                'valid_until' => $this->validUntil($request->checkType, $status),
            ]);

            $companyId = $observation->company_id ?? $this->companyId();

            if ($companyId) {
                $this->ledger->record($companyId, [
                    'action' => 'verification.recorded',
                    'supplier_id' => $request->supplier->id,
                    'subject_type' => $request->subjectType,
                    'subject_id' => $request->subjectId,
                    'check_type' => $request->checkType,
                    'provider_code' => $providerCode,
                    'status' => $status,
                    'summary' => CheckType::label($request->checkType).": {$status} — ".Str::limit($summary, 300, ''),
                    'claimed' => $request->claimed,
                    'observed' => $result->observed,
                    'payload' => $result->raw,
                ]);
            }

            return $observation;
        });
    }

    /**
     * The provider says what it found; the comparison says whether that agrees with the claim.
     *
     * A provider answering "verified" about a company whose registered name is not the name the
     * supplier gave us has not verified the supplier — it has found a different company. Only the
     * comparison can catch that, so it gets the last word.
     */
    private function reconcile(VerificationResult $result, array $comparison): string
    {
        if ($result->status !== VerificationStatus::VERIFIED) {
            return $result->status;
        }

        if (! $this->comparator->allMatched($comparison)) {
            return VerificationStatus::MISMATCH;
        }

        // Close-but-not-equal is never silently accepted: somebody confirms it is the same entity.
        return $this->comparator->partials($comparison) !== []
            ? VerificationStatus::MANUAL_REVIEW
            : VerificationStatus::VERIFIED;
    }

    private function summarise(VerificationResult $result, array $comparison, string $status): string
    {
        if ($status === VerificationStatus::MISMATCH) {
            $fields = array_keys(array_filter($comparison, fn ($f) => ($f['match'] ?? null) === false));

            if ($fields !== []) {
                $detail = collect($fields)->map(function ($field) use ($comparison) {
                    $claimed = $comparison[$field]['claimed'] ?? '—';
                    $observed = $comparison[$field]['observed'] ?? '—';

                    return str_replace('_', ' ', $field).": supplier says \"{$claimed}\", source says \"{$observed}\"";
                })->implode('; ');

                return 'Does not match the source — '.$detail.'.';
            }
        }

        if ($status === VerificationStatus::MANUAL_REVIEW && $this->comparator->partials($comparison) !== []) {
            $notes = collect($this->comparator->partials($comparison))
                ->map(fn ($field) => $comparison[$field]['note'] ?? str_replace('_', ' ', $field))
                ->implode(' ');

            return 'Needs a person to confirm — '.$notes;
        }

        return $result->summary ?? ucfirst($status);
    }

    /** When this answer goes stale, so a Passport tile can say "checked 41 days ago" and mean it. */
    private function validUntil(string $checkType, string $status): ?Carbon
    {
        if (! VerificationStatus::passes($status)) {
            return null;   // Only a pass has a freshness horizon; a failure does not expire into one.
        }

        $days = CheckType::validDays($checkType);

        return $days === null ? null : Carbon::now()->addDays($days);
    }

    private function noteProviderOutcome(?TenantVerificationProvider $config, VerificationResult $result): void
    {
        if (! $config) {
            return;
        }

        // UNAVAILABLE is a configuration problem rather than a provider failure, so it does not mark
        // the connector as failing — the registry screen would otherwise show every unconfigured
        // check as a broken integration.
        match ($result->status) {
            VerificationStatus::ERROR => $config->recordFailure($result->summary ?? 'Provider error.'),
            VerificationStatus::UNAVAILABLE => null,
            default => $config->recordSuccess(),
        };
    }

    private function companyId(): ?int
    {
        return $this->currentCompany->id() ?? Auth::user()?->company_id;
    }
}
