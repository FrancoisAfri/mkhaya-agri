<?php

namespace App\Modules\Procurement\Governance\Services;

use App\Modules\Procurement\Governance\Support\Integration\CurrencyBridge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic scoring (§1.6): individual scores, averages, weighted scores, committee scores,
 * threshold results, financial ranking and overall ranking.
 *
 * Three things here are load-bearing.
 *
 * **Effective weight.** Criteria nest, and a sub-criterion's weight is a share of its parent's, not
 * of the whole. A sub-criterion worth 25% of a Technical criterion worth 40% is worth 10 points
 * overall. Every calculation uses that resolved effective weight, so a three-level criteria tree
 * scores identically to the flat one an evaluator would have built by hand.
 *
 * **Averaging happens per criterion, not per total.** Averaging evaluators' *totals* and averaging
 * their *per-criterion scores* give different answers whenever one evaluator skipped a criterion,
 * and the second is the defensible one: it is the committee's view of each criterion, combined once
 * by the agreed weighting. Doing it the other way lets an incomplete evaluation quietly reweight
 * the whole tender.
 *
 * **Nothing here writes a score.** It reads what evaluators recorded and computes what follows.
 * A "corrected" aggregate that does not follow from the underlying scores is the single most
 * damaging thing this module could produce, so aggregates are always derived and always recomputed
 * from scratch.
 *
 * Disqualified bidders are excluded from ranking but keep their scores, because §1.8 requires the
 * report to show the disqualification and its reason alongside what they had scored.
 */
class ScoringEngine
{
    public function __construct(
        private readonly AuditLedger $audit,
        private readonly CurrencyBridge $currency,
        private readonly PreferenceCalculator $preference,
    ) {}

