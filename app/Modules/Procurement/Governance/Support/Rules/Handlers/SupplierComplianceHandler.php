<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Integration\SupplierBridge;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;

/**
 * SUPPLIER_COMPLIANCE — the supplier is fit to be dealt with (§5.4 "supplier information").
 *
 * parameters:
 *   require_active        bool         default true
 *   require_tax_reference bool         default false
 *   max_bbbee_level       int|null     e.g. 4 — a *worse* level than this is not acceptable
 *   allow_open_risk_flags bool         default true
 *   max_risk_severity     string|null  low | medium | high | critical
 *   allow_expired_documents bool       default false
 *
 * B-BBEE levels run the wrong way round for intuition — level 1 is the strongest — so
 * `max_bbbee_level` is a ceiling on the *number*, and a supplier at level 6 fails a ceiling of 4.
 * Naming it `max` and comparing with `>` is the one way round that matches how procurement people
 * write the requirement down.
 */
class SupplierComplianceHandler implements RuleHandler
{
    private const SEVERITY_RANK = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function __construct(private readonly SupplierBridge $suppliers) {}

    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        if (! $this->suppliers->isAvailable()) {
            return RuleOutcome::skip('Supplier Management is not installed, so supplier compliance cannot be verified.');
        }

        if (! $subject->supplierId) {
            return RuleOutcome::skip('No supplier is linked to this item.');
        }

        $supplier = $this->suppliers->compliance($subject->supplierId, $subject->companyId);

        if ($supplier === null) {
            return RuleOutcome::fail('The linked supplier could not be found on the supplier register.');
        }

        $failures = [];

        if (($parameters['require_active'] ?? true) && ! $supplier['is_active']) {
            $failures[] = 'the supplier is not active on the register';
        }

        if (($parameters['require_tax_reference'] ?? false) && empty($supplier['tax_reference'])) {
            $failures[] = 'no tax reference number is recorded';
        }

        if (isset($parameters['max_bbbee_level'])) {
            $ceiling = (int) $parameters['max_bbbee_level'];

            if ($supplier['bbbee_level'] === null) {
                $failures[] = 'no B-BBEE level is recorded';
            } elseif ($supplier['bbbee_level'] > $ceiling) {
                $failures[] = "B-BBEE level {$supplier['bbbee_level']} is weaker than the required level {$ceiling}";
            }
        }

        if (! ($parameters['allow_open_risk_flags'] ?? true) && $supplier['open_risk_flags'] > 0) {
            $failures[] = "{$supplier['open_risk_flags']} unresolved risk flag(s)";
        }

        if (! empty($parameters['max_risk_severity']) && $supplier['highest_risk_severity']) {
            $ceiling = self::SEVERITY_RANK[mb_strtolower((string) $parameters['max_risk_severity'])] ?? 4;
            $actual = self::SEVERITY_RANK[$supplier['highest_risk_severity']] ?? 0;

            if ($actual > $ceiling) {
                $failures[] = "an open {$supplier['highest_risk_severity']} risk flag";
            }
        }

        if (! ($parameters['allow_expired_documents'] ?? false) && $supplier['expired_documents'] > 0) {
            $failures[] = "{$supplier['expired_documents']} expired compliance document(s)";
        }

        if ($failures === []) {
            return RuleOutcome::pass(null, $supplier);
        }

        return RuleOutcome::fail(
            "{$supplier['name']}: ".implode('; ', $failures).'.',
            $supplier,
        );
    }
}
