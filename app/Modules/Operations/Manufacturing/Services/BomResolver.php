<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\Bom;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Which recipe applies on a date (MFG-002: "calculate material requirements using the approved
 * BOM version applicable to the production order").
 *
 * "Applicable" is a question about a date, not about a flag. The default revision answers "what do
 * we make from today"; it does not answer "what was this order costed against" for an order raised
 * last quarter, or "what will apply from the first of next month" for one scheduled ahead.
 *
 * A SUPERSEDED revision is therefore a candidate: it was approved, and for the window it was
 * effective it was the recipe. An OBSOLETE one is not -- it was retired deliberately, to stop it
 * being used. A draft is never applicable.
 *
 * Where two windows overlap -- the day a revision is superseded it is both the old recipe's last
 * day and the new one's first -- the live approved revision wins, then the default, then the one
 * that took effect most recently. The preference order is fixed so the same date always resolves
 * to the same recipe.
 */
class BomResolver
{
    public function applicable(int $itemId, ?DateTimeInterface $asOf = null): ?Bom
    {
        $date = ($asOf ? Carbon::instance($asOf) : Carbon::now())->toDateString();

        return Bom::query()
            ->where('item_id', $itemId)
            ->whereIn('status', [Bom::APPROVED, Bom::SUPERSEDED])
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            // Live before superseded, default before not, then the most recent start, then newest.
            ->orderByRaw("CASE status WHEN 'APPROVED' THEN 0 ELSE 1 END")
            ->orderByDesc('is_default')
            ->orderByRaw('effective_from DESC NULLS LAST')
            ->orderByDesc('revision')
            ->first();
    }

    /**
     * Every revision that applies at some point in a window, oldest first.
     *
     * For answering "which recipes could a plan for the next quarter be costed against", where the
     * plan straddles a change of recipe and the right answer is two of them.
     *
     * @return \Illuminate\Support\Collection<int, Bom>
     */
    public function applicableBetween(int $itemId, DateTimeInterface $from, DateTimeInterface $to)
    {
        $start = Carbon::instance($from)->toDateString();
        $end = Carbon::instance($to)->toDateString();

        return Bom::query()
            ->where('item_id', $itemId)
            ->whereIn('status', [Bom::APPROVED, Bom::SUPERSEDED])
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $end))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->orderBy('revision')
            ->get();
    }
}
