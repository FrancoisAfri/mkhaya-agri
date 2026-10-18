<?php

namespace App\Modules\SupplierManagement\DueDiligence\Support;

/**
 * The shape of the risk model: which components exist, what they are worth, and — the part that
 * decides whether this system is honest — what a component with no data is worth.
 *
 * That last question is the whole design. A supplier nobody has checked scores zero on every
 * component if absence is treated as absence of risk, and comes out "excellent". Every system that
 * has ever waved through a fraudulent supplier did it this way. So each component declares what
 * missing data means for it, and for the ones that matter it means risk, not silence:
 *
 *   risk     no data is a bad answer — you do not know who this company is
 *   neutral  no data is genuinely uninformative — a brand-new supplier has no delivery record, and
 *            holding that against them is noise, not diligence
 *
 * Weights are defaults. They are per-tenant configurable because a bank and a municipality do not
 * weigh reputation the same way, and every stored score carries the weights it used so an old
 * assessment stays explicable after the model is retuned.
 */
final class RiskModel
{
    public const COMPANY_IDENTITY = 'company_identity';

    public const OWNERSHIP = 'ownership';

    public const TAX = 'tax';

    public const FINANCIAL = 'financial';

    public const COMPLIANCE = 'compliance';

    public const SANCTIONS_PEP = 'sanctions_pep';

    public const REPUTATION = 'reputation';

    public const OPERATIONAL = 'operational';

    public const CYBER = 'cyber';

    public const ESG = 'esg';

    /**
     * @return array<string, array{label:string, weight:float, on_missing:string, blocks_approval:bool}>
     */
    public static function components(): array
    {
        return [
            self::COMPANY_IDENTITY => [
                'label' => 'Company & identity',
                'weight' => 10.0,
                // Not knowing who a company is IS the risk.
                'on_missing' => 'risk',
                'blocks_approval' => true,
            ],
            self::OWNERSHIP => [
                'label' => 'Ownership',
                'weight' => 10.0,
                'on_missing' => 'risk',
                'blocks_approval' => false,
            ],
            self::TAX => [
                'label' => 'Tax',
                'weight' => 10.0,
                'on_missing' => 'risk',
                'blocks_approval' => true,
            ],
            self::FINANCIAL => [
                'label' => 'Financial & credit',
                'weight' => 15.0,
                'on_missing' => 'risk',
                'blocks_approval' => false,
            ],
            self::COMPLIANCE => [
                'label' => 'Compliance',
                'weight' => 10.0,
                'on_missing' => 'risk',
                'blocks_approval' => true,
            ],
            self::SANCTIONS_PEP => [
                'label' => 'Sanctions & PEP',
                'weight' => 15.0,
                'on_missing' => 'risk',
                'blocks_approval' => true,
            ],
            self::REPUTATION => [
                'label' => 'Reputation',
                'weight' => 15.0,
                // Thin coverage is reported as thin coverage rather than scored as guilt.
                'on_missing' => 'neutral',
                'blocks_approval' => false,
            ],
            self::OPERATIONAL => [
                'label' => 'Operational',
                'weight' => 5.0,
                'on_missing' => 'neutral',
                'blocks_approval' => false,
            ],
            self::CYBER => [
                'label' => 'Cybersecurity',
                'weight' => 5.0,
                'on_missing' => 'neutral',
                'blocks_approval' => false,
            ],
            self::ESG => [
                'label' => 'ESG',
                'weight' => 5.0,
                'on_missing' => 'neutral',
                'blocks_approval' => false,
            ],
        ];
    }

    /** The score a component gets when nothing is known about it. */
    public static function missingScore(string $component): float
    {
        return (self::components()[$component]['on_missing'] ?? 'neutral') === 'risk' ? 65.0 : 0.0;
    }

    /**
     * Bands, in ascending order of concern. The labels are the ones the specification uses, and the
     * boundaries are inclusive-of-the-upper: 40 is low, 41 is moderate.
     *
     * @return array<string, array{label:string, max:float, requires:string}>
     */
    public static function bands(): array
    {
        return [
            'excellent' => ['label' => 'Excellent', 'max' => 20.0, 'requires' => 'standard'],
            'low' => ['label' => 'Low risk', 'max' => 40.0, 'requires' => 'standard'],
            'moderate' => ['label' => 'Moderate', 'max' => 60.0, 'requires' => 'additional_checks'],
            'high' => ['label' => 'High', 'max' => 80.0, 'requires' => 'enhanced_due_diligence'],
            'critical' => ['label' => 'Critical', 'max' => 100.0, 'requires' => 'investigation'],
        ];
    }

    public static function bandFor(float $score, ?array $custom = null): string
    {
        foreach ($custom ?: self::bands() as $key => $band) {
            if ($score <= (float) ($band['max'] ?? 100)) {
                return $key;
            }
        }

        return 'critical';
    }

    /** Below this a supplier may be approved without a person weighing in — if every gate also passes. */
    public const AUTO_APPROVE_BELOW = 30.0;

    /**
     * Diligence tiers, decided BEFORE any checking from what the engagement actually is.
     *
     * Tier is not the score. Tier says how much diligence this engagement warrants; the score is the
     * result of doing it. Keeping them apart is the only thing that makes the process survive real
     * volume — a stationery supplier and a supplier with access to payroll data must not walk the
     * same path, and a system that insists they do gets bypassed within a month.
     */
    public static function tiers(): array
    {
        return [
            1 => ['label' => 'Basic', 'description' => 'Low spend, no data or system access. Identity and compliance only.'],
            2 => ['label' => 'Standard', 'description' => 'Ordinary commercial engagement. Adds financial and screening checks.'],
            3 => ['label' => 'Elevated', 'description' => 'Material spend, or access to data, systems or premises. Adds ownership resolution and enhanced screening.'],
            4 => ['label' => 'Critical', 'description' => 'Government contracts, critical infrastructure, security, financial services, or personal data at scale. Full enhanced due diligence.'],
        ];
    }

    /**
     * What pushes an engagement up a tier.
     *
     * Deliberately about the engagement rather than the supplier: the same company can be tier 1 for
     * stationery and tier 4 for a payroll integration, and pretending otherwise is how a "low risk"
     * supplier ends up holding everybody's ID numbers.
     */
    public static function tierFactors(): array
    {
        return [
            'annual_spend_over' => ['label' => 'Annual spend above the threshold', 'tier' => 3],
            'contract_value_over' => ['label' => 'Contract value above the threshold', 'tier' => 3],
            'personal_data' => ['label' => 'Processes personal data', 'tier' => 4],
            'system_access' => ['label' => 'Has access to company systems', 'tier' => 4],
            'premises_access' => ['label' => 'Has access to premises', 'tier' => 3],
            'government_contract' => ['label' => 'Used on government contracts', 'tier' => 4],
            'critical_infrastructure' => ['label' => 'Critical infrastructure', 'tier' => 4],
            'security_services' => ['label' => 'Security services', 'tier' => 4],
            'financial_services' => ['label' => 'Financial services', 'tier' => 4],
            'sole_source' => ['label' => 'Sole-source supplier', 'tier' => 3],
            'subcontracts_work' => ['label' => 'Subcontracts the work onward', 'tier' => 3],
        ];
    }
}
