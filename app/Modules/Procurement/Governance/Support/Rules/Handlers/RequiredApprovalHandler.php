<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Facades\DB;

/**
 * REQUIRED_APPROVAL — the approval this subject needs exists, and reached the right level
 * (§5.3 "approval levels", §5.4 "required approvals" and "applicable approval level").
 *
 * parameters:
 *   chain_code   string|null  a specific chain must have been used
 *   status       string       required state, default 'APPROVED'
 *   min_steps    int|null     the chain must have had at least this many approval levels
 *   step_names   list<string> these named steps must all have been approved
 *
 * The point of `min_steps` and `step_names` is the delegation-of-authority question an auditor
 * actually asks: not "was it approved" but "was it approved by someone senior enough for that
 * amount". A three-million-rand deviation signed off only by a department manager passes a naive
 * "is there an approval" check and fails this one.
 */
class RequiredApprovalHandler implements RuleHandler
{
    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        $requiredStatus = strtoupper((string) ($parameters['status'] ?? 'APPROVED'));
        $chainCode = $parameters['chain_code'] ?? null;
        $minSteps = isset($parameters['min_steps']) ? (int) $parameters['min_steps'] : null;
        $stepNames = array_values(array_filter((array) ($parameters['step_names'] ?? [])));

        $query = DB::table('procgov_approval_requests')
            ->where('company_id', $subject->companyId)
            ->where('subject_type', $subject->type)
            ->where('subject_id', $subject->id)
            ->whereNotIn('status', ['WITHDRAWN', 'CANCELLED']);

        if ($chainCode) {
            $query->where('chain_code', $chainCode);
        }

        $request = $query->orderByDesc('id')->first();

        if (! $request) {
            return RuleOutcome::fail(
                $chainCode
                    ? "No approval has been raised under the '{$chainCode}' approval chain."
                    : 'No approval has been raised for this item.',
                ['chain_code' => $chainCode],
            );
        }

        if ($request->status !== $requiredStatus) {
            return RuleOutcome::fail(
                "Approval is {$request->status}, and {$requiredStatus} is required.",
                ['status' => $request->status, 'required' => $requiredStatus, 'chain_code' => $request->chain_code],
            );
        }

        if ($minSteps !== null && (int) $request->total_steps < $minSteps) {
            return RuleOutcome::fail(
                "The approval passed through {$request->total_steps} level(s); this value requires "
                ."at least {$minSteps}.",
                ['total_steps' => (int) $request->total_steps, 'required_steps' => $minSteps],
            );
        }

        if ($stepNames !== []) {
            $approved = DB::table('procgov_approval_steps')
                ->where('request_id', $request->id)
                ->where('status', 'APPROVED')
                ->pluck('name')
                ->map(fn ($n) => mb_strtolower((string) $n))
                ->all();

            $outstanding = array_values(array_filter(
                $stepNames,
                fn (string $name) => ! in_array(mb_strtolower($name), $approved, true)
            ));

            if ($outstanding !== []) {
                return RuleOutcome::fail(
                    'These approval levels have not been signed: '.implode(', ', $outstanding).'.',
                    ['outstanding_steps' => $outstanding],
                );
            }
        }

        return RuleOutcome::pass();
    }
}
