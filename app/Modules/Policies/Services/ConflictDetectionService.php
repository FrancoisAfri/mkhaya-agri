<?php

namespace App\Modules\Policies\Services;

use App\Modules\Policies\Models\Policy;
use App\Modules\Policies\Models\PolicyClause;
use App\Modules\Policies\Models\PolicyConflict;
use App\Modules\Policies\Models\PolicyRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ConflictDetectionService
{
    /**
     * Run conflict detection for the given policy against all other active policies.
     * Persists detected conflicts to policy_conflicts and returns the count.
     */
    public function detect(Policy $policy, ?int $detectedBy = null): array
    {
        $policy->load(['rules.conditions', 'clauses']);

        $otherPolicies = Policy::with(['rules.conditions', 'clauses'])
            ->where('id', '!=', $policy->id)
            ->where('status', 'active')
            ->get();

        $detectedConflicts = [];

        foreach ($otherPolicies as $other) {
            // 1. Duplicate rules (same name + severity across policies)
            $duplicates = $this->findDuplicateRules($policy, $other);
            foreach ($duplicates as $pair) {
                $detectedConflicts[] = $this->persistConflict(
                    policyA : $policy,
                    policyB : $other,
                    ruleA   : $pair['a'],
                    ruleB   : $pair['b'],
                    type    : 'duplicate_rule',
                    desc    : "Rule \"{$pair['a']->name}\" in \"{$policy->name}\" duplicates \"{$pair['b']->name}\" in \"{$other->name}\" (same severity and action on breach).",
                    by      : $detectedBy,
                );
            }

            // 2. Contradicting clauses (same type, opposing action_on_breach)
            $contradictions = $this->findContradictingClauses($policy, $other);
            foreach ($contradictions as $pair) {
                $detectedConflicts[] = $this->persistConflict(
                    policyA : $policy,
                    policyB : $other,
                    ruleA   : null,
                    ruleB   : null,
                    type    : 'contradicting_clause',
                    desc    : "Clause [{$pair['a']->clause_reference}] in \"{$policy->name}\" contradicts [{$pair['b']->clause_reference}] in \"{$other->name}\" — same clause type with opposing enforcement rules.",
                    by      : $detectedBy,
                );
            }

            // 3. Overlapping scope (same department, same category)
            if ($this->hasOverlappingScope($policy, $other)) {
                $detectedConflicts[] = $this->persistConflict(
                    policyA : $policy,
                    policyB : $other,
                    ruleA   : null,
                    ruleB   : null,
                    type    : 'overlapping_scope',
                    desc    : "\"{$policy->name}\" and \"{$other->name}\" share the same category and department scope, creating potential overlap.",
                    by      : $detectedBy,
                );
            }

            // 4. Ambiguous threshold (same rule field, different numeric thresholds)
            $thresholds = $this->findAmbiguousThresholds($policy, $other);
            foreach ($thresholds as $pair) {
                $detectedConflicts[] = $this->persistConflict(
                    policyA : $policy,
                    policyB : $other,
                    ruleA   : $pair['a'],
                    ruleB   : $pair['b'],
                    type    : 'ambiguous_threshold',
                    desc    : "Threshold conflict on field \"{$pair['field']}\": \"{$policy->name}\" uses {$pair['val_a']}, but \"{$other->name}\" uses {$pair['val_b']}.",
                    by      : $detectedBy,
                );
            }
        }

        return $detectedConflicts;
    }

    // ── Rule similarity ───────────────────────────────────────────────────────

    private function findDuplicateRules(Policy $a, Policy $b): array
    {
        $pairs = [];
        foreach ($a->rules as $ruleA) {
            foreach ($b->rules as $ruleB) {
                if (
                    $ruleA->severity === $ruleB->severity &&
                    $ruleA->action_on_breach === $ruleB->action_on_breach &&
                    $this->nameSimilarity($ruleA->name, $ruleB->name) >= 0.75 &&
                    ! $this->conflictExists($a->id, $b->id, 'duplicate_rule', $ruleA->id, $ruleB->id)
                ) {
                    $pairs[] = ['a' => $ruleA, 'b' => $ruleB];
                }
            }
        }
        return $pairs;
    }

    private function findContradictingClauses(Policy $a, Policy $b): array
    {
        $pairs = [];
        foreach ($a->clauses as $clauseA) {
            foreach ($b->clauses as $clauseB) {
                if (
                    $clauseA->type === $clauseB->type &&
                    $this->textSimilarity($clauseA->content, $clauseB->content) >= 0.6 &&
                    $this->hasContradictingRules($clauseA, $clauseB) &&
                    ! $this->conflictExists($a->id, $b->id, 'contradicting_clause')
                ) {
                    $pairs[] = ['a' => $clauseA, 'b' => $clauseB];
                }
            }
        }
        return $pairs;
    }

    private function findAmbiguousThresholds(Policy $a, Policy $b): array
    {
        $pairs = [];
        foreach ($a->rules as $ruleA) {
            foreach ($ruleA->conditions as $condA) {
                if (! in_array($condA->operator, ['gt', 'gte', 'lt', 'lte', 'between'])) {
                    continue;
                }
                foreach ($b->rules as $ruleB) {
                    foreach ($ruleB->conditions as $condB) {
                        if (
                            $condA->field === $condB->field &&
                            $condA->operator === $condB->operator &&
                            $condA->value !== $condB->value &&
                            ! $this->conflictExists($a->id, $b->id, 'ambiguous_threshold', $ruleA->id, $ruleB->id)
                        ) {
                            $pairs[] = [
                                'a'     => $ruleA,
                                'b'     => $ruleB,
                                'field' => $condA->field,
                                'val_a' => is_array($condA->value) ? implode('–', $condA->value) : $condA->value,
                                'val_b' => is_array($condB->value) ? implode('–', $condB->value) : $condB->value,
                            ];
                        }
                    }
                }
            }
        }
        return $pairs;
    }

    private function hasOverlappingScope(Policy $a, Policy $b): bool
    {
        return $a->category_id !== null
            && $a->category_id === $b->category_id
            && $a->department_id !== null
            && $a->department_id === $b->department_id
            && ! $this->conflictExists($a->id, $b->id, 'overlapping_scope');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function hasContradictingRules(PolicyClause $clauseA, PolicyClause $clauseB): bool
    {
        $actionsA = $clauseA->rules->pluck('action_on_breach')->unique()->sort()->values()->toArray();
        $actionsB = $clauseB->rules->pluck('action_on_breach')->unique()->sort()->values()->toArray();

        $opposed = [
            'block' => ['warn', 'log_only'],
            'warn'  => ['block'],
        ];

        foreach ($actionsA as $actA) {
            foreach ($actionsB as $actB) {
                if (in_array($actB, $opposed[$actA] ?? [])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function conflictExists(int $idA, int $idB, string $type, ?int $ruleA = null, ?int $ruleB = null): bool
    {
        return PolicyConflict::query()
            ->where(fn($q) => $q->where('policy_a_id', $idA)->where('policy_b_id', $idB))
            ->orWhere(fn($q) => $q->where('policy_a_id', $idB)->where('policy_b_id', $idA))
            ->where('conflict_type', $type)
            ->when($ruleA, fn($q) => $q->where(fn($q2) =>
                $q2->where('rule_a_id', $ruleA)->where('rule_b_id', $ruleB)
                   ->orWhere('rule_a_id', $ruleB)->where('rule_b_id', $ruleA)
            ))
            ->whereIn('status', ['open', 'acknowledged'])
            ->exists();
    }

    private function persistConflict(
        Policy      $policyA,
        Policy      $policyB,
        ?PolicyRule $ruleA,
        ?PolicyRule $ruleB,
        string      $type,
        string      $desc,
        ?int        $by,
    ): PolicyConflict {
        return PolicyConflict::create([
            'policy_a_id'   => $policyA->id,
            'policy_b_id'   => $policyB->id,
            'rule_a_id'     => $ruleA?->id,
            'rule_b_id'     => $ruleB?->id,
            'conflict_type' => $type,
            'description'   => $desc,
            'status'        => 'open',
            'detected_by'   => $by,
        ]);
    }

    /**
     * Normalised character-level Jaccard similarity for names.
     */
    private function nameSimilarity(string $a, string $b): float
    {
        $a = $this->tokenise($a);
        $b = $this->tokenise($b);

        $intersection = count(array_intersect($a, $b));
        $union        = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /**
     * Word-level Jaccard similarity for clause content.
     */
    private function textSimilarity(string $a, string $b): float
    {
        $wordsA = array_unique(explode(' ', strtolower(trim($a))));
        $wordsB = array_unique(explode(' ', strtolower(trim($b))));

        $intersection = count(array_intersect($wordsA, $wordsB));
        $union        = count(array_unique(array_merge($wordsA, $wordsB)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    private function tokenise(string $str): array
    {
        return array_unique(preg_split('/\s+/', strtolower(trim($str))) ?: []);
    }
}
