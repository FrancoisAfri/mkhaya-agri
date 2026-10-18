<?php

namespace App\Modules\SupplierManagement\DueDiligence\Services;

use App\Modules\SupplierManagement\DueDiligence\Support\DueDiligenceAddon;
use App\Modules\SupplierManagement\Models\ScreeningHit;
use App\Modules\SupplierManagement\Models\Supplier;
use App\Modules\SupplierManagement\Models\SupplierRiskScore;
use App\Modules\SupplierManagement\Models\SupplierVerification;

/**
 * What other modules are allowed to ask this one.
 *
 * Procurement Governance already decides bidder eligibility for the Eswatini and Botswana work, and
 * it should not have to do that twice for a supplier this module has already verified. So it asks
 * here — but through a published, read-only answer rather than by reaching into the tables, which is
 * what keeps the two modules separable.
 *
 * Three states, and the distinction between the last two is the whole point:
 *
 *  - `available: false, reason: not_installed` — the add-on is off for this tenant. The caller keeps
 *    its own eligibility rules and behaves exactly as it did before this module existed.
 *  - `available: true, assessed: false` — the add-on is on, but this supplier has never been
 *    assessed. Not a pass. The caller should say so rather than treating silence as clearance.
 *  - `available: true, assessed: true` — a real verdict, with the reasons behind it.
 *
 * A caller that cannot tell "not installed" from "assessed and clean" will eventually let an
 * unassessed supplier through on a tenant that never bought the add-on, which is precisely the
 * failure this shape prevents.
 */
class DueDiligenceVerdict
{
    /**
     * The verdict for one supplier.
     *
     * @return array{
     *   available: bool, assessed: bool, reason: string|null,
     *   score: float|null, band: string|null, tier: int|null,
     *   cleared: bool, blockers: array<int,string>, edd_required: bool,
     *   sanctions_hit: bool, unadjudicated_matches: int, assessed_at: string|null
     * }
     */
    public function for(Supplier $supplier): array
    {
        if (! DueDiligenceAddon::active()) {
            return $this->unavailable('not_installed');
        }

        $score = SupplierRiskScore::latestFor($supplier->id);

        if (! $score) {
            return [
                'available' => true,
                'assessed' => false,
                'reason' => 'never_assessed',
                'score' => null,
                'band' => null,
                'tier' => $supplier->risk_tier,
                // Never assessed is not cleared. Anything that reads this as a pass has misread it.
                'cleared' => false,
                'blockers' => ['This supplier has not been assessed.'],
                'edd_required' => false,
                'sanctions_hit' => false,
                'unadjudicated_matches' => ScreeningHit::query()->where('supplier_id', $supplier->id)->unadjudicated()->count(),
                'assessed_at' => null,
            ];
        }

        $sanctions = ScreeningHit::query()
            ->where('supplier_id', $supplier->id)
            ->live()
            ->type(ScreeningHit::TYPE_SANCTIONS)
            ->exists();

        return [
            'available' => true,
            'assessed' => true,
            'reason' => null,
            'score' => (float) $score->total,
            'band' => $score->band,
            'tier' => $score->tier,
            'cleared' => (bool) $score->auto_approvable && ! $sanctions,
            'blockers' => $score->blockers ?? [],
            'edd_required' => ($score->edd_triggers ?? []) !== [],
            'sanctions_hit' => $sanctions,
            'unadjudicated_matches' => ScreeningHit::query()->where('supplier_id', $supplier->id)->unadjudicated()->count(),
            'assessed_at' => $score->calculated_at?->toDateTimeString(),
        ];
    }

    /**
     * Whether another module should let this supplier proceed.
     *
     * Returns null — not false — when this module has nothing to say, so a caller can tell "no
     * opinion" from "no". A false would quietly block every supplier on a tenant that never bought
     * the add-on.
     */
    public function permits(Supplier $supplier): ?bool
    {
        $verdict = $this->for($supplier);

        return $verdict['available'] ? $verdict['cleared'] : null;
    }

    /**
     * A short line another module can show its own users.
     *
     * Written for somebody looking at a tender evaluation, not at this module — it says what is known
     * and what is missing without assuming the reader knows what a "band" is.
     */
    public function summary(Supplier $supplier): ?string
    {
        $verdict = $this->for($supplier);

        if (! $verdict['available']) {
            return null;
        }

        if (! $verdict['assessed']) {
            return __('supplier_management.verdict.never_assessed');
        }

        if ($verdict['sanctions_hit']) {
            return __('supplier_management.verdict.sanctions');
        }

        if ($verdict['cleared']) {
            return __('supplier_management.verdict.cleared', ['score' => number_format($verdict['score'], 1)]);
        }

        return __('supplier_management.verdict.blocked', [
            'score' => number_format($verdict['score'], 1),
            'count' => count($verdict['blockers']),
        ]);
    }

    /**
     * Verdicts for many suppliers at once, for a list screen.
     *
     * @param  array<int,int>  $supplierIds
     * @return array<int, array<string,mixed>>
     */
    public function forMany(array $supplierIds): array
    {
        if (! DueDiligenceAddon::active() || $supplierIds === []) {
            return [];
        }

        return Supplier::query()
            ->whereIn('id', $supplierIds)
            ->get()
            ->mapWithKeys(fn (Supplier $supplier) => [$supplier->id => $this->for($supplier)])
            ->all();
    }

    /** Whether a specific check has been passed — for a caller that cares about one thing. */
    public function hasPassed(Supplier $supplier, string $checkType): ?bool
    {
        if (! DueDiligenceAddon::active()) {
            return null;
        }

        return SupplierVerification::currentFor($supplier->id)->get($checkType)?->passed() ?? false;
    }

    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'assessed' => false,
            'reason' => $reason,
            'score' => null,
            'band' => null,
            'tier' => null,
            'cleared' => false,
            'blockers' => [],
            'edd_required' => false,
            'sanctions_hit' => false,
            'unadjudicated_matches' => 0,
            'assessed_at' => null,
        ];
    }
}
