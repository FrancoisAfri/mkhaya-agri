<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Support\Integration\BudgetBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The compliance dashboard (§3.5).
 *
 * §3.5 asks for a traffic light — 🟢 compliant, 🟠 attention required, 🔴 non-compliant — over nine
 * named conditions: missing documents, expired approvals, missing evaluations, missing
 * declarations, unapproved deviations, overdue procurement, missing contracts, budget overruns and
 * incomplete audit records.
 *
 * Each is a check that returns a count and a status, and every one of them names the affected
 * records. A dashboard that says "3 non-compliant" without saying which three sends someone hunting
 * through a register, which is how a compliance dashboard becomes something people stop opening.
 *
 * A check whose data source is unavailable reports as UNKNOWN rather than green. The single most
 * dangerous thing this screen could do is show a reassuring colour for a check that never ran.
 */
class ComplianceDashboardService
{
    public const COMPLIANT = 'COMPLIANT';

    public const ATTENTION = 'ATTENTION';

    public const NON_COMPLIANT = 'NON_COMPLIANT';

    public const UNKNOWN = 'UNKNOWN';

    public function __construct(
        private readonly BudgetBridge $budgets,
        private readonly AuditLedger $audit,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function snapshot(int $companyId, array $filters = []): array
    {
        $checks = [
            $this->missingDocuments($companyId),
            $this->expiringDocuments($companyId),
            $this->expiredApprovals($companyId),
            $this->missingEvaluations($companyId),
            $this->missingDeclarations($companyId),
            $this->unapprovedDeviations($companyId),
            $this->overdueProcurement($companyId),
            $this->missingContracts($companyId),
            $this->budgetOverruns($companyId),
            $this->incompleteAuditRecords($companyId),
        ];

        $counts = [
            self::COMPLIANT => 0,
            self::ATTENTION => 0,
            self::NON_COMPLIANT => 0,
            self::UNKNOWN => 0,
        ];

        foreach ($checks as $check) {
            $counts[$check['status']]++;
        }

        // The overall light is the worst individual light, not an average. Averaging would let nine
        // green checks hide one red one, which is the opposite of what this screen is for.
        $overall = match (true) {
            $counts[self::NON_COMPLIANT] > 0 => self::NON_COMPLIANT,
            $counts[self::ATTENTION] > 0 => self::ATTENTION,
            $counts[self::UNKNOWN] > 0 => self::ATTENTION,
            default => self::COMPLIANT,
        };

        return [
            'overall' => $overall,
            'counts' => $counts,
            'checks' => $checks,
            'generated_at' => Carbon::now()->toDateTimeString(),
            'recent_failures' => DB::table('procgov_compliance_checks')
                ->where('company_id', $companyId)
                ->whereIn('result', ['FAIL', 'WARNING'])
                ->orderByDesc('checked_at')
                ->limit(25)
                ->get(['uuid', 'subject_type', 'subject_reference', 'result', 'failures',
                    'warnings', 'checked_at', 'rule_set_code']),
        ];
    }

    // ── The nine §3.5 checks ─────────────────────────────────────────────────────────────────────

    private function missingDocuments(int $companyId): array
    {
        // A required document link with no usable document behind it: recorded as required during
        // configuration, never satisfied in practice.
        $rows = DB::table('procgov_document_links as l')
            ->leftJoin('procgov_documents as d', function ($join) {
                $join->on('d.id', '=', 'l.document_id')->whereNull('d.deleted_at');
            })
            ->where('l.company_id', $companyId)
            ->where('l.is_required', true)
            ->whereNull('d.id')
            ->limit(100)
            ->get(['l.entity_type', 'l.entity_id', 'l.entity_reference', 'l.link_role']);

        return $this->check(
            'missing_documents',
            'Missing required documents',
            $rows->count(),
            $rows,
            failFrom: 1,
        );
    }

    private function expiringDocuments(int $companyId): array
    {
        $expired = DB::table('procgov_documents')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', Carbon::today())
            ->limit(100)
            ->get(['uuid', 'reference', 'title', 'expires_at']);

        $expiring = DB::table('procgov_documents')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [Carbon::today(), Carbon::today()->addDays(30)])
            ->count();

        return $this->check(
            'expiring_documents',
            'Expired and expiring documents',
            $expired->count(),
            $expired,
            failFrom: 1,
            attentionCount: $expiring,
        );
    }

