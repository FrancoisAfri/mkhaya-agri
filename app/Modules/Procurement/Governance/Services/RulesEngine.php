<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\ConditionMatcher;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\BudgetAvailabilityHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\CustomExpressionHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\FinancialThresholdHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\MandatoryJustificationHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\RequiredApprovalHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\RequiredDeclarationHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\RequiredDocumentHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\RequiredFieldHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\SupplierComplianceHandler;
use App\Modules\Procurement\Governance\Support\Rules\Handlers\TimeLimitHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Procurement Rules Engine (§5.3) and the automatic compliance check it performs (§5.4).
 *
 * Given a subject, it finds the rule set that governs that kind of subject, keeps the rules whose
 * conditions match, runs each through its handler, and writes the verdict — PASS, WARNING or FAIL —
 * together with every individual rule outcome.
 *
 * Two things about the verdict are deliberate:
 *
 *  - **SKIPPED never becomes PASS.** A rule that could not be evaluated because Budget Management
 *    is absent is reported as skipped and counted separately. Rolling it into PASS would let a
 *    compliance dashboard show green for checks that never ran, which is the single most dangerous
 *    thing a compliance dashboard can do.
 *
 *  - **A check is a record, not a return value.** §5.4 runs before submission, but §7 needs the
 *    result to still be there years later, attached to the deviation, showing which rule set version
 *    produced it. So every run is persisted and the caller gets the row back.
 *
 * When a tenant has configured no rule set for a subject, the result is PASS with zero rules
 * evaluated. That is the honest answer — the organisation has not defined any compliance
 * requirements yet — and it keeps the module usable from the first day rather than blocking every
 * submission until someone finishes configuring a framework.
 */
class RulesEngine
{
    /** rule_type => handler class. Adding a rule kind is one line here plus the class. */
    private const HANDLERS = [
        'REQUIRED_DOCUMENT' => RequiredDocumentHandler::class,
        'REQUIRED_FIELD' => RequiredFieldHandler::class,
        'REQUIRED_DECLARATION' => RequiredDeclarationHandler::class,
        'REQUIRED_APPROVAL' => RequiredApprovalHandler::class,
        'FINANCIAL_THRESHOLD' => FinancialThresholdHandler::class,
        'TIME_LIMIT' => TimeLimitHandler::class,
        'BUDGET_AVAILABILITY' => BudgetAvailabilityHandler::class,
        'SUPPLIER_COMPLIANCE' => SupplierComplianceHandler::class,
        'MANDATORY_JUSTIFICATION' => MandatoryJustificationHandler::class,
        'CUSTOM' => CustomExpressionHandler::class,
    ];

    public function __construct(
        private readonly ConditionMatcher $conditions,
        private readonly AuditLedger $audit,
    ) {}

