<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use Illuminate\Support\Str;

/**
 * Decides how strongly a name off a list matches the person or company in front of you.
 *
 * Both failure modes here are expensive and only one of them is obvious. Match too loosely and every
 * supplier with a common surname generates a sanctions alert, the queue fills with noise, and within
 * two months people clear hits without reading them — at which point the real one gets cleared too.
 * Match too tightly and a name written "Mohammed" instead of "Muhammad", or a registry that puts the
 * surname first, walks straight through.
 *
 * So matching is scored rather than decided, and the score is built from stated reasons a person can
 * check: how close the names are, and whether a date of birth, nationality or identifier corroborates
 * or contradicts. A contradiction is what makes this useful — a same-name hit whose date of birth is
 * twenty years out is not a maybe, it is a no, and saying so is how the queue stays readable.
 */
class ScreeningMatcher
{
    /** At or above this, a candidate is worth putting in front of a person. */
    public const REPORT_THRESHOLD = 70;

    /** Transliteration variants that routinely appear across list sources. */
    private const EQUIVALENTS = [
        ['mohammed', 'mohamed', 'muhammad', 'mohammad', 'muhammed'],
        ['abdul', 'abdel', 'abd al'],
        ['yusuf', 'yousef', 'yousuf', 'joseph'],
        ['ibrahim', 'ebrahim'],
        ['ahmed', 'ahmad'],
        ['hassan', 'hasan'],
        ['sergei', 'sergey', 'serge'],
        ['aleksandr', 'alexander', 'alexandr'],
        ['dmitri', 'dmitry', 'dimitri'],
        ['jan', 'johan', 'john'],
        ['pieter', 'peter', 'petrus'],
        ['thabo', 'tabo'],
    ];

    public function __construct(private readonly FieldComparator $comparator) {}

    /**
     * Score a candidate against a subject.
     *
     * @param  array{name:string, dob?:string|null, nationality?:string|null, identifier?:string|null}  $subject
     * @param  array{name:string, dob?:string|null, nationality?:string|null, identifier?:string|null, aliases?:array<int,string>}  $candidate
     * @return array{score:int, basis:array<int,array{factor:string,effect:string,detail:string}>, reportable:bool}
     */
    public function score(array $subject, array $candidate): array
    {
        $basis = [];

        $nameScore = $this->bestNameScore($subject['name'] ?? '', $candidate);
        $basis[] = [
            'factor' => 'name',
            'effect' => $nameScore >= 90 ? 'strong' : ($nameScore >= 75 ? 'partial' : 'weak'),
            'detail' => sprintf('Names are %d%% alike.', $nameScore),
        ];

        $score = $nameScore;

        // An identifier settles it in either direction — this is the single most valuable field on
        // the record, and the reason Phase 1 collects it.
        if (! empty($subject['identifier']) && ! empty($candidate['identifier'])) {
            if ($this->digits($subject['identifier']) === $this->digits($candidate['identifier'])) {
                $basis[] = ['factor' => 'identifier', 'effect' => 'confirms', 'detail' => 'Identity number matches exactly.'];

                return ['score' => 100, 'basis' => $basis, 'reportable' => true];
            }

            $basis[] = ['factor' => 'identifier', 'effect' => 'contradicts', 'detail' => 'Identity numbers differ.'];
            $score -= 45;
        }

        if (! empty($subject['dob']) && ! empty($candidate['dob'])) {
            $same = $this->sameDate($subject['dob'], $candidate['dob']);
            $basis[] = [
                'factor' => 'date_of_birth',
                'effect' => $same ? 'confirms' : 'contradicts',
                'detail' => $same ? 'Dates of birth agree.' : 'Dates of birth differ.',
            ];
            $score += $same ? 12 : -35;
        }

        if (! empty($subject['nationality']) && ! empty($candidate['nationality'])) {
            $same = Str::upper($subject['nationality']) === Str::upper($candidate['nationality']);
            $basis[] = [
                'factor' => 'nationality',
                'effect' => $same ? 'confirms' : 'weakens',
                'detail' => $same ? 'Nationalities agree.' : 'Nationalities differ.',
            ];
            $score += $same ? 6 : -12;
        }

        // A name-only match on a common name is exactly the kind of hit that floods a queue, so it is
        // scored down and said so.
        if ($this->isCommonName($subject['name'] ?? '') && ! $this->corroborated($basis)) {
            $basis[] = [
                'factor' => 'commonality',
                'effect' => 'weakens',
                'detail' => 'A common name with nothing else to corroborate it.',
            ];
            $score -= 10;
        }

        $score = (int) max(0, min(100, round($score)));

        return ['score' => $score, 'basis' => $basis, 'reportable' => $score >= self::REPORT_THRESHOLD];
    }

