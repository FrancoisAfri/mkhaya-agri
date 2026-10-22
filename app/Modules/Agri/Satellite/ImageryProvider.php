<?php

namespace App\Modules\Agri\Satellite;

use App\Modules\Agri\Models\FarmField;
use Illuminate\Support\Collection;

/**
 * A satellite imagery provider an organisation has chosen (Copernicus Data Space or
 * AgroMonitoring). Every answer comes back in one shape, so the fetch, the flags and the screens
 * never care which provider sent it. Each request to the provider goes through the budget first;
 * an exhausted budget throws SatelliteBudgetExhausted before the request is made.
 */
interface ImageryProvider
{
    /** A cheap call that proves the credentials work. @return array{ok: bool, message: string} */
    public function check(): array;

    /**
     * The acquisitions over a field between two dates (inclusive), with statistics for the clear ones.
     *
     * @param  array  $geometry  the field's GeoJSON Polygon / MultiPolygon (lng, lat)
     * @param  Collection<int, \App\Modules\Agri\Models\FieldZone>  $zones  zones to measure too
     * @param  list<string>  $known  dates already looked at for this field (never asked for again)
     * @return list<array{date: string, ref: ?string, cloud_pct: ?float, status: string, indices: array<string, array{mean: float, min: ?float, max: ?float}>, zones: array<string, array<string, float>>, image: mixed}>
     */
    public function scenes(FarmField $field, array $geometry, Collection $zones, string $from, string $to, float $maxCloudPct, array $known): array;

    /** The NDVI picture of the field for one stored scene, as PNG bytes; null when the provider has none. */
    public function image(FarmField $field, array $geometry, array $scene): ?string;
}
