<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierFinding;
/**
 * A reputation score out of 100, where higher is worse.
 *
 * The specification asks for nine things to be weighed, and they matter because a naive count fails
 * in a specific, expensive way: fifteen customers complaining about delivery times outscores one
 * regulator opening an investigation, and the supplier who should have been stopped is not.
 *
 * So each finding contributes according to:
 *
 *  - severity          — critical counts for four times what low does
 *  - category          — a bribery allegation is not a late-delivery complaint
 *  - regulatory weight — a regulator acting is worth more than volume of grumbling
 *  - recency           — a five-year-old story is history, not news; it decays rather than vanishing
 *  - source credibility— a national title outweighs an anonymous forum post
 *  - corroboration     — the same story from three outlets is stronger than from one
 *  - confirmation      — a person confirming it outweighs the machine's own confidence
 *  - entity confidence — a finding we are only 62% sure is about this supplier counts 62% as much
 *
 * Positive findings pull the score down, but only so far. A supplier cannot buy its way out of a
 * fraud investigation by winning contracts, so the offset is capped — and applies at all only because
 * a score that can never improve is one nobody trusts.
 */
class ReputationScore
{
    /** The most that everything after the worst finding can add. Volume is a tail, not the story. */
    private const MAX_TAIL = 25.0;

    /** The most that positive coverage can pull a score down. */
    private const MAX_POSITIVE_OFFSET = 12.0;

    /** Sources whose word carries more weight, keyed by a fragment of the source name. */
    private const CREDIBLE_SOURCES = [
        'reuters' => 1.0, 'bloomberg' => 1.0, 'financial times' => 1.0, 'ft.com' => 1.0,
        'news24' => 0.95, 'businesslive' => 0.95, 'business day' => 0.95, 'moneyweb' => 0.95,
        'daily maverick' => 0.95, 'mail & guardian' => 0.9, 'timeslive' => 0.9, 'iol' => 0.85,
        'sabc' => 0.9, 'engineering news' => 0.85, 'gov.za' => 1.0, 'sars' => 1.0, 'cipc' => 1.0,
    ];

    /**
     * Score one supplier's reputation, with the reasons.
     *
     * @return array{score:float, band:string, findings:int, negative:int, positive:int,
     *               unreviewed:int, drivers:array<int,array<string,mixed>>, coverage:bool}
     */
    public function for(Supplier $supplier): array
    {
        $findings = SupplierFinding::query()
            ->where('supplier_id', $supplier->id)
            ->live()
            ->get();

        if ($findings->isEmpty()) {
            // No coverage is not a clean record. The caller decides what to do with that; saying
            // "0 — excellent" here would be the system inventing good news.
            return [
                'score' => 0.0,
                'band' => 'unknown',
                'findings' => 0,
                'negative' => 0,
                'positive' => 0,
                'unreviewed' => 0,
                'drivers' => [],
                'coverage' => false,
            ];
        }

        $negative = $findings->filter(fn (SupplierFinding $f) => ! $f->is_positive
            && ! in_array($f->category, ['positive', 'neutral'], true));

        $scored = $negative
            ->map(fn (SupplierFinding $finding) => [
                'finding' => $finding,
                'value' => $this->weigh($finding),
            ])
            ->sortByDesc('value')
            ->values();

        // The worst thing known about a supplier sets the score, and volume adds a bounded amount on
        // top of it.
        //
        // The obvious alternative — accumulate every finding with diminishing returns — was tried and
        // is wrong in the direction that matters: fifteen low-severity customer complaints on social
        // media accumulate past a single high-severity regulatory action, and the supplier who should
        // have been stopped is not. Volume of grumbling is a real signal and it is a weaker one than
        // a regulator acting, so it can only ever be a tail.
        // Nothing negative on file is a real state, not an edge case: a supplier can be covered by
        // the press entirely for winning work.
        $worst = (float) ($scored->first()['value'] ?? 0.0);

        $tail = 0.0;
        foreach ($scored->slice(1)->values() as $index => $entry) {
            // Halving per finding: the second-worst matters, the ninth barely does.
            $tail += $entry['value'] * (0.5 ** ($index + 1));
        }

        $total = min(100.0, $worst + min(self::MAX_TAIL, $tail));

        $positives = $findings->count() - $negative->count();
        $offset = min(self::MAX_POSITIVE_OFFSET, $positives * 3.0);

        $score = round(max(0.0, min(100.0, $total - $offset)), 2);

        return [
            'score' => $score,
            'band' => $this->band($score),
            'findings' => $findings->count(),
            'negative' => $negative->count(),
            'positive' => $positives,
            'unreviewed' => $findings->whereIn('status', [SupplierFinding::STATUS_OPEN, SupplierFinding::STATUS_NEEDS_REVIEW])->count(),
            'drivers' => $scored->take(5)->map(fn ($entry) => [
                'id' => $entry['finding']->id,
                'title' => $entry['finding']->title,
                'category' => $entry['finding']->categoryLabel(),
                'severity' => $entry['finding']->severity,
                'published_at' => $entry['finding']->published_at?->toDateString(),
                'contribution' => round($entry['value'], 1),
            ])->all(),
            'coverage' => true,
        ];
    }