    /**
     * Run the checks and persist the result.
     *
     * @param  string  $trigger  manual | pre_submit | scheduled | transition
     * @return object the `procgov_compliance_checks` row, with a `results` collection attached
     */
    public function check(RuleSubject $subject, string $trigger = 'manual', ?int $ruleSetId = null): object
    {
        $ruleSet = $this->resolveRuleSet($subject, $ruleSetId);
        $rules = $ruleSet ? $this->applicableRules($ruleSet->id, $subject) : collect();

        $results = [];
        $failures = 0;
        $warnings = 0;
        $skipped = 0;

        foreach ($rules as $rule) {
            $outcome = $this->evaluateRule($rule, $subject);

            match ($outcome->outcome) {
                RuleOutcome::FAIL => $failures++,
                RuleOutcome::WARNING => $warnings++,
                RuleOutcome::SKIPPED => $skipped++,
                default => null,
            };

            $results[] = [
                'rule' => $rule,
                'outcome' => $outcome,
            ];
        }

        $verdict = $failures > 0
            ? RuleOutcome::FAIL
            : ($warnings > 0 ? RuleOutcome::WARNING : RuleOutcome::PASS);

        return DB::transaction(function () use ($subject, $trigger, $ruleSet, $results, $verdict, $failures, $warnings, $skipped) {
            $checkId = DB::table('procgov_compliance_checks')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'company_id' => $subject->companyId,
                'subject_type' => $subject->type,
                'subject_id' => $subject->id,
                'subject_reference' => $subject->reference,
                'record_id' => $subject->recordId,
                'rule_set_id' => $ruleSet?->id,
                'rule_set_code' => $ruleSet?->code,
                'rule_set_version' => $ruleSet?->version,
                'result' => $verdict,
                'rules_evaluated' => count($results),
                'failures' => $failures,
                'warnings' => $warnings,
                'context' => json_encode($subject->toContext()),
                'trigger' => $trigger,
                'checked_by' => auth()->id(),
                'checked_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            foreach ($results as $entry) {
                /** @var RuleOutcome $outcome */
                $outcome = $entry['outcome'];
                $rule = $entry['rule'];

                DB::table('procgov_compliance_results')->insert([
                    'company_id' => $subject->companyId,
                    'check_id' => $checkId,
                    'rule_id' => $rule->id,
                    'rule_code' => $rule->code,
                    'rule_name' => $rule->name,
                    'rule_type' => $rule->rule_type,
                    'severity' => $rule->severity,
                    'outcome' => $outcome->outcome,
                    'message' => Str::limit($outcome->message ?? '', 495, '') ?: null,
                    'detail' => $outcome->detail ? json_encode($outcome->detail) : null,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            $this->audit->record($subject->companyId, [
                'action' => 'compliance_checked',
                'capability' => 'governance',
                'entity_type' => $subject->type,
                'entity_id' => $subject->id,
                'entity_reference' => $subject->reference,
                'record_id' => $subject->recordId,
                'decision' => $verdict,
                'summary' => sprintf(
                    'Compliance check on %s returned %s (%d rules, %d failed, %d warnings, %d skipped)',
                    $subject->reference ?: $subject->type.' #'.$subject->id,
                    $verdict,
                    count($results),
                    $failures,
                    $warnings,
                    $skipped,
                ),
            ]);

            $check = DB::table('procgov_compliance_checks')->find($checkId);
            $check->results = DB::table('procgov_compliance_results')
                ->where('check_id', $checkId)
                ->orderByRaw("case outcome when 'FAIL' then 0 when 'WARNING' then 1 when 'SKIPPED' then 2 else 3 end")
                ->get();
            $check->skipped = $skipped;

            return $check;
        });
    }

    /**
     * The §5.4 headline without persisting anything — for a live form that shows the user where
     * they stand as they type. `check()` is what runs on submit.
     *
     * @return array{result:string, failures:list<string>, warnings:list<string>, skipped:list<string>}
     */
    public function preview(RuleSubject $subject, ?int $ruleSetId = null): array
    {
        $ruleSet = $this->resolveRuleSet($subject, $ruleSetId);
        $rules = $ruleSet ? $this->applicableRules($ruleSet->id, $subject) : collect();

        $buckets = ['failures' => [], 'warnings' => [], 'skipped' => []];

        foreach ($rules as $rule) {
            $outcome = $this->evaluateRule($rule, $subject);
            $line = $outcome->message ?: $rule->name;

            match ($outcome->outcome) {
                RuleOutcome::FAIL => $buckets['failures'][] = $line,
                RuleOutcome::WARNING => $buckets['warnings'][] = $line,
                RuleOutcome::SKIPPED => $buckets['skipped'][] = $line,
                default => null,
            };
        }

        return array_merge(
            [
                'result' => $buckets['failures'] !== []
                    ? RuleOutcome::FAIL
                    : ($buckets['warnings'] !== [] ? RuleOutcome::WARNING : RuleOutcome::PASS),
            ],
            $buckets,
        );
    }

    /**
     * The active rule set governing a subject type. A set declared for the specific type beats the
     * catch-all 'any' set, so a tenant can have general rules plus tighter ones for deviations.
     */
    public function resolveRuleSet(RuleSubject $subject, ?int $ruleSetId = null): ?object
    {
        $query = DB::table('procgov_rule_sets')
            ->where('company_id', $subject->companyId)
            ->whereNull('deleted_at');

        if ($ruleSetId) {
            return $query->where('id', $ruleSetId)->first();
        }

        $today = Carbon::today()->toDateString();

        return $query
            ->where('is_active', true)
            ->whereIn('applies_to', [$this->subjectAlias($subject->type), 'any'])
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            // Specific before generic, then the newest version of it.
            ->orderByRaw('case when applies_to = ? then 0 else 1 end', [$this->subjectAlias($subject->type)])
            ->orderByDesc('version')
            ->first();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    private function applicableRules(int $ruleSetId, RuleSubject $subject)
    {
        return DB::table('procgov_rules')
            ->where('rule_set_id', $ruleSetId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn ($rule) => $this->conditions->matches(
                $this->decode($rule->conditions),
                $subject,
            ))
            ->values();
    }

    private function evaluateRule(object $rule, RuleSubject $subject): RuleOutcome
    {
        $handlerClass = self::HANDLERS[$rule->rule_type] ?? null;

        if (! $handlerClass) {
            return RuleOutcome::skip("No handler is registered for rule type {$rule->rule_type}.");
        }

        try {
            /** @var RuleHandler $handler */
            $handler = app($handlerClass);

            $outcome = $handler
                ->evaluate($subject, $this->decode($rule->parameters) ?? [])
                ->applySeverity((string) $rule->severity);

            // A rule's configured message is the tenant's own wording and wins over the handler's
            // generic one — but only for a failure, since a configured message reads as a complaint
            // and attaching it to a pass would be nonsense.
            if ($rule->message && in_array($outcome->outcome, [RuleOutcome::FAIL, RuleOutcome::WARNING], true)) {
                return $outcome->withMessage((string) $rule->message);
            }

            return $outcome;
        } catch (\Throwable $e) {
            report($e);

            // A broken rule must not silently pass, and must not take the whole check down with it.
            return RuleOutcome::skip('This rule could not be evaluated because of a system error.');
        }
    }

    private function decode(mixed $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }

        if (is_array($json)) {
            return $json;
        }

        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Table name → the `applies_to` vocabulary a tenant configures against. */
    private function subjectAlias(string $type): string
    {
        return match ($type) {
            'procgov_deviations' => 'deviation',
            'procgov_plans' => 'plan',
            'procgov_plan_items' => 'plan_item',
            'procgov_tender_reviews' => 'tender_review',
            'procgov_evaluations' => 'evaluation',
            'procgov_records' => 'record',
            default => $type,
        };
    }
}