    private function expiredApprovals(int $companyId): array
    {
        $rows = DB::table('procgov_approval_steps as s')
            ->join('procgov_approval_requests as r', 'r.id', '=', 's.request_id')
            ->where('s.company_id', $companyId)
            ->where('s.status', 'ACTIVE')
            ->whereNotNull('s.due_at')
            ->where('s.due_at', '<', Carbon::now())
            ->limit(100)
            ->get(['r.subject_type', 'r.subject_reference', 's.name', 's.due_at']);

        return $this->check(
            'expired_approvals',
            'Approvals past their service level',
            $rows->count(),
            $rows,
            failFrom: 5,
        );
    }

    private function missingEvaluations(int $companyId): array
    {
        // Reviews open for evaluation past their deadline with evaluators who have not submitted.
        $rows = DB::table('procgov_tender_reviews as r')
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')
            ->where('r.status', 'EVALUATION')
            ->whereNotNull('r.evaluation_deadline')
            ->where('r.evaluation_deadline', '<', Carbon::today())
            ->limit(100)
            ->select(['r.uuid', 'r.reference', 'r.title', 'r.evaluation_deadline'])
            // Counted in the same query rather than per row: the outstanding-evaluator figure is
            // the whole point of the list, and a per-row lookup would issue one query per review.
            ->selectSub(
                DB::table('procgov_review_evaluators as e')
                    ->selectRaw('count(*)')
                    ->whereColumn('e.review_id', 'r.id')
                    ->where('e.is_voting', true)
                    ->whereNotIn('e.status', ['SUBMITTED', 'RECUSED', 'REMOVED']),
                'outstanding_evaluators'
            )
            ->get();

        return $this->check(
            'missing_evaluations',
            'Overdue tender evaluations',
            $rows->count(),
            $rows,
            failFrom: 1,
        );
    }

    private function missingDeclarations(int $companyId): array
    {
        $rows = DB::table('procgov_review_evaluators as e')
            ->join('procgov_tender_reviews as r', 'r.id', '=', 'e.review_id')
            ->where('e.company_id', $companyId)
            ->whereIn('e.coi_status', ['NOT_COMPLETED', 'CONFLICT_IDENTIFIED'])
            ->whereNotIn('e.status', ['REMOVED'])
            ->whereIn('r.status', ['CRITERIA_SETUP', 'EVALUATION', 'MODERATION'])
            ->limit(100)
            ->get(['r.uuid as review_uuid', 'r.reference', 'e.coi_status', 'e.member_role']);

        // An unresolved declared conflict is materially worse than an outstanding one, so it alone
        // turns this check red.
        $conflicts = $rows->where('coi_status', 'CONFLICT_IDENTIFIED')->count();

        return $this->check(
            'missing_declarations',
            'Outstanding conflict-of-interest declarations',
            $conflicts,
            $rows,
            failFrom: 1,
            attentionCount: $rows->where('coi_status', 'NOT_COMPLETED')->count(),
        );
    }

    private function unapprovedDeviations(int $companyId): array
    {
        $rows = DB::table('procgov_deviations')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['SUBMITTED', 'COMPLIANCE_REVIEW', 'PROCUREMENT_REVIEW', 'FINANCIAL_REVIEW', 'MANAGEMENT_APPROVAL'])
            ->limit(100)
            ->get(['uuid', 'reference', 'title', 'status', 'risk_level', 'submitted_at', 'compliance_result']);

        // A deviation that failed compliance and is still in flight is the red condition; ordinary
        // pending approvals are just work in progress.
        $failing = $rows->where('compliance_result', 'FAIL')->count();