    /** Just the number, for the risk engine's reputation component. */
    public function score(Supplier $supplier): float
    {
        return $this->for($supplier)['score'];
    }

    /**
     * What one finding is worth, before aggregation.
     *
     * Multiplicative rather than additive: a critical finding we are barely sure is about this
     * supplier should not count as critical, and a nine-year-old one should not count as news. Any
     * factor near zero should be able to reduce the whole thing to near zero, which is what
     * multiplication does and addition does not.
     */
    private function weigh(SupplierFinding $finding): float
    {
        $severity = SupplierFinding::SEVERITIES[$finding->severity ?? 'none'] ?? 0.0;
        $category = SupplierFinding::CATEGORIES[$finding->category ?? 'neutral']['weight'] ?? 0.4;

        // A finding with no severity but a serious category still counts for something: the category
        // is the sturdier signal, since severity is the machine's opinion.
        $base = max($severity, $category * 0.6) * 100;

        $value = $base
            * $this->recency($finding)
            * $this->credibility($finding)
            * $this->corroboration($finding)
            * $this->confirmation($finding)
            * ($finding->entity_confidence / 100);

        // A regulator acting is not a matter of opinion.
        if ($finding->isRegulatory()) {
            $value *= 1.25;
        }

        return max(0.0, $value);
    }

    /**
     * Recent news counts fully; old news decays to a floor rather than to nothing.
     *
     * The floor is the point — a conviction from four years ago is not irrelevant, it is simply not
     * current, and a decay that reaches zero would let a supplier outlive its own record.
     */
    private function recency(SupplierFinding $finding): float
    {
        $published = $finding->published_at ?? $finding->first_seen_at;

        if (! $published) {
            return 0.7;
        }

        $months = max(0, $published->diffInMonths(now()));

        return match (true) {
            $months <= 3 => 1.0,
            $months <= 12 => 0.85,
            $months <= 24 => 0.65,
            $months <= 60 => 0.45,
            default => 0.3,
        };
    }

    /** A national title's report and an anonymous forum post are not the same evidence. */
    private function credibility(SupplierFinding $finding): float
    {
        $source = strtolower(trim((string) ($finding->source_name ?? '')));

        foreach (self::CREDIBLE_SOURCES as $needle => $weight) {
            if ($source !== '' && str_contains($source, $needle)) {
                return $weight;
            }
        }

        return match ($finding->channel) {
            'regulatory' => 1.0,
            'news' => 0.8,
            'web' => 0.65,
            // Anonymous and unverifiable by default. A social post can still be the first sign of a
            // real problem, which is why this is 0.5 and not 0.
            'social' => 0.5,
            'forum' => 0.4,
            default => 0.6,
        };
    }

    /** Independent sources reporting the same thing make it likelier to be true. */
    private function corroboration(SupplierFinding $finding): float
    {
        return match (true) {
            $finding->corroborations >= 3 => 1.3,
            $finding->corroborations === 2 => 1.15,
            $finding->corroborations === 1 => 1.05,
            default => 1.0,
        };
    }

    /**
     * A person's verdict outweighs the machine's confidence.
     *
     * Confirmed counts fully. Unreviewed counts at the machine's own confidence in its reading, so an
     * uncertain classification does not carry the weight of a certain one until somebody agrees with
     * it. Needs-review counts least: we are not yet sure it is even the right company.
     */
    private function confirmation(SupplierFinding $finding): float
    {
        return match ($finding->status) {
            SupplierFinding::STATUS_CONFIRMED => 1.0,
            SupplierFinding::STATUS_NEEDS_REVIEW => 0.5,
            default => max(0.4, ($finding->analysis_confidence ?? 50) / 100),
        };
    }

    /** The bands from the specification. */
    private function band(float $score): string
    {
        return match (true) {
            $score <= 20 => 'excellent',
            $score <= 40 => 'low',
            $score <= 60 => 'moderate',
            $score <= 80 => 'high',
            default => 'critical',
        };
    }
}
