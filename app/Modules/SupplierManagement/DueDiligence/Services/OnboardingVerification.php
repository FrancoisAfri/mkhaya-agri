<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\VerificationStatus;
use App\Modules\SupplierManagement\Models\Supplier;
use Illuminate\Support\Facades\Log;

/**
 * Check a supplier the moment it registers, rather than whenever somebody gets round to it.
 *
 * Everything needed to verify a supplier existed — the provider registry, the engine, the risk
 * scoring, the case workflow, the nightly sweep — and nothing called any of it at the one moment it
 * matters most. A supplier filled in the registration form, landed in the directory as inactive, and
 * sat there unchecked until a person opened its page and pressed a button, or until the overnight
 * sweep happened to reach it. In between, the buyer's only evidence that the company was real was
 * the company's own word for it.
 *
 * ── What gets checked ───────────────────────────────────────────────────────
 *
 * The checks a registration can actually answer, in the order a human would ask them: does the
 * company exist, is it tax compliant, is the VAT number real, does the bank account belong to it, and
 * is anybody behind it on a sanctions or PEP list. A check is skipped — not failed — when the tenant
 * has no data source configured for it, or when the supplier gave us nothing to check against. Those
 * are different from a check that ran and did not like what it found, and the three are counted
 * separately because "we could not look" must never read as "we looked and it was fine".
 *
 * ── Why it never blocks the registration ────────────────────────────────────
 *
 * The supplier is already saved and already inactive, which is the control that matters: nobody can
 * be paid until a person activates them. Verification informs that decision, so a provider being
 * down must delay it, not lose the registration — the applicant has filled in a long form and would
 * have to do it again. Every failure here is caught and recorded against the supplier.
 */
class OnboardingVerification
{
    /**
     * In the order a person would ask them, which is also roughly cheapest-first.
     *
     * Director and beneficial-ownership checks are deliberately absent: they need a person to check
     * against and the registration form collects directors as free text, so they belong to the first
     * review rather than to the automatic pass.
     */
    public const CHECKS = [
        CheckType::COMPANY_REGISTRY,
        CheckType::TAX_COMPLIANCE,
        CheckType::TAX_VAT_VENDOR,
        CheckType::BANK_ACCOUNT,
        CheckType::SCREENING_SANCTIONS,
        CheckType::SCREENING_PEP,
    ];

    /**
     * The one field each check cannot be run without.
     *
     * Testing for empty claims is not enough: most claim sets carry the company name as context, so
     * a supplier with no VAT number still produces a non-empty claim for the VAT check and we would
     * spend a paid lookup to be told there is nothing to look up.
     */
    private const REQUIRED_CLAIM = [
        CheckType::COMPANY_REGISTRY => 'registration_number',
        CheckType::TAX_COMPLIANCE => 'tax_reference_number',
        CheckType::TAX_VAT_VENDOR => 'vat_number',
        CheckType::BANK_ACCOUNT => 'account_number',
    ];

    public function __construct(
        private readonly VerificationEngine $engine,
        private readonly ProviderRegistry $registry,
        private readonly CaseWorkflow $cases,
    ) {
    }

    /**
     * Which of the checks could actually run for this supplier right now.
     *
     * Two reasons one cannot: the tenant has configured no data source for it, or the supplier gave
     * us nothing to check — no VAT number means the VAT check has nothing to ask about, and asking
     * anyway spends a paid lookup to be told so.
     *
     * @return array{runnable: list<string>, no_source: list<string>, no_data: list<string>}
     */
    public function plan(Supplier $supplier): array
    {
        $runnable = $noSource = $noData = [];

        foreach (self::CHECKS as $check) {
            if (! $this->registry->hasDataSourceFor($check)) {
                $noSource[] = $check;

                continue;
            }

            $claims = $this->engine->claimsFor($supplier, $check);
            $required = self::REQUIRED_CLAIM[$check] ?? null;

            if ($claims === [] || ($required !== null && blank($claims[$required] ?? null))) {
                $noData[] = $check;

                continue;
            }

            $runnable[] = $check;
        }

        return ['runnable' => $runnable, 'no_source' => $noSource, 'no_data' => $noData];
    }

    /**
     * Run them.
     *
     * @return array{checked: int, passed: int, attention: int, inconclusive: int, skipped: int, opened_case: bool}
     */
    public function run(Supplier $supplier): array
    {
        $plan = $this->plan($supplier);
        $passed = $attention = $inconclusive = 0;

        foreach ($plan['runnable'] as $check) {
            try {
                $observation = $this->engine->verify(supplier: $supplier, checkType: $check);
            } catch (\Throwable $e) {
                /*
                 * One provider failing must not stop the rest. A sanctions screen is worth having
                 * even when the registry lookup timed out, and the opposite is just as true.
                 */
                Log::warning('onboarding verification: a check could not be completed', [
                    'supplier' => $supplier->id, 'check' => $check, 'error' => $e->getMessage(),
                ]);
                $inconclusive++;

                continue;
            }

            /*
             * Three outcomes, not two. `needsAttention` deliberately excludes `error` and
             * `unavailable` because neither is the supplier's fault and neither belongs in somebody's
             * queue — but treating them as the opposite of attention would count a provider outage as
             * a clean pass, which is the exact conflation this service exists to avoid. A check that
             * could not complete is its own answer.
             */
            $status = (string) $observation->status;

            if (VerificationStatus::passes($status)) {
                $passed++;
            } elseif (VerificationStatus::needsAttention($status)) {
                $attention++;
            } else {
                $inconclusive++;
            }
        }

        /*
         * Something that needs a human gets a case opened for it, because a verification sitting at
         * `mismatch` on a record nobody has opened is the same as no verification at all. The case is
         * the thing that appears in somebody's queue.
         */
        $openedCase = false;

        if ($attention > 0) {
            try {
                $this->cases->open($supplier);
                $openedCase = true;
            } catch (\Throwable $e) {
                Log::warning('onboarding verification: could not open a case', [
                    'supplier' => $supplier->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $result = [
            'checked' => count($plan['runnable']),
            'passed' => $passed,
            'attention' => $attention,
            // Ran, and told us nothing — a provider that was down or refused. Counted apart from
            // both, because it is neither evidence for the supplier nor against them.
            'inconclusive' => $inconclusive,
            'skipped' => count($plan['no_source']) + count($plan['no_data']),
            'opened_case' => $openedCase,
        ];

        Log::info('onboarding verification completed', $result + [
            'supplier' => $supplier->id,
            'no_source' => $plan['no_source'],
            'no_data' => $plan['no_data'],
        ]);

        return $result;
    }
}