        return $this->check(
            'unapproved_deviations',
            'Deviations awaiting approval',
            $failing,
            $rows,
            failFrom: 1,
            attentionCount: $rows->count(),
        );
    }

    private function overdueProcurement(int $companyId): array
    {
        $rows = DB::table('procgov_records')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('status', 'ACTIVE')
            ->whereNotNull('target_completion_at')
            ->where('target_completion_at', '<', Carbon::now())
            ->limit(100)
            ->get(['uuid', 'reference', 'title', 'stage', 'target_completion_at', 'officer_id']);

        return $this->check(
            'overdue_procurement',
            'Procurements past their target date',
            $rows->count(),
            $rows,
            failFrom: 10,
            attentionCount: $rows->count(),
        );
    }

    private function missingContracts(int $companyId): array
    {
        // Awarded, but nothing linked as a contract — the gap between decision and paperwork, which
        // is where value quietly leaks.
        $awarded = DB::table('procgov_records as r')
            ->where('r.company_id', $companyId)
            ->whereNull('r.deleted_at')
            ->whereIn('r.stage', ['AWARD', 'CONTRACT', 'DELIVERY'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('procgov_record_links as l')
                    ->whereColumn('l.record_id', 'r.id')
                    ->where('l.link_role', 'contract');
            })
            ->limit(100)
            ->get(['r.uuid', 'r.reference', 'r.title', 'r.stage', 'r.awarded_value']);

        return $this->check(
            'missing_contracts',
            'Awards with no contract recorded',
            $awarded->count(),
            $awarded,
            failFrom: 1,
        );
    }

    private function budgetOverruns(int $companyId): array
    {
        if (! $this->budgets->isAvailable()) {
            return [
                'key' => 'budget_overruns',
                'label' => 'Budget overruns',
                'status' => self::UNKNOWN,
                'count' => 0,
                'message' => 'Budget Management is not installed, so budget positions cannot be checked.',
                'items' => [],
            ];
        }

        $plans = DB::table('procgov_plans')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('budget_available_at_verification')
            ->whereColumn('total_estimated_value', '>', 'budget_available_at_verification')
            ->limit(100)
            ->get(['uuid', 'reference', 'title', 'total_estimated_value', 'budget_available_at_verification']);

        return $this->check(
            'budget_overruns',
            'Plans exceeding their verified budget',
            $plans->count(),
            $plans,
            failFrom: 1,
        );
    }

    /**
     * §3.5 "incomplete audit records" — read here as an audit chain that does not verify. A broken
     * chain is the most serious finding this dashboard can produce, so it is red at one.
     */
    private function incompleteAuditRecords(int $companyId): array
    {
        $verification = $this->audit->verify($companyId);

        if ($verification['ok']) {
            return [
                'key' => 'audit_integrity',
                'label' => 'Audit trail integrity',
                'status' => self::COMPLIANT,
                'count' => 0,
                'message' => "Audit chain verified across {$verification['checked']} events.",
                'items' => [],
            ];
        }

        return [
            'key' => 'audit_integrity',
            'label' => 'Audit trail integrity',
            'status' => self::NON_COMPLIANT,
            'count' => 1,
            'message' => sprintf(
                'The audit chain does not verify at sequence %s: %s.',
                $verification['broken_at'] ?? '?',
                $verification['reason'] ?? 'unknown',
            ),
            'items' => [$verification],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * `$count` is the number that makes the check red; `$attentionCount` (defaulting to the same)
     * is the number that makes it amber. Separating them lets a check distinguish a serious
     * condition from a routine one within the same list — pending deviations are amber, deviations
     * that failed compliance are red.
     */
    private function check(
        string $key,
        string $label,
        int $count,
        $items,
        int $failFrom = 1,
        ?int $attentionCount = null,
    ): array {
        $attention = $attentionCount ?? $count;

        $status = match (true) {
            $count >= $failFrom => self::NON_COMPLIANT,
            $attention > 0 => self::ATTENTION,
            default => self::COMPLIANT,
        };

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'count' => $status === self::NON_COMPLIANT ? $count : $attention,
            'message' => null,
            'items' => $items,
        ];
    }
}
