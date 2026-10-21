<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Models\CropObservation;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropStageEvent;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmAlert;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmLocation;
use App\Modules\Agri\Models\FarmMachine;
use App\Modules\Agri\Models\IrrigationException;
use App\Modules\Agri\Models\IrrigationZone;
use App\Modules\Agri\Models\LivestockGroup;
use App\Modules\Agri\Models\WeatherReading;

/**
 * Phase 2 §16A: the farm as a map, with what is happening on it.
 *
 * Fields (their boundaries, or a point), the farm's buildings, camps, dams and boreholes, its
 * irrigation zones, machines where telematics last saw them, and herds on their fields — each
 * carrying its live state: the crop and its stage, open scouting and alerts, irrigation problems,
 * machine status, head counts. Drawn from the records themselves, so the map is never a separate
 * thing to keep up to date; anything without coordinates is listed rather than guessed.
 */
class FarmTwin
{
    public function build(Farm $farm): array
    {
        $alerts = FarmAlert::query()->where('farm_id', $farm->id)->whereIn('status', [FarmAlert::OPEN, FarmAlert::ACKNOWLEDGED])->get();
        $plans = CropPlan::query()->where('farm_id', $farm->id)->whereIn('status', CropPlan::LIVE)->with('cropType')->get()->groupBy('field_id');
        $obs = CropObservation::query()->where('farm_id', $farm->id)->where('status', 'open')->get()->groupBy('field_id');
        $irr = IrrigationException::query()->where('farm_id', $farm->id)->whereNull('resolved_at')->get()->groupBy('field_id');
        $herds = LivestockGroup::query()->where('farm_id', $farm->id)->where('status', 'active')->with('species')->get();
        $features = [];
        $unplaced = [];

        foreach (FarmField::query()->where('farm_id', $farm->id)->where('status', 'active')->get() as $f) {
            $p = ($plans[$f->id] ?? collect())->first();
            $stage = $p ? CropStageEvent::query()->where('crop_plan_id', $p->id)->latest('observed_on')->value('stage') : null;
            $o = $obs[$f->id] ?? collect();
            $geom = $this->geometry($f->boundary) ?? ($f->latitude !== null ? ['type' => 'Point', 'coordinates' => [(float) $f->longitude, (float) $f->latitude]] : null);
            $props = ['layer' => 'field', 'id' => $f->uuid, 'label' => $f->code, 'name' => $f->name, 'hectares' => $f->hectares(),
                'crop' => $p?->cropType?->name, 'plan' => $p?->code, 'plan_status' => $p?->status, 'stage' => $stage,
                'observations' => $o->count(), 'serious' => $o->whereIn('severity', ['high', 'critical'])->count(), 'irrigation_issues' => ($irr[$f->id] ?? collect())->count(),
                'herds' => $herds->where('field_id', $f->id)->map(fn ($h) => "{$h->code} ({$h->currentHeadCount()})")->values()->all(),
                'state' => $o->whereIn('severity', ['high', 'critical'])->isNotEmpty() || ($irr[$f->id] ?? collect())->isNotEmpty() ? 'attention' : ($p ? 'growing' : 'fallow'),
                'link' => $p ? "/manufacturing/agriculture/crops/plans/{$p->uuid}" : null];
            $geom ? $features[] = ['geometry' => $geom, 'properties' => $props] : $unplaced[] = $props;
        }
        foreach (FarmLocation::query()->where('farm_id', $farm->id)->get() as $l) {
            $geom = $this->geometry($l->boundary) ?? ($l->latitude !== null ? ['type' => 'Point', 'coordinates' => [(float) $l->longitude, (float) $l->latitude]] : null);
            $props = ['layer' => 'location', 'id' => $l->uuid, 'label' => $l->name, 'kind' => $l->location_type, 'state' => $l->status === 'active' ? 'ok' : 'inactive'];
            $geom ? $features[] = ['geometry' => $geom, 'properties' => $props] : $unplaced[] = $props;
        }
        foreach (FarmMachine::query()->where('farm_id', $farm->id)->where('status', '!=', FarmMachine::DISPOSED)->get() as $m) {
            $props = ['layer' => 'machine', 'id' => $m->uuid, 'label' => $m->code, 'name' => $m->name, 'status' => $m->currentStatus(),
                'seen' => $m->last_seen_at?->format('Y-m-d H:i'), 'state' => $m->isUnavailable() ? 'attention' : 'ok', 'link' => '/agri/machinery/fleet/bookings'];
            $m->last_latitude !== null ? $features[] = ['geometry' => ['type' => 'Point', 'coordinates' => [(float) $m->last_longitude, (float) $m->last_latitude]], 'properties' => $props]
                : $unplaced[] = $props;
        }
        $now = WeatherReading::query()->where('farm_id', $farm->id)->where('kind', 'current')->latest('period_start')->first();

        return ['farm' => ['name' => $farm->name, 'point' => $farm->latitude !== null ? [(float) $farm->longitude, (float) $farm->latitude] : null],
            'features' => $features, 'unplaced' => $unplaced,
            'zones' => IrrigationZone::query()->where('farm_id', $farm->id)->get()->map(fn ($z) => ['code' => $z->code, 'name' => $z->name, 'system' => $z->system,
                'field' => FarmField::query()->whereKey($z->field_id)->value('code')])->all(),
            'weather' => $now ? ['temperature' => $now->temperature, 'wind' => $now->wind_speed, 'at' => $now->period_start?->format('Y-m-d H:i'), 'source' => $now->provider] : null,
            'alerts' => ['total' => $alerts->count(), 'critical' => $alerts->where('severity', 'critical')->count()],
            'bounds' => $this->bounds($features)];
    }

    /** A GeoJSON geometry from what is stored: a geometry, a Feature, or a list of [lat, lng] points. */
    private function geometry($b): ?array
    {
        if (! is_array($b) || ! $b) {
            return null;
        }
        if (isset($b['type']) && $b['type'] === 'Feature') {
            return $this->geometry($b['geometry'] ?? null);
        }
        if (isset($b['type'], $b['coordinates']) && in_array($b['type'], ['Polygon', 'MultiPolygon', 'Point', 'LineString'], true)) {
            return $b;
        }
        if (array_is_list($b) && is_array($b[0] ?? null) && count($b[0]) === 2 && is_numeric($b[0][0])) {
            return ['type' => 'Polygon', 'coordinates' => [array_map(fn ($pt) => [(float) $pt[1], (float) $pt[0]], $b)]];   // [lat, lng] → GeoJSON [lng, lat]
        }

        return null;
    }

    private function bounds(array $features): ?array
    {
        $pts = [];
        $walk = function ($c) use (&$walk, &$pts) {
            if (is_array($c) && count($c) === 2 && is_numeric($c[0]) && is_numeric($c[1])) {
                $pts[] = $c;
            } elseif (is_array($c)) {
                foreach ($c as $x) {
                    $walk($x);
                }
            }
        };
        foreach ($features as $f) {
            $walk($f['geometry']['coordinates']);
        }
        if (! $pts) {
            return null;
        }

        return [min(array_column($pts, 0)), min(array_column($pts, 1)), max(array_column($pts, 0)), max(array_column($pts, 1))];
    }
}