    /**
     * Recompute one evaluator's totals for one bidder. Called on save and on submit; the result is
     * identical either way, which is what makes a draft's running total trustworthy.
     */
    public function computeEvaluation(int $companyId, int $evaluationId): void
    {
        $evaluation = DB::table('procgov_evaluations')->find($evaluationId);

        if (! $evaluation) {
            return;
        }

        $criteria = $this->criteriaTree($companyId, (int) $evaluation->review_id);
        $scores = DB::table('procgov_evaluation_scores')
            ->where('evaluation_id', $evaluationId)
            ->get()
            ->keyBy('criterion_id');

        $totalWeighted = 0.0;
        $totalRaw = 0.0;
        $thresholdPassed = true;

        foreach ($criteria['leaves'] as $criterion) {
            $score = $scores[$criterion->id] ?? null;

            if (! $score || $score->score === null) {
                continue;
            }

            $max = (float) ($score->max_score ?: $criterion->max_score ?: 100);
            $ratio = $max > 0 ? ((float) $score->score / $max) : 0.0;
            $effectiveWeight = (float) $criterion->effective_weight;

            $weighted = round($ratio * $effectiveWeight, 4);

            $totalRaw += (float) $score->score;
            $totalWeighted += $weighted;

            // Persist the weight that produced this number, so a later weighting change cannot
            // silently rewrite what this evaluator scored.
            DB::table('procgov_evaluation_scores')->where('id', $score->id)->update([
                'weight_applied' => $effectiveWeight,
                'weighted_score' => $weighted,
                'updated_at' => Carbon::now(),
            ]);

            if ($criterion->minimum_threshold !== null && ($ratio * 100) < (float) $criterion->minimum_threshold) {
                $thresholdPassed = false;
            }

            // A mandatory or pass/fail criterion that was failed sinks the whole evaluation.
            if (($criterion->is_mandatory || $criterion->is_pass_fail) && $score->passed === false) {
                $thresholdPassed = false;
            }
        }

        DB::table('procgov_evaluations')->where('id', $evaluationId)->update([
            'total_raw_score' => round($totalRaw, 4),
            'total_weighted_score' => round($totalWeighted, 4),
            'threshold_passed' => $thresholdPassed,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Recompute every bidder's committee score and ranking for a review.
     *
     * @return array<int,array<string,mixed>> keyed by bidder id, for immediate display
     */
    public function computeReview(int $companyId, int $reviewId): array
    {
        $review = DB::table('procgov_tender_reviews')->find($reviewId);

        if (! $review) {
            return [];
        }

        $criteria = $this->criteriaTree($companyId, $reviewId);

        $bidders = DB::table('procgov_bidders')
            ->where('company_id', $companyId)
            ->where('review_id', $reviewId)
            ->whereNull('deleted_at')
            ->get();

        // Bids arrive in the bidder's currency. Everything downstream — lowest price, price points,
        // financial rank — compares them against each other, so they are brought into one currency
        // first. Skipping this does not fail; it silently ranks 900,000 BWP above 1,000,000 ZAR.
        $this->normaliseAmounts($companyId, $review, $bidders);

        $framework = $this->preference->frameworkFor($companyId, $review, $this->tenantJurisdiction($companyId));

        // Only submitted evaluations count towards a committee score. A draft is one evaluator's
        // work in progress and must not move a ranking that other people are looking at.
        $evaluations = DB::table('procgov_evaluations')
            ->where('company_id', $companyId)
            ->where('review_id', $reviewId)
            ->where('status', 'SUBMITTED')
            ->get()
            ->groupBy('bidder_id');

        $scoreRows = DB::table('procgov_evaluation_scores as s')
            ->join('procgov_evaluations as e', 'e.id', '=', 's.evaluation_id')
            ->where('e.review_id', $reviewId)
            ->where('e.status', 'SUBMITTED')
            ->get(['s.evaluation_id', 's.criterion_id', 's.score', 's.max_score', 's.passed', 'e.bidder_id'])
            ->groupBy('bidder_id');

        $results = [];

        foreach ($bidders as $bidder) {
            $bidderEvaluations = $evaluations[$bidder->id] ?? collect();
            $bidderScores = $scoreRows[$bidder->id] ?? collect();

            $groupTotals = $this->groupTotals($criteria, $bidderScores);

            $technical = $groupTotals['TECHNICAL'] ?? null;
            $financialFromCriteria = $groupTotals['FINANCIAL'] ?? null;
            $preference = $groupTotals['PREFERENCE'] ?? null;

            $weighted = array_sum(array_filter($groupTotals, fn ($v) => $v !== null));

            $average = $bidderEvaluations->count() > 0
                ? round((float) $bidderEvaluations->avg('total_weighted_score'), 4)
                : null;

            // Every submitted evaluator must have cleared the thresholds, and a bidder who failed
            // an administrative/mandatory gate never reaches scoring at all.
            $thresholdPassed = $bidderEvaluations->count() > 0
                ? ! $bidderEvaluations->contains(fn ($e) => $e->threshold_passed === false)
                : null;

            if ($bidder->mandatory_passed === false) {
                $thresholdPassed = false;
            }

            $results[$bidder->id] = [
                'technical_score' => $technical,
                'financial_score' => $financialFromCriteria,
                // Scored preference criteria win: if a committee actually assessed preference, that
                // assessment is the answer. Otherwise the framework derives it from what is on
                // record, and only failing both do we fall back to the typed-in number.
                'preference_score' => $preference !== null
                    ? round($preference, 4)
                    : $this->derivedPreference($companyId, $framework, $bidder),
                'average_score' => $average,
                'weighted_score' => round($weighted, 4),
                'threshold_passed' => $thresholdPassed,
                'evaluator_count' => $bidderEvaluations->count(),
            ];
        }

        $results = $this->applyMethodology($review, $bidders, $results);
        $results = $this->rank($bidders, $results);

        foreach ($results as $bidderId => $values) {
            DB::table('procgov_bidders')->where('id', $bidderId)->update([
                'technical_score' => $values['technical_score'],
                'financial_score' => $values['financial_score'],
                'preference_score' => $values['preference_score'],
                'average_score' => $values['average_score'],
                'weighted_score' => $values['weighted_score'],
                'threshold_passed' => $values['threshold_passed'],
                'financial_rank' => $values['financial_rank'],
                'overall_rank' => $values['overall_rank'],
                'scores_computed_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        $this->audit->record($companyId, [
            'action' => 'scores_computed',
            'capability' => 'tender_evaluation',
            'entity_type' => 'procgov_tender_reviews',
            'entity_id' => $reviewId,
            'entity_reference' => $review->reference,
            'record_id' => $review->record_id,
            'summary' => "Recomputed committee scores and ranking for {$review->reference}",
        ]);

        return $results;
    }

    /**
     * §1.7: where evaluators disagree materially on a criterion. Returns one entry per
     * (bidder, criterion) whose spread exceeds the tenant's configured threshold, expressed in
     * percentage points of the criterion's maximum so criteria with different maxima are
     * comparable.
     *
     * @return list<array<string,mixed>>
     */
    public function variances(int $companyId, int $reviewId, float $thresholdPct): array
    {
        $rows = DB::table('procgov_evaluation_scores as s')
            ->join('procgov_evaluations as e', 'e.id', '=', 's.evaluation_id')
            ->join('procgov_criteria as c', 'c.id', '=', 's.criterion_id')
            ->join('procgov_bidders as b', 'b.id', '=', 'e.bidder_id')
            ->where('e.review_id', $reviewId)
            ->where('e.status', 'SUBMITTED')
            ->whereNotNull('s.score')
            ->get([
                'e.bidder_id', 'b.supplier_name', 's.criterion_id', 'c.name as criterion_name',
                's.score', 's.max_score', 'e.evaluator_id', 'e.id as evaluation_id',
            ]);

        $out = [];

        foreach ($rows->groupBy(fn ($r) => $r->bidder_id.':'.$r->criterion_id) as $group) {
            $max = (float) ($group->first()->max_score ?: 100);

            if ($max <= 0 || $group->count() < 2) {
                continue;
            }

            $lowest = (float) $group->min('score');
            $highest = (float) $group->max('score');
            $spreadPct = (($highest - $lowest) / $max) * 100;

            if ($spreadPct < $thresholdPct) {
                continue;
            }

            $out[] = [
                'bidder_id' => (int) $group->first()->bidder_id,
                'supplier_name' => $group->first()->supplier_name,
                'criterion_id' => (int) $group->first()->criterion_id,
                'criterion_name' => $group->first()->criterion_name,
                'lowest_score' => $lowest,
                'highest_score' => $highest,
                'variance' => round($spreadPct, 2),
                'evaluator_ids' => $group->pluck('evaluator_id')->unique()->values()->all(),
            ];
        }

        usort($out, fn ($a, $b) => $b['variance'] <=> $a['variance']);

        return $out;
    }

    /**
     * The criteria tree with effective weights resolved, plus the leaves that actually carry
     * scores. Parents are containers: scoring both a parent and its children would double-count.
     *
     * @return array{all:Collection, leaves:Collection}
     */
    public function criteriaTree(int $companyId, int $reviewId): array
    {
        $all = DB::table('procgov_criteria')
            ->where('company_id', $companyId)
            ->where('review_id', $reviewId)
            ->whereNull('deleted_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 0);

        $resolve = function (Collection $nodes, float $parentWeight, ?string $inheritedGroup) use (&$resolve, $byParent) {
            foreach ($nodes as $node) {
                // A top-level criterion's weight is a share of 100; a sub-criterion's is a share of
                // its parent's effective weight.
                $node->effective_weight = round($parentWeight * ((float) $node->weight / 100), 6);
                $node->criterion_group = $node->criterion_group ?: $inheritedGroup;

                $children = $byParent[$node->id] ?? collect();
                $node->children = $children;

                if ($children->isNotEmpty()) {
                    $resolve($children, $node->effective_weight, $node->criterion_group);
                }
            }
        };

        $roots = $byParent[0] ?? collect();
        $resolve($roots, 100.0, null);

        $leaves = $all->filter(fn ($c) => ($byParent[$c->id] ?? collect())->isEmpty())->values();

        return ['all' => $all, 'roots' => $roots, 'leaves' => $leaves];
    }

    /**
     * §1.4 validation: weights must sum to 100 at every level, or the arithmetic below is
     * meaningless. Returns the problems, empty when the tree is sound.
     *
     * @return list<string>
     */
    public function validateWeights(int $companyId, int $reviewId): array
    {
        $all = DB::table('procgov_criteria')
            ->where('company_id', $companyId)
            ->where('review_id', $reviewId)
            ->whereNull('deleted_at')
            ->get();

        if ($all->isEmpty()) {
            return ['No evaluation criteria have been configured.'];
        }

        $problems = [];

        foreach ($all->groupBy(fn ($c) => $c->parent_id ?? 0) as $parentId => $siblings) {
            $sum = round((float) $siblings->sum('weight'), 4);

            if (abs($sum - 100) < 0.01) {
                continue;
            }

            $where = $parentId === 0
                ? 'Top-level criteria'
                : 'Sub-criteria of "'.($all->firstWhere('id', $parentId)->name ?? '?').'"';

            $problems[] = "{$where} weights total {$sum}%, and must total 100%.";
        }

        return $problems;
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Committee score per criterion group: average each criterion across evaluators first, then
     * apply the weighting once. See the class docblock for why this order matters.
     *
     * @return array<string,float|null>
     */
    private function groupTotals(array $criteria, Collection $bidderScores): array
    {
        $byCriterion = $bidderScores->groupBy('criterion_id');
        $totals = [];

        foreach ($criteria['leaves'] as $criterion) {
            $group = $criterion->criterion_group ?: 'TECHNICAL';
            $totals[$group] ??= null;

            $scores = $byCriterion[$criterion->id] ?? collect();
            $scored = $scores->filter(fn ($s) => $s->score !== null);

            if ($scored->isEmpty()) {
                continue;
            }

            $max = (float) ($scored->first()->max_score ?: $criterion->max_score ?: 100);

            if ($max <= 0) {
                continue;
            }

            $averageRatio = (float) $scored->avg('score') / $max;

            $totals[$group] = ($totals[$group] ?? 0) + round($averageRatio * (float) $criterion->effective_weight, 4);
        }

        return array_map(fn ($v) => $v === null ? null : round($v, 4), $totals);
    }

    /**
     * The figure a bid is compared on: the converted amount where one exists, the bid as made
     * otherwise.
     *
     * The fallback is not a shortcut. A single-currency tender never needs conversion, and existing
     * tenders predate the column entirely — both must keep ranking exactly as they do now. Where
     * conversion was genuinely required and could not be done, normaliseAmounts() has already
     * refused, so this never falls back on a bid it should have converted.
     */
    private function comparable(object $bidder): ?float
    {
        $amount = $bidder->bid_amount_base ?? $bidder->bid_amount ?? null;

        return $amount === null ? null : (float) $amount;
    }

    private function tenantJurisdiction(int $companyId): string
    {
        if (! Schema::hasColumn('procgov_settings', 'jurisdiction')) {
            return 'ZA';
        }

        $value = DB::table('procgov_settings')->where('company_id', $companyId)->value('jurisdiction');

        return $value ?: 'ZA';
    }

    /**
     * Bring every bid into the tender's currency, and record how.
     *
     * The rate, its date and its source are written against the bid rather than only the converted
     * figure, because "why was this bidder ranked second" has to be answerable a year later, when
     * the rate has moved. A conversion nobody can reproduce is not evidence.
     *
     * Refuses outright when a rate is missing rather than ranking on whatever is in the column:
     * an unconvertible bid compared as though it were the tender currency is exactly the silent
     * wrong award this exists to prevent.
     */
    private function normaliseAmounts(int $companyId, object $review, Collection $bidders): void
    {
        if (! Schema::hasColumn('procgov_bidders', 'bid_amount_base')) {
            return;
        }

        $target = strtoupper((string) ($review->currency ?: 'ZAR'));
        $basis = Schema::hasColumn('procgov_settings', 'fx_rate_basis')
            ? (DB::table('procgov_settings')->where('company_id', $companyId)->value('fx_rate_basis') ?: 'CLOSING')
            : 'CLOSING';
        $rateType = Schema::hasColumn('procgov_settings', 'fx_rate_type')
            ? (DB::table('procgov_settings')->where('company_id', $companyId)->value('fx_rate_type') ?: 'spot')
            : 'spot';

        $on = $this->currency->rateDate($review, $basis);

        foreach ($bidders as $bidder) {
            if ($bidder->bid_amount === null) {
                continue;
            }

            $from = strtoupper((string) ($bidder->currency ?: $target));

            // Same money: the converted figure is the bid, and saying so explicitly keeps every
            // bidder in a tender carrying a comparable amount rather than some of them.
            if ($from === $target) {
                $this->recordConversion($bidder, (float) $bidder->bid_amount, 1.0, $on->toDateString(), 'identity');

                continue;
            }

            $converted = $this->currency->convert(
                $companyId,
                (float) $bidder->bid_amount,
                $from,
                $target,
                $basis === 'SUBMISSION' && $bidder->submitted_at
                    ? Carbon::parse($bidder->submitted_at)->startOfDay()
                    : $on,
                $rateType,
            );

            $this->recordConversion($bidder, $converted['amount'], $converted['rate'], $converted['date'], $converted['source']);
        }
    }

    private function recordConversion(object $bidder, float $amount, float $rate, string $date, string $source): void
    {
        // Mutated in place as well as persisted, because the ranking that follows in this same pass
        // reads from the collection, not from the database.
        $bidder->bid_amount_base = $amount;
        $bidder->fx_rate = $rate;
        $bidder->fx_rate_date = $date;

        DB::table('procgov_bidders')->where('id', $bidder->id)->update([
            'bid_amount_base' => $amount,
            'fx_rate' => $rate,
            'fx_rate_date' => $date,
            'fx_rate_source' => $source,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Preference points derived from the tenant's configured framework.
     *
     * Falls back to the captured number when no framework applies, so an installation that has not
     * configured one keeps working exactly as it did.
     */
    private function derivedPreference(int $companyId, ?object $framework, object $bidder): ?float
    {
        if (! $framework) {
            return $bidder->preference_points !== null ? (float) $bidder->preference_points : null;
        }

        $result = $this->preference->pointsFor($companyId, $framework, $bidder);

        return $result['points'] ?? ($bidder->preference_points !== null ? (float) $bidder->preference_points : null);
    }

    /**
     * §1.1's evaluation methodologies. Each decides what the headline number is; the criteria
     * scores are computed identically in every case, which keeps the audit trail uniform.
     */
    private function applyMethodology(object $review, Collection $bidders, array $results): array
    {
        $priceWeight = $review->price_weight !== null ? (float) $review->price_weight : 80.0;
        $preferenceWeight = $review->preference_weight !== null ? (float) $review->preference_weight : 20.0;

        // The lowest *acceptable* price is the comparator: a disqualified bid must not set the
        // benchmark every compliant bidder is then measured against. Comparison is on the converted
        // amount, never the amount as bid — see normaliseAmounts().
        $eligible = $bidders->filter(fn ($b) => ! $b->is_disqualified && $this->comparable($b) !== null);
        $lowest = $eligible->map(fn ($b) => $this->comparable($b))->min();

        foreach ($bidders as $bidder) {
            $r = &$results[$bidder->id];

            switch ($review->methodology) {
                case 'PRICE_ONLY':
                    // Ranking is by price alone; the score is a normalised 0-100 for display.
                    $r['weighted_score'] = ($lowest && $this->comparable($bidder))
                        ? round(((float) $lowest / (float) $this->comparable($bidder)) * 100, 4)
                        : null;
                    $r['financial_score'] = $r['weighted_score'];
                    break;

                case 'PRICE_PREFERENCE':
                    /*
                     * Price points fall away as a bid exceeds the lowest, in proportion to the
                     * excess: at the lowest price a bidder takes the full price weight, and a bid
                     * twice the lowest takes none. Preference points are whatever the tenant
                     * captured or scored — this module does not embed any preference-point table,
                     * because that table is exactly the regulation §5.3 says not to hard-code.
                     */
                    $pricePoints = ($lowest && $this->comparable($bidder))
                        ? max(0.0, $priceWeight * (1 - (((float) $this->comparable($bidder) - (float) $lowest) / (float) $lowest)))
                        : 0.0;

                    $preferencePoints = $r['preference_score'] !== null
                        ? min((float) $r['preference_score'], $preferenceWeight)
                        : 0.0;

                    $r['financial_score'] = round($pricePoints, 4);
                    $r['preference_score'] = round($preferencePoints, 4);
                    $r['weighted_score'] = round($pricePoints + $preferencePoints, 4);
                    break;

                case 'TWO_ENVELOPE':
                    // The financial envelope is only opened for bidders who cleared the technical
                    // threshold — so a failing bidder keeps a technical score and has no overall one.
                    if ($r['threshold_passed'] === false) {
                        $r['financial_score'] = null;
                        $r['weighted_score'] = $r['technical_score'];
                    }
                    break;

                case 'WEIGHTED':
                default:
                    // weighted_score is already the sum of the weighted group totals.
                    break;
            }

            unset($r);
        }

        return $results;
    }

    /** §1.6 financial ranking and overall ranking, with ties sharing a rank. */
    private function rank(Collection $bidders, array $results): array
    {
        foreach ($results as $id => $values) {
            $results[$id]['financial_rank'] = null;
            $results[$id]['overall_rank'] = null;
        }

        $eligible = $bidders->filter(function ($b) use ($results) {
            return ! $b->is_disqualified
                && ($results[$b->id]['threshold_passed'] ?? null) !== false;
        });

        // Financial ranking: cheapest first.
        $byPrice = $eligible->filter(fn ($b) => $this->comparable($b) !== null)
            ->sortBy(fn ($b) => (float) $this->comparable($b))
            ->values();

        $rank = 0;
        $seen = 0;
        $previous = null;

        foreach ($byPrice as $bidder) {
            $seen++;
            $amount = (float) $this->comparable($bidder);

            if ($previous === null || abs($amount - $previous) > 0.0001) {
                $rank = $seen;
                $previous = $amount;
            }

            $results[$bidder->id]['financial_rank'] = $rank;
        }

        // Overall ranking: highest score first.
        $byScore = $eligible->filter(fn ($b) => ($results[$b->id]['weighted_score'] ?? null) !== null)
            ->sortByDesc(fn ($b) => (float) $results[$b->id]['weighted_score'])
            ->values();

        $rank = 0;
        $seen = 0;
        $previous = null;

        foreach ($byScore as $bidder) {
            $seen++;
            $score = (float) $results[$bidder->id]['weighted_score'];

            if ($previous === null || abs($score - $previous) > 0.0001) {
                $rank = $seen;
                $previous = $score;
            }

            $results[$bidder->id]['overall_rank'] = $rank;
        }

        return $results;
    }
}
