<?php

namespace App\Modules\Operations\Manufacturing\Services;

use App\Modules\Operations\Manufacturing\Models\Routing;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Which routing applies on a date -- the same rule as BomResolver, and for the same reason: an
 * order dated before a routing changed is planned with the routing in force then.
 */
class RoutingResolver
{
    public function applicable(int $itemId, ?DateTimeInterface $asOf = null): ?Routing
    {
        $date = ($asOf ? Carbon::instance($asOf) : Carbon::now())->toDateString();

        return Routing::query()
            ->where('item_id', $itemId)
            ->whereIn('status', [Routing::APPROVED, Routing::SUPERSEDED])
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByRaw("CASE status WHEN 'APPROVED' THEN 0 ELSE 1 END")
            ->orderByDesc('is_default')
            ->orderByRaw('effective_from DESC NULLS LAST')
            ->orderByDesc('revision')
            ->first();
    }
}