    /**
     * Filter a provider's candidate list down to what is worth a person's time.
     *
     * @return array<int, array<string,mixed>> each candidate with its score and basis attached
     */
    public function rank(array $subject, array $candidates): array
    {
        $scored = [];

        foreach ($candidates as $candidate) {
            $result = $this->score($subject, $candidate);

            if (! $result['reportable']) {
                continue;
            }

            $scored[] = $candidate + ['match_score' => $result['score'], 'match_basis' => $result['basis']];
        }

        usort($scored, fn ($a, $b) => $b['match_score'] <=> $a['match_score']);

        return $scored;
    }

    /** The best score across the candidate's primary name and any aliases it carries. */
    private function bestNameScore(string $subjectName, array $candidate): int
    {
        $names = array_filter([$candidate['name'] ?? '', ...($candidate['aliases'] ?? [])]);
        $best = 0;

        foreach ($names as $name) {
            $best = max($best, $this->nameSimilarity($subjectName, (string) $name));
        }

        return $best;
    }

    /**
     * How alike two personal names are, ignoring word order, honorifics and spelling variants.
     *
     * Word order is ignored because registries and forms disagree about it constantly ("SMITH, John"
     * against "John Smith"); treating that as a difference would mean every registry-sourced hit
     * scored low.
     */
    private function nameSimilarity(string $a, string $b): int
    {
        $left = $this->tokens($a);
        $right = $this->tokens($b);

        if ($left === [] || $right === []) {
            return 0;
        }

        $matched = 0;

        foreach ($left as $token) {
            foreach ($right as $other) {
                if ($token === $other || $this->equivalent($token, $other)) {
                    $matched++;

                    break;
                }

                // An initial standing in for a full first name is a match on that token, not a miss.
                if ((strlen($token) === 1 || strlen($other) === 1) && $token[0] === $other[0]) {
                    $matched++;

                    break;
                }
            }
        }

        $coverage = $matched / max(count($left), count($right));

        // Whole-string similarity as a tiebreaker, so "Johnathan Smith" beats "Jane Smith" on a
        // subject of "Jonathan Smith" even though both match one token.
        similar_text(implode(' ', $left), implode(' ', $right), $percent);

        return (int) round(($coverage * 80) + ($percent * 0.2));
    }

    private function tokens(string $name): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', $this->comparator->normalisePersonName($name)) ?: [],
            fn ($token) => $token !== ''
        ));
    }

    private function equivalent(string $a, string $b): bool
    {
        foreach (self::EQUIVALENTS as $group) {
            if (in_array($a, $group, true) && in_array($b, $group, true)) {
                return true;
            }
        }

        // Same sound, different spelling — the last line of defence against transliteration.
        return metaphone($a) !== '' && metaphone($a) === metaphone($b);
    }

    private function corroborated(array $basis): bool
    {
        foreach ($basis as $entry) {
            if ($entry['effect'] === 'confirms') {
                return true;
            }
        }

        return false;
    }

    /**
     * Surnames common enough that a name-only match means very little on its own. Deliberately short
     * and South-Africa-weighted rather than an attempt at a global census.
     */
    private function isCommonName(string $name): bool
    {
        $common = ['smith', 'nkosi', 'dlamini', 'mokoena', 'ndlovu', 'khumalo', 'van der merwe',
            'botha', 'naidoo', 'patel', 'mthembu', 'sithole', 'mahlangu', 'zulu', 'jones', 'brown'];

        $normalised = $this->comparator->normalisePersonName($name);

        foreach ($common as $surname) {
            if (Str::contains($normalised, $surname)) {
                return true;
            }
        }

        return false;
    }

    private function sameDate(string $a, string $b): bool
    {
        try {
            return \Illuminate\Support\Carbon::parse($a)->toDateString()
                === \Illuminate\Support\Carbon::parse($b)->toDateString();
        } catch (\Throwable) {
            return false;
        }
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
