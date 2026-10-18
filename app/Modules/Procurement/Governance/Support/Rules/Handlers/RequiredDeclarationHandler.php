<?php

namespace App\Modules\Procurement\Governance\Support\Rules\Handlers;

use App\Modules\Procurement\Governance\Contracts\RuleHandler;
use App\Modules\Procurement\Governance\Support\Rules\RuleOutcome;
use App\Modules\Procurement\Governance\Support\Rules\RuleSubject;
use Illuminate\Support\Facades\DB;

/**
 * REQUIRED_DECLARATION — everyone who must declare, has (§5.3 "required declarations", §1.3).
 *
 * parameters:
 *   declaration     'coi' | 'confidentiality'   default 'coi'
 *   voting_only     bool                        default true — observers need not declare
 *   allow_recused   bool                        default true — a recused member is resolved, not outstanding
 *
 * Applies to tender reviews, where the declaring population is the review's evaluators. For any
 * other subject it skips rather than passes: a rule that silently passes on a subject it cannot
 * assess is worse than one that says it did not apply.
 *
 * A member with CONFLICT_IDENTIFIED that nobody has resolved fails the check even though they have
 * technically "declared" — §1.3 requires evaluation activity to stop until it is resolved, so an
 * unresolved conflict must block, not merely warn.
 */
class RequiredDeclarationHandler implements RuleHandler
{
    private const CLEARED = ['DECLARED', 'APPROVED_TO_PARTICIPATE'];

    public function evaluate(RuleSubject $subject, array $parameters): RuleOutcome
    {
        if ($subject->type !== 'procgov_tender_reviews') {
            return RuleOutcome::skip('Declarations apply to tender reviews; this subject has none.');
        }

        $declaration = $parameters['declaration'] ?? 'coi';
        $votingOnly = (bool) ($parameters['voting_only'] ?? true);
        $allowRecused = (bool) ($parameters['allow_recused'] ?? true);

        $query = DB::table('procgov_review_evaluators')
            ->where('company_id', $subject->companyId)
            ->where('review_id', $subject->id)
            ->whereNotIn('status', ['REMOVED']);

        if ($votingOnly) {
            $query->where('is_voting', true);
        }

        $evaluators = $query->get([
            'id', 'user_id', 'external_name', 'member_role',
            'coi_status', 'confidentiality_accepted_at', 'status',
        ]);

        if ($evaluators->isEmpty()) {
            return RuleOutcome::fail(
                'No evaluators are assigned to this review, so no declarations can have been made.',
                ['evaluators' => 0],
            );
        }

        if ($declaration === 'confidentiality') {
            $outstanding = $evaluators->filter(fn ($e) => $e->confidentiality_accepted_at === null);

            return $outstanding->isEmpty()
                ? RuleOutcome::pass()
                : RuleOutcome::fail(
                    "{$outstanding->count()} of {$evaluators->count()} committee members have not "
                    .'signed the confidentiality declaration.',
                    ['outstanding' => $outstanding->count(), 'total' => $evaluators->count()],
                );
        }

        $notCompleted = $evaluators->filter(fn ($e) => $e->coi_status === 'NOT_COMPLETED');
        $unresolved = $evaluators->filter(fn ($e) => $e->coi_status === 'CONFLICT_IDENTIFIED');
        $recused = $evaluators->filter(fn ($e) => $e->coi_status === 'RECUSED');

        $problems = [];

        if ($notCompleted->isNotEmpty()) {
            $problems[] = "{$notCompleted->count()} not completed";
        }

        if ($unresolved->isNotEmpty()) {
            $problems[] = "{$unresolved->count()} with an unresolved conflict";
        }

        if (! $allowRecused && $recused->isNotEmpty()) {
            $problems[] = "{$recused->count()} recused";
        }

        if ($problems === []) {
            return RuleOutcome::pass();
        }

        return RuleOutcome::fail(
            'Conflict-of-interest declarations are outstanding — '.implode(', ', $problems).'.',
            [
                'total' => $evaluators->count(),
                'cleared' => $evaluators->whereIn('coi_status', self::CLEARED)->count(),
                'not_completed' => $notCompleted->count(),
                'conflicts_open' => $unresolved->count(),
                'recused' => $recused->count(),
            ],
        );
    }
}
