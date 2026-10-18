<?php

namespace App\Modules\Procurement\Governance\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preference points: the most disputed number in a Southern African tender (§1.6).
 *
 * Before this class the number was whatever an officer typed into a box. It could not be checked,
 * it could not be explained to a losing bidder, and it meant the same thing in Gaborone as in
 * Mbabane as in Pretoria — which it does not.
 *
 * What is deliberately *not* here is any country's statute. The PPPFA price formula lives in the
 * scoring engine because it is stable and arithmetic; the points allocation has been each organ of
 * state's own "specific goals" since the 2022 Preferential Procurement Regulations, and Eswatini and
 * Botswana run different schemes again — commonly a margin of preference rather than points. Hard
 * coding any of that would be wrong within a year and wrong across the border immediately.
 *
 * So a framework is configuration: a table of bands, each saying which bidder attribute qualifies
 * and what it earns. This class reads that table and shows its working. Seeded frameworks arrive
 * marked unverified precisely so nobody mistakes a starting point for the law.
 */
class PreferenceCalculator
{
    /**
     * The framework a tender runs under: its own if named, otherwise the tenant's default for the
     * tender's jurisdiction.
     */
    public function frameworkFor(int $companyId, object $review, ?string $tenantJurisdiction = null): ?object
    {
        if (! Schema::hasTable('procgov_preference_frameworks')) {
            return null;
        }

        $query = DB::table('procgov_preference_frameworks')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNull('deleted_at');

        if (! empty($review->preference_framework_code)) {
            return $query->where('code', $review->preference_framework_code)->first();
        }

        $jurisdiction = $review->jurisdiction ?: ($tenantJurisdiction ?: 'ZA');

        return $query->where('jurisdiction', $jurisdiction)->orderBy('id')->first();
    }

    /** @return list<object> */
    public function bands(int $companyId, int $frameworkId): array
    {
        if (! Schema::hasTable('procgov_preference_bands')) {
            return [];
        }

        return DB::table('procgov_preference_bands')
            ->where('company_id', $companyId)
            ->where('framework_id', $frameworkId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The points a bidder earns under a framework, with the reason.
     *
     * Returns null points when nothing matches rather than zero: "this bidder qualified for nothing"
     * and "we could not tell" are different claims, and only one of them is safe to award on. The
     * caller decides which to show.
     *
     * @return array{points: float|null, margin_pct: float|null, band: string|null, reason: string}
     */
    public function pointsFor(int $companyId, object $framework, object $bidder): array
    {
        $bands = $this->bands($companyId, (int) $framework->id);

        if ($bands === []) {
            return [
                'points' => null,
                'margin_pct' => null,
                'band' => null,
                'reason' => "{$framework->name} has no bands configured, so no preference can be derived.",
            ];
        }

        foreach ($bands as $band) {
            if (! $this->matches($band, $bidder)) {
                continue;
            }

            $points = min((float) $band->points, (float) $framework->max_points);

            return [
                'points' => round($points, 4),
                'margin_pct' => $band->margin_pct !== null ? (float) $band->margin_pct : null,
                'band' => (string) $band->label,
                'reason' => sprintf('%s: %s', $framework->name, $band->label),
            ];
        }

        return [
            'points' => null,
            'margin_pct' => null,
            'band' => null,
            'reason' => sprintf(
                '%s: this bidder matches no band, so preference cannot be derived from what is on record.',
                $framework->name,
            ),
        ];
    }

    /**
     * Does a bidder fall in this band?
     *
     * Equality on a named field, plus the two comparisons a points table actually needs: a level "1
     * or better" band, and a plain yes/no for citizen or local qualification. Anything more
     * expressive belongs in the rules engine, where it can be read and frozen alongside the rest.
     */
    private function matches(object $band, object $bidder): bool
    {
        $field = (string) $band->match_field;
        $expected = trim((string) $band->match_value);

        if (! property_exists($bidder, $field) && ! isset($bidder->{$field})) {
            return false;
        }

        $actual = $bidder->{$field} ?? null;

        if ($actual === null || $actual === '') {
            return false;
        }

        // Boolean qualification, e.g. is_local_content_compliant = true.
        if (in_array(strtolower($expected), ['true', 'false'], true)) {
            return filter_var($actual, FILTER_VALIDATE_BOOLEAN) === (strtolower($expected) === 'true');
        }

        // "Level 2 or better" is written as <=2, because a lower B-BBEE level is a better one.
        if (str_starts_with($expected, '<=')) {
            return is_numeric($actual) && (float) $actual <= (float) substr($expected, 2);
        }

        if (str_starts_with($expected, '>=')) {
            return is_numeric($actual) && (float) $actual >= (float) substr($expected, 2);
        }

        return (string) $actual === $expected;
    }
}
