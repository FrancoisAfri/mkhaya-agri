<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\CheckType;
use App\Modules\SupplierManagement\DueDiligence\Support\RiskModel;
use App\Modules\SupplierManagement\Models\Supplier;

/**
 * Decides how much diligence an engagement warrants, before any of it is done.
 *
 * The tier is not the score. The score is what the checks found; the tier is how hard you should
 * have looked, and it is decided from what the engagement *is* — spend, access, sector — not from
 * anything about the supplier's conduct.
 *
 * Keeping them apart is what makes the process survivable at volume. A stationery supplier and a
 * supplier with access to payroll data cannot walk the same path: insist on it and the people doing
 * the work will route around the system within a month, which leaves you with a due-diligence
 * module and no due diligence.
 *
 * Tier is per engagement, not per company. The same supplier can be tier 1 for paper and tier 4 for
 * a payroll integration, and a system that stores one tier per company will eventually record the
 * paper one.
 */
class RiskTiering
{
    /**
     * Work out the tier from the answers given about the engagement.
     *
     * @param  array<string,mixed>  $factors  keys from RiskModel::tierFactors(), plus `annual_spend`
     *                                        and `contract_value` as numbers
     * @return array{tier:int, label:string, reasons:array<int,string>, required_checks:array<int,string>}
     */
    public function assess(array $factors, float $spendThreshold = 1_000_000, float $contractThreshold = 500_000): array
    {
        $tier = 1;
        $reasons = [];
        $definitions = RiskModel::tierFactors();

        // Money first: the two thresholds are the only factors expressed as amounts rather than
        // yes/no, so they are turned into the same kind of answer before anything else runs.
        $derived = $factors;

        if (($factors['annual_spend'] ?? 0) >= $spendThreshold) {
            $derived['annual_spend_over'] = true;
        }

        if (($factors['contract_value'] ?? 0) >= $contractThreshold) {
            $derived['contract_value_over'] = true;
        }

        foreach ($definitions as $key => $definition) {
            if (empty($derived[$key])) {
                continue;
            }

            if ($definition['tier'] > $tier) {
                $tier = $definition['tier'];
            }

            $reasons[] = $definition['label'];
        }

        // A supplier that is neither trivial nor exceptional is tier 2 — the ordinary commercial
        // case. Tier 1 is reserved for engagements where the answer to every question was no.
        if ($tier === 1 && $reasons === [] && (($factors['annual_spend'] ?? 0) > 0 || ($factors['contract_value'] ?? 0) > 0)) {
            $tier = 2;
            $reasons[] = 'Ordinary commercial engagement.';
        }

        return [
            'tier' => $tier,
            'label' => RiskModel::tiers()[$tier]['label'] ?? "Tier {$tier}",
            'reasons' => $reasons,
            'required_checks' => $this->requiredChecks($tier),
        ];
    }

    /** Record the tier on the supplier, with the answers that produced it. */
    public function apply(Supplier $supplier, array $factors): array
    {
        $result = $this->assess($factors);

        $supplier->forceFill([
            'risk_tier' => $result['tier'],
            'tier_factors' => $factors + ['assessed_at' => now()->toDateTimeString(), 'reasons' => $result['reasons']],
        ])->saveQuietly();

        return $result;
    }

    /**
     * What this tier has to have before approval.
     *
     * Cumulative: each tier is everything below it plus more. Expressed as check types so the case
     * workflow can ask "what is still outstanding" without knowing anything about tiers.
     *
     * @return array<int,string>
     */
    public function requiredChecks(int $tier): array
    {
        $basic = [
            CheckType::COMPANY_REGISTRY,
            CheckType::BANK_ACCOUNT,
            CheckType::TAX_COMPLIANCE,
        ];

        $standard = [...$basic, CheckType::COMPANY_DIRECTORS, CheckType::SCREENING_SANCTIONS];

        $elevated = [...$standard, CheckType::OWNERSHIP_BENEFICIAL, CheckType::SCREENING_PEP, CheckType::CREDIT_REPORT];

        $critical = [...$elevated, CheckType::MEDIA_ADVERSE, CheckType::CYBER_POSTURE, CheckType::GOVERNMENT_CSD];

        return match (true) {
            $tier >= 4 => $critical,
            $tier === 3 => $elevated,
            $tier === 2 => $standard,
            default => $basic,
        };
    }

    /**
     * Checks this tier requires that have not been answered yet.
     *
     * @return array<int,string>
     */
    public function outstandingChecks(Supplier $supplier): array
    {
        $current = \App\Modules\SupplierManagement\Models\SupplierVerification::currentFor($supplier->id);

        return array_values(array_filter(
            $this->requiredChecks((int) ($supplier->risk_tier ?: 2)),
            fn (string $check) => ! ($current->get($check)?->passed() ?? false)
        ));
    }
}
