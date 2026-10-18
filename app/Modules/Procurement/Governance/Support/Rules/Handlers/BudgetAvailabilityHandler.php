<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Services\Capabilities;
use App\Modules\Procurement\Governance\Support\Integration\BudgetBridge;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;

/**
 * BUDGET_AVAILABILITY — there is money for this (§5.4 "budget availability", §2.3).
 *
 * parameters:
 *   allow_over_commit  bool         default false
 *   tolerance_pct      float|null   permit an overrun up to this share of the allocation
 *   require_budget     bool         default true — a subject with no budget linked fails
 *
 * The Budget module owns the arithmetic, and this handler asks it rather than reimplementing it —
 * available = allocated + approved overrides − reserved − committed − actual, which is a definition
 * that must not exist in two places.
 *
 * When Budget Management is not installed the outcome is SKIPPED, never PASS. "We have no way to
 * check whether there is money for this" is a materially different statement from "there is money
 * for this", and a compliance report that conflates them is misleading in the one direction that
 * matters.
 */
class BudgetAvailabilityHandler implements RuleHandler
{
    public function __construct(
        private readonly Capabilities $capabilities,
        private readonly BudgetBridge $budgets,
    ) {}

    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        if (! $this->capabilities->integratesWith('finance.budgets')) {
            return RuleOutcome::skip('Budget Management is not installed, so availability cannot be verified.');
        }

        $requireBudget = (bool) ($parameters['require_budget'] ?? true);

        if (! $subject->budgetId) {
            return $requireBudget
                ? RuleOutcome::fail('No budget is linked, so the funding source cannot be verified.')
                : RuleOutcome::skip('No budget is linked and this rule does not require one.');
        }

        if ($subject->amount === null) {
            return RuleOutcome::skip('No value has been captured, so it cannot be checked against the budget.');
        }

        $amounts = $this->budgets->amounts($subject->budgetId);

        if ($amounts === null) {
            return RuleOutcome::skip('The linked budget could not be read.');
        }

        $available = (float) $amounts['available'];
        $shortfall = round($subject->amount - $available, 2);

        $detail = array_merge($amounts, [
            'requested' => $subject->amount,
            'shortfall' => max(0, $shortfall),
        ]);

        if ($shortfall <= 0) {
            return RuleOutcome::pass(null, $detail);
        }

        if ((bool) ($parameters['allow_over_commit'] ?? false)) {
            return RuleOutcome::pass(null, $detail);
        }

        $tolerance = isset($parameters['tolerance_pct']) ? (float) $parameters['tolerance_pct'] : null;

        if ($tolerance !== null && $amounts['allocated'] > 0) {
            $overrunPct = ($shortfall / (float) $amounts['allocated']) * 100;

            if ($overrunPct <= $tolerance) {
                $detail['overrun_pct'] = round($overrunPct, 2);
                $detail['tolerance_pct'] = $tolerance;

                return RuleOutcome::pass(null, $detail);
            }
        }

        return RuleOutcome::fail(
            sprintf(
                'The budget is short by %s — %s is available and %s is required.',
                number_format($shortfall, 2),
                number_format($available, 2),
                number_format($subject->amount, 2),
            ),
            $detail,
        );
    }
}
