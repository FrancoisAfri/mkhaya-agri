<?php

namespace App\Modules\Operations\Sheq\Ai;

use Illuminate\Support\Facades\DB;

/**
 * The release gate for the AI agents (§7.4).
 *
 * §7.4 sets two numbers — hallucination at or below 2%, citation precision at or above 95% — and a
 * held-out set of 200 incidents to measure them against. This is what computes them and what says
 * yes or no.
 *
 * The design decision that matters: the metrics are computed from **what practitioners actually did
 * with the drafts**, not from a scoring model. Using an LLM to grade an LLM produces a number that
 * moves when either model changes and correlates with neither correctness nor usefulness. A
 * practitioner rejecting a draft with "wrong root cause" is ground truth — they know the site, they
 * were there, and they carry the professional liability for the document. That signal is already
 * being collected, on every decision, by {@see AiSuggestionService}.
 *
 * This means the gate is only as good as the review volume behind it, and {@see gate()} says so
 * rather than returning a confident pass on four samples — a gate that passes on thin evidence is
 * worse than no gate, because it launders a guess into an approval.
 */
class EvaluationHarness
{
    /** §7.4: at most 2% of assertions may be unsupported. */
    public const MAX_HALLUCINATION_RATE = 0.02;

    /** §7.4: at least 95% of citations must point at material that supports the claim. */
    public const MIN_CITATION_PRECISION = 0.95;

    /** §7.4 specifies 200. Below it the percentages are noise dressed as measurement. */
    public const MIN_SAMPLE = 200;

    /**
     * Score an agent over a window.
     *
     * @return array{agent:string, sample:int, decided:int, hallucination_rate:float,
     *               citation_precision:float, acceptance_rate:float, edit_rate:float,
     *               rejection_rate:float, unsupported_assertions:int, total_assertions:int}
     */
    public function score(int $companyId, string $agent, int $days = 90): array
    {
        $since = now()->subDays($days);

        $rows = DB::table('sheq_ai_suggestions')
            ->where('company_id', $companyId)->where('agent', $agent)
            ->where('created_at', '>=', $since)->whereNull('deleted_at')
            ->get(['status', 'assertions', 'has_unverified_assertions']);

        $sample = $rows->count();

        // SUPERSEDED drafts were never looked at, so they carry no verdict and must not dilute the
        // rates — counting them would make an agent look better the more often it was re-run.
        $decided = $rows->whereIn('status', [
            AiSuggestionService::STATUS_ACCEPTED,
            AiSuggestionService::STATUS_EDITED,
            AiSuggestionService::STATUS_REJECTED,
        ]);

        $totalAssertions = 0;
        $unsupported = 0;
        $citedCorrectly = 0;
        $cited = 0;

        foreach ($rows as $row) {
            foreach (json_decode((string) $row->assertions, true) ?: [] as $assertion) {
                $totalAssertions++;

                if (empty($assertion['verified'])) {
                    $unsupported++;

                    continue;
                }

                $cited++;

                // A citation counts as precise when the claim carrying it survived review. A
                // rejected draft's citations are not evidence of anything — the practitioner is
                // telling us the reasoning was wrong, and the footnote came from that reasoning.
                if ($row->status !== AiSuggestionService::STATUS_REJECTED) {
                    $citedCorrectly++;
                }
            }
        }

        $decidedCount = $decided->count();

        return [
            'agent' => $agent,
            'sample' => $sample,
            'decided' => $decidedCount,
            'total_assertions' => $totalAssertions,
            'unsupported_assertions' => $unsupported,
            'hallucination_rate' => $totalAssertions === 0 ? 0.0
                : round($unsupported / $totalAssertions, 4),
            'citation_precision' => $cited === 0 ? 1.0 : round($citedCorrectly / $cited, 4),
            'acceptance_rate' => $decidedCount === 0 ? 0.0 : round(
                $decided->where('status', AiSuggestionService::STATUS_ACCEPTED)->count() / $decidedCount, 4),
            'edit_rate' => $decidedCount === 0 ? 0.0 : round(
                $decided->where('status', AiSuggestionService::STATUS_EDITED)->count() / $decidedCount, 4),
            'rejection_rate' => $decidedCount === 0 ? 0.0 : round(
                $decided->where('status', AiSuggestionService::STATUS_REJECTED)->count() / $decidedCount, 4),
        ];
    }

    /**
     * Would this agent pass §7.4's gate?
     *
     * Three outcomes, not two. `INSUFFICIENT_DATA` exists because the honest answer to "is this
     * safe to ship?" after eleven reviewed drafts is "we do not know yet", and collapsing that into
     * PASS is how a gate becomes theatre.
     *
     * @return array{verdict:string, reasons:list<string>, metrics:array}
     */
    public function gate(int $companyId, string $agent, int $days = 90): array
    {
        $metrics = $this->score($companyId, $agent, $days);
        $reasons = [];

        if ($metrics['decided'] < self::MIN_SAMPLE) {
            return [
                'verdict' => 'INSUFFICIENT_DATA',
                'reasons' => [sprintf(
                    '§7.4 requires a held-out set of %d; %d drafts from this agent have been '
                    .'reviewed by a person so far. The rates below are computed but should not be '
                    .'relied on for a release decision.',
                    self::MIN_SAMPLE, $metrics['decided'],
                )],
                'metrics' => $metrics,
            ];
        }

        if ($metrics['hallucination_rate'] > self::MAX_HALLUCINATION_RATE) {
            $reasons[] = sprintf(
                'Hallucination rate is %.2f%%, above the %.0f%% ceiling — %d of %d assertions '
                .'carried no supporting source.',
                $metrics['hallucination_rate'] * 100, self::MAX_HALLUCINATION_RATE * 100,
                $metrics['unsupported_assertions'], $metrics['total_assertions'],
            );
        }

        if ($metrics['citation_precision'] < self::MIN_CITATION_PRECISION) {
            $reasons[] = sprintf(
                'Citation precision is %.2f%%, below the %.0f%% floor.',
                $metrics['citation_precision'] * 100, self::MIN_CITATION_PRECISION * 100,
            );
        }

        // Not a §7.4 threshold, but a signal worth surfacing: an agent whose drafts are rejected
        // more often than not is costing practitioners time rather than saving it, whatever its
        // citation numbers say.
        if ($metrics['rejection_rate'] > 0.5) {
            $reasons[] = sprintf(
                'Practitioners reject %.0f%% of these drafts. The agent meets its citation targets '
                .'but is not doing useful work.', $metrics['rejection_rate'] * 100,
            );
        }

        return [
            'verdict' => $reasons === [] ? 'PASS' : 'FAIL',
            'reasons' => $reasons,
            'metrics' => $metrics,
        ];
    }

    /** @return list<string> */
    public function agents(int $companyId): array
    {
        return DB::table('sheq_ai_suggestions')->where('company_id', $companyId)
            ->distinct()->orderBy('agent')->pluck('agent')->all();
    }
}
