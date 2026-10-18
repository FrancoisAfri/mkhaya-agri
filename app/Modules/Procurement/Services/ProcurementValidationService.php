<?php

namespace App\Modules\Procurement\Services;

use App\Modules\Budget\Services\BudgetService;
use App\Modules\Procurement\Models\ProcurementPolicyEnforcementLog;
use App\Modules\Procurement\Models\ProcurementPolicyRule;
use App\Modules\Procurement\Models\ProcurementPolicyViolation;
use App\Modules\Procurement\Models\PurchaseRequest;
use Illuminate\Support\Carbon;

/**
 * Validates a purchase request before approval: policy rules, budget availability,
 * the approval matrix, duplicate/split detection. Returns blocks (must be fixed),
 * warnings (informational), and the approval chain to route.
 */
class ProcurementValidationService
{
    public function __construct(private readonly BudgetService $budgets) {}

    /** @return array{blocks:array,warnings:array,chain:array} */
    public function validate(PurchaseRequest $request): array
    {
        $request->loadMissing(['items', 'budget']);
        $total = (float) $request->estimated_total;
        $rules = ProcurementPolicyRule::where('active', true)->orderBy('sort_order')->get();

        $blocks = [];
        $warnings = [];

        // 1. Emergency requests require justification.
        if ($request->is_emergency && trim((string) $request->justification) === '') {
            $blocks[] = $this->violation('emergency_justification', 'block', 'Emergency requests require a written justification.');
        }

        // 2. Procurement cannot proceed without an approved/active budget.
        if (! $request->budget_id || ! $request->budget || $request->budget->status !== 'active') {
            $blocks[] = $this->violation('budget_required', 'block', 'An active approved budget must be linked before submission.');
        } else {
            // 3. Budget availability — block if short and no approved override.
            $check = $this->budgets->validateBudgetAvailability($request->budget, $total);
            if (! $check['ok']) {
                // A budget stopped by one of its company's blocking rules has a shortfall of zero,
                // so the generic sentence would read "short by 0.00" and send the buyer to ask for
                // an increase they do not need. The specific reason wins when there is one.
                $blocks[] = $this->violation('budget_unavailable', 'block', $check['reason'] ?? sprintf(
                    'Insufficient budget: available %s, short by %s. Request a budget override or increase.',
                    number_format($check['available'], 2),
                    number_format($check['shortfall'], 2)
                ), ['available' => $check['available'], 'shortfall' => $check['shortfall'], 'blocked' => $check['blocked'] ?? false]);
            }
        }

        // 4. Quotation threshold (informational — enforced at RFQ stage).
        foreach ($rules->where('type', 'quotation_threshold') as $rule) {
            if ($total >= (float) $rule->min_amount) {
                $quotes = (int) ($rule->params['quotes'] ?? 3);
                $warnings[] = $this->violation('quotation_threshold', 'warning',
                    "{$quotes} quotations required for spend of ".number_format($total, 2).".");
            }
        }

        // 5. Duplicate detection.
        if ($this->looksDuplicate($request)) {
            $warnings[] = $this->violation('duplicate', 'warning', 'A very similar recent request exists — possible duplicate.');
        }

        // 6. Split detection.
        if ($this->looksSplit($request, $rules)) {
            $warnings[] = $this->violation('split', 'warning', 'Multiple recent requests just under an approval threshold — possible split purchase.');
        }

        $chain = $this->buildApprovalChain($request, $rules, $total);

        // Log enforcement outcomes.
        foreach (array_merge($blocks, $warnings) as $v) {
            $this->log($request, $v['rule_type'], $v['severity'] === 'block' ? 'fail' : 'exception', $v);
        }
        $this->log($request, 'approval_matrix', 'pass', ['steps' => count($chain)]);

        return ['blocks' => $blocks, 'warnings' => $warnings, 'chain' => $chain];
    }

    /** Persist the returned violations against the request. */
    public function persistViolations(PurchaseRequest $request, array $violations): void
    {
        ProcurementPolicyViolation::where('source_type', PurchaseRequest::class)
            ->where('source_id', $request->id)->where('status', 'open')->delete();

        foreach ($violations as $v) {
            ProcurementPolicyViolation::create([
                'source_type' => PurchaseRequest::class,
                'source_id'   => $request->id,
                'rule_type'   => $v['rule_type'],
                'severity'    => $v['severity'],
                'message'     => $v['message'],
                'context'     => $v['context'] ?? null,
                'status'      => 'open',
            ]);
        }
    }

    /**
     * Build the approval chain from approval_threshold rules: every rule whose
     * min_amount <= total (and whose category filter matches, if any) becomes a
     * step, ordered by amount.
     */
    public function buildApprovalChain(PurchaseRequest $request, $rules, float $total): array
    {
        $steps = [];
        $i = 1;
        $matched = $rules->where('type', 'approval_threshold')
            ->filter(fn ($r) => $total >= (float) $r->min_amount && $this->categoryMatches($request, $r))
            ->sortBy('min_amount');

        foreach ($matched as $rule) {
            $steps[] = [
                'step' => $i++,
                'name' => $rule->name ?: ('Approval ≥ '.number_format((float) $rule->min_amount, 0)),
                'role' => $rule->role,
            ];
        }

        // Always at least a line-manager step.
        if (empty($steps)) {
            $steps[] = ['step' => 1, 'name' => 'Manager approval', 'role' => 'manager'];
        }

        return $steps;
    }

    private function categoryMatches(PurchaseRequest $request, ProcurementPolicyRule $rule): bool
    {
        $term = $rule->params['category'] ?? null;
        if (! $term) {
            return true;
        }
        $term = mb_strtolower($term);

        return $request->items->contains(fn ($it) =>
            str_contains(mb_strtolower((string) $it->name), $term)
            || str_contains(mb_strtolower((string) $it->description), $term));
    }

    private function looksDuplicate(PurchaseRequest $request): bool
    {
        $total = (float) $request->estimated_total;
        if ($total <= 0) {
            return false;
        }

        return PurchaseRequest::query()
            ->where('id', '!=', $request->id)
            ->where('requester_id', $request->requester_id)
            ->whereNotIn('status', ['cancelled', 'rejected', 'draft'])
            ->whereBetween('estimated_total', [$total * 0.95, $total * 1.05])
            ->where('created_at', '>=', Carbon::now()->subDays(14))
            ->exists();
    }

    private function looksSplit(PurchaseRequest $request, $rules): bool
    {
        $threshold = (float) ($rules->where('type', 'approval_threshold')->min('min_amount') ?: 10000);
        $total = (float) $request->estimated_total;
        if ($total <= 0 || $total >= $threshold) {
            return false;
        }

        $recentSum = (float) PurchaseRequest::query()
            ->where('requester_id', $request->requester_id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->sum('estimated_total');

        return ($recentSum + $total) >= $threshold;
    }

    private function violation(string $type, string $severity, string $message, ?array $context = null): array
    {
        return ['rule_type' => $type, 'severity' => $severity, 'message' => $message, 'context' => $context];
    }

    private function log(PurchaseRequest $request, string $ruleType, string $outcome, array $detail): void
    {
        ProcurementPolicyEnforcementLog::create([
            'source_type' => PurchaseRequest::class,
            'source_id'   => $request->id,
            'rule_type'   => $ruleType,
            'outcome'     => $outcome,
            'detail'      => $detail,
            'created_at'  => now(),
        ]);
    }
}
