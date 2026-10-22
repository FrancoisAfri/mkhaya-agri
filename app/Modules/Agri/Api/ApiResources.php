<?php

namespace App\Modules\Agri\Api;

use App\Modules\Agri\Models;
use App\Modules\Agri\Services\FarmAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The farm records the public API reads, described once.
 *
 * Each resource names its model, the ability it needs, the filters it takes and the fields it
 * returns with their types. The list and show endpoints, the webhook payloads and the OpenAPI
 * document are all built from this, so the documentation cannot describe a field the API does not
 * return. Records are read through their models, so the tenant scope and farm access apply as they
 * do on a screen; a record on a farm the token cannot reach is simply not there.
 *
 * Paging is by cursor (the last id returned, opaque to the client); `updated_since` returns only
 * records changed at or after a moment, which is how a client keeps a copy in sync.
 */
final class ApiResources
{
    /** Field types: string, number, integer, boolean, date, datetime, geojson, object, array. */
    public static function definitions(): array
    {
        $farm = ['farm' => ['farm_id', 'farms']];
        $field = ['field' => ['field_id', 'farm_fields']];
        $plan = ['crop_plan' => ['crop_plan_id', 'crop_plans']];

        return [
            'farms' => ['path' => 'farms', 'ability' => 'farms:read', 'model' => Models\Farm::class, 'farm_column' => 'id', 'tag' => 'Farms',
                'summary' => 'Farms in the organisation that the token reaches.',
                'filters' => ['status' => ['status', 'string']],
                'fields' => ['code' => 'string', 'name' => 'string', 'farm_type' => 'string', 'status' => 'string', 'location' => 'string', 'area' => 'number', 'area_unit' => 'string',
                    'mapped_area_ha' => 'number', 'latitude' => 'number', 'longitude' => 'number', 'timezone' => 'string', 'currency' => 'string', 'boundary' => 'geojson']],
            'fields' => ['path' => 'fields', 'ability' => 'farms:read', 'model' => Models\FarmField::class, 'tag' => 'Farms', 'geojson' => true,
                'summary' => 'Fields, blocks, paddocks and camps, with their boundaries as GeoJSON. Send format=geojson for a FeatureCollection.',
                'refs' => $farm + ['parent_field' => ['parent_field_id', 'farm_fields']],
                'filters' => ['status' => ['status', 'string'], 'land_use_type' => ['land_use_type', 'string']],
                'fields' => ['code' => 'string', 'name' => 'string', 'land_use_type' => 'string', 'status' => 'string', 'area' => 'number', 'area_unit' => 'string', 'mapped_area_ha' => 'number',
                    'soil_type' => 'string', 'irrigation' => 'string', 'water_source' => 'string', 'slope_pct' => 'number', 'latitude' => 'number', 'longitude' => 'number', 'boundary' => 'geojson']],
            'zones' => ['path' => 'zones', 'ability' => 'farms:read', 'model' => Models\FieldZone::class, 'tag' => 'Farms', 'geojson' => true,
                'summary' => 'Management zones within fields.', 'refs' => $farm + $field,
                'filters' => ['field' => ['field_id', 'uuid', 'farm_fields'], 'status' => ['status', 'string']],
                'fields' => ['name' => 'string', 'zone_class' => 'string', 'soil_type' => 'string', 'area_ha' => 'number', 'status' => 'string', 'boundary' => 'geojson']],
            'seasons' => ['path' => 'seasons', 'ability' => 'crops:read', 'model' => Models\CropSeason::class, 'farm_column' => null, 'tag' => 'Crops',
                'summary' => 'The organisation\'s crop seasons.', 'filters' => ['status' => ['status', 'string']],
                'fields' => ['code' => 'string', 'name' => 'string', 'starts_on' => 'date', 'ends_on' => 'date', 'status' => 'string']],
            'crop-plans' => ['path' => 'crop-plans', 'ability' => 'crops:read', 'model' => Models\CropPlan::class, 'tag' => 'Crops',
                'summary' => 'Crop plans: what is grown on which field in which season.',
                'refs' => $farm + $field + ['season' => ['season_id', 'crop_seasons']],
                'names' => ['crop_type' => ['crop_type_id', 'crop_types', 'name'], 'crop_code' => ['crop_type_id', 'crop_types', 'code'], 'variety' => ['variety_id', 'crop_varieties', 'name']],
                'filters' => ['field' => ['field_id', 'uuid', 'farm_fields'], 'season' => ['season_id', 'uuid', 'crop_seasons'], 'status' => ['status', 'string']],
                'fields' => ['code' => 'string', 'status' => 'string', 'current_stage' => 'string', 'planned_area' => 'number', 'area_unit' => 'string', 'production_method' => 'string',
                    'planned_planting_on' => 'date', 'planned_harvest_on' => 'date', 'planted_on' => 'date', 'harvest_completed_on' => 'date',
                    'expected_yield' => 'number', 'yield_unit' => 'string', 'target_markets' => 'array']],
            'field-activities' => ['path' => 'field-activities', 'ability' => 'crops:read', 'model' => Models\FieldActivity::class, 'tag' => 'Crops',
                'summary' => 'Field activities: planting, spraying, fertilising, irrigation and other work on a field.',
                'refs' => $farm + $field + $plan + ['livestock_group' => ['livestock_group_id', 'livestock_groups']], 'date' => 'planned_on',
                'filters' => ['field' => ['field_id', 'uuid', 'farm_fields'], 'crop_plan' => ['crop_plan_id', 'uuid', 'crop_plans'], 'status' => ['status', 'string'], 'activity_type' => ['activity_type', 'string']],
                'fields' => ['activity_type' => 'string', 'title' => 'string', 'status' => 'string', 'planned_on' => 'date', 'completed_at' => 'datetime',
                    'area_covered' => 'number', 'labour_hours' => 'number', 'machine_hours' => 'number', 'notes' => 'string']],
            'inputs-used' => ['path' => 'inputs-used', 'ability' => 'crops:read', 'model' => Models\ActivityInput::class, 'tag' => 'Crops',
                'summary' => 'Seed, fertiliser, chemicals and fuel used on field activities.',
                'refs' => $farm + ['activity' => ['activity_id', 'field_activities']], 'date' => 'issued_at',
                'names' => ['item_code' => ['stock_item_id', 'fin_stock_items', 'code'], 'item' => ['stock_item_id', 'fin_stock_items', 'name']],
                'filters' => ['activity' => ['activity_id', 'uuid', 'field_activities'], 'status' => ['status', 'string']],
                'fields' => ['status' => 'string', 'quantity' => 'number', 'uom' => 'string', 'rate_per_ha' => 'number', 'unit_cost' => 'number', 'total_cost' => 'number',
                    'withholding_days' => 'integer', 'reentry_hours' => 'integer', 'issued_at' => 'datetime']],
            'harvests' => ['path' => 'harvests', 'ability' => 'harvests:read', 'model' => Models\HarvestRecord::class, 'tag' => 'Harvest & trace',
                'summary' => 'Harvest records per field and crop plan.', 'refs' => $farm + $field + $plan, 'date' => 'harvested_on',
                'filters' => ['field' => ['field_id', 'uuid', 'farm_fields'], 'crop_plan' => ['crop_plan_id', 'uuid', 'crop_plans'], 'destination' => ['destination', 'string']],
                'fields' => ['lot_code' => 'string', 'harvested_on' => 'date', 'quantity' => 'number', 'unit' => 'string', 'grade' => 'string', 'moisture_pct' => 'number',
                    'area_harvested' => 'number', 'destination' => 'string', 'rejected_quantity' => 'number', 'spoiled_quantity' => 'number', 'measuring_method' => 'string']],
            'lots' => ['path' => 'lots', 'ability' => 'harvests:read', 'model' => Models\ProduceLot::class, 'tag' => 'Harvest & trace',
                'summary' => 'Produce lots: harvest lots and what grading, packing and processing made from them.', 'refs' => $farm + $field + $plan, 'date' => 'produced_on',
                'filters' => ['status' => ['status', 'string'], 'kind' => ['kind', 'string']],
                'fields' => ['code' => 'string', 'kind' => 'string', 'product' => 'string', 'grade' => 'string', 'quantity' => 'number', 'remaining' => 'number', 'unit' => 'string',
                    'status' => 'string', 'produced_on' => 'date', 'best_before' => 'date', 'location' => 'string', 'pallet_ref' => 'string', 'target_markets' => 'array']],
            'ndvi' => ['path' => 'ndvi', 'ability' => 'crops:read', 'model' => Models\FieldImagery::class, 'tag' => 'Crops',
                'summary' => 'Vegetation-index captures per field (satellite NDVI and imports): mean, min, max and change.', 'refs' => $farm + $field + $plan, 'date' => 'captured_on',
                'filters' => ['field' => ['field_id', 'uuid', 'farm_fields'], 'index' => ['index', 'string']],
                'fields' => ['captured_on' => 'date', 'source' => 'string', 'index' => 'string', 'mean' => 'number', 'min' => 'number', 'max' => 'number', 'cloud_pct' => 'number', 'change_pct' => 'number']],
            'animals' => ['path' => 'animals', 'ability' => 'livestock:read', 'model' => Models\Animal::class, 'tag' => 'Livestock',
                'summary' => 'Individually tagged animals.', 'refs' => $farm + $field + ['group' => ['group_id', 'livestock_groups'], 'dam' => ['dam_id', 'animals'], 'sire' => ['sire_id', 'animals']],
                'names' => ['species' => ['species_id', 'livestock_species', 'code'], 'breed' => ['breed_id', 'livestock_breeds', 'name']],
                'filters' => ['status' => ['status', 'string'], 'group' => ['group_id', 'uuid', 'livestock_groups']],
                'fields' => ['tag_number' => 'string', 'electronic_id' => 'string', 'name' => 'string', 'sex' => 'string', 'birth_date' => 'date', 'status' => 'string',
                    'ownership' => 'string', 'last_weight_kg' => 'number', 'acquired_on' => 'date', 'exited_on' => 'date', 'exit_reason' => 'string']],
            'livestock-groups' => ['path' => 'livestock-groups', 'ability' => 'livestock:read', 'model' => Models\LivestockGroup::class, 'tag' => 'Livestock',
                'summary' => 'Herds, flocks and batches counted as a group.', 'refs' => $farm + $field, 'names' => ['species' => ['species_id', 'livestock_species', 'code']],
                'filters' => ['status' => ['status', 'string']],
                'fields' => ['code' => 'string', 'name' => 'string', 'batch_code' => 'string', 'purpose' => 'string', 'head_count' => 'integer', 'target_head' => 'integer',
                    'status' => 'string', 'started_on' => 'date', 'closed_on' => 'date']],
            'animal-events' => ['path' => 'animal-events', 'ability' => 'livestock:read', 'model' => Models\AnimalEvent::class, 'tag' => 'Livestock',
                'summary' => 'Births, weaning, matings, pregnancy checks, milk recordings and other animal events.', 'refs' => $farm + ['animal' => ['animal_id', 'animals'], 'sire' => ['sire_id', 'animals']],
                'date' => 'occurred_on', 'filters' => ['animal' => ['animal_id', 'uuid', 'animals'], 'event_type' => ['event_type', 'string']],
                'fields' => ['event_type' => 'string', 'occurred_on' => 'date', 'expected_on' => 'date', 'outcome' => 'string', 'method' => 'string', 'details' => 'object']],
            'work-orders' => ['path' => 'work-orders', 'ability' => 'work_orders:read', 'model' => Models\FarmWorkOrder::class, 'tag' => 'Work orders',
                'summary' => 'Planned and reactive farm jobs.', 'refs' => $farm + $field + $plan + ['livestock_group' => ['livestock_group_id', 'livestock_groups']],
                'filters' => ['status' => ['status', 'string'], 'category' => ['category', 'string'], 'field' => ['field_id', 'uuid', 'farm_fields']],
                'fields' => ['reference' => 'string', 'category' => 'string', 'title' => 'string', 'priority' => 'string', 'status' => 'string', 'planned_start' => 'datetime',
                    'planned_end' => 'datetime', 'estimated_hours' => 'number', 'expected_output' => 'number', 'actual_output' => 'number', 'output_unit' => 'string',
                    'started_at' => 'datetime', 'completed_at' => 'datetime']],
            'weather' => ['path' => 'weather', 'ability' => 'weather:read', 'model' => Models\WeatherReading::class, 'tag' => 'Weather',
                'summary' => 'Weather observations and forecasts per farm (and field where measured there).', 'refs' => $farm + $field, 'date' => 'period_start',
                'filters' => ['kind' => ['kind', 'string'], 'field' => ['field_id', 'uuid', 'farm_fields']],
                'fields' => ['provider' => 'string', 'kind' => 'string', 'period_start' => 'datetime', 'period_end' => 'datetime', 'temperature' => 'number', 'temp_min' => 'number',
                    'temp_max' => 'number', 'humidity' => 'number', 'wind_speed' => 'number', 'wind_gust' => 'number', 'rain_mm' => 'number', 'rain_probability' => 'number',
                    'conditions' => 'string', 'et0_mm' => 'number']],
            'grain-tickets' => ['path' => 'grain-tickets', 'ability' => 'harvests:read', 'model' => Models\GrainTicket::class, 'tag' => 'Harvest & trace',
                'summary' => 'Grain load tickets over the weighbridge, to the farm\'s bins or to a buyer.', 'refs' => $farm + $field + $plan, 'date' => 'dispatched_at',
                'names' => ['buyer' => ['party_id', 'fin_parties', 'name']], 'filters' => ['status' => ['status', 'string'], 'movement' => ['movement', 'string']],
                'fields' => ['number' => 'string', 'status' => 'string', 'movement' => 'string', 'vehicle_reg' => 'string', 'destination_name' => 'string', 'dispatched_at' => 'datetime',
                    'gross_kg' => 'number', 'tare_kg' => 'number', 'net_kg' => 'number', 'moisture_pct' => 'number', 'foreign_matter_pct' => 'number', 'grade' => 'string',
                    'moisture_deduction_kg' => 'number', 'handling_deduction_kg' => 'number', 'screenings_deduction_kg' => 'number', 'settled_kg' => 'number',
                    'buyer_ticket_ref' => 'string', 'completed_at' => 'datetime']],
            'sales-contracts' => ['path' => 'sales-contracts', 'ability' => 'sales:read', 'model' => Models\SalesContract::class, 'farm_column' => 'farm_id', 'manual_reach' => true,
                'tag' => 'Sales', 'summary' => 'Buyer contracts for produce (read only).', 'refs' => $farm,
                'names' => ['buyer' => ['party_id', 'fin_parties', 'name'], 'crop_type' => ['crop_type_id', 'crop_types', 'name']],
                'filters' => ['status' => ['status', 'string']],
                'fields' => ['reference' => 'string', 'version' => 'integer', 'status' => 'string', 'product' => 'string', 'quantity' => 'number', 'unit' => 'string', 'tolerance_pct' => 'number',
                    'pricing' => 'string', 'price' => 'number', 'currency' => 'string', 'windows' => 'array', 'accepted_grades' => 'array', 'approved_at' => 'datetime']],
        ];
    }

    public function definition(string $key): array
    {
        return self::definitions()[$key] ?? throw new HttpException(404, 'No such resource.');
    }

    /** @return array{data: list<array>, meta: array} */
    public function index(string $key, array $q): array
    {
        $d = $this->definition($key);
        $limit = $this->limit($q);
        $query = $this->query($d)->orderBy($this->table($d).'.id');
        $farmCol = array_key_exists('farm_column', $d) ? $d['farm_column'] : 'farm_id';

        if (! empty($q['farm']) && $farmCol) {
            $query->where($this->table($d).'.'.$farmCol, $this->idFor('farms', (string) $q['farm']));
        }
        foreach ($d['filters'] ?? [] as $param => $f) {
            if (! isset($q[$param]) || $q[$param] === '' || ! is_scalar($q[$param])) {
                continue;
            }
            $query->where($this->table($d).'.'.$f[0], $f[1] === 'uuid' ? $this->idFor($f[2], (string) $q[$param]) : (string) $q[$param]);
        }
        if (! empty($d['date'])) {
            if (! empty($q['from'])) {
                $query->where($this->table($d).'.'.$d['date'], '>=', $this->date($q['from'], 'from'));
            }
            if (! empty($q['to'])) {
                $query->where($this->table($d).'.'.$d['date'], '<=', $this->date($q['to'], 'to').' 23:59:59');
            }
        }
        if (! empty($q['updated_since'])) {
            $query->where($this->table($d).'.updated_at', '>=', $this->moment($q['updated_since']));
        }
        if (! empty($q['cursor'])) {
            $query->where($this->table($d).'.id', '>', $this->decodeCursor((string) $q['cursor']));
        }

        $rows = $query->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();
        $next = $more ? $this->encodeCursor((int) $rows->last()->getKey()) : null;

        return ['data' => $this->rows($key, $rows), 'meta' => ['limit' => $limit, 'count' => $rows->count(), 'next_cursor' => $next, 'has_more' => $more]];
    }

    public function show(string $key, string $id): array
    {
        $d = $this->definition($key);
        abort_unless(Str::isUuid($id), 404, 'Not found.');
        $model = $this->query($d)->where($this->table($d).'.uuid', $id)->first();
        abort_unless($model, 404, 'Not found.');

        return $this->rows($key, collect([$model]))[0];
    }

    /** The model for a resource by its public id, within reach, or a 404. */
    public function find(string $key, ?string $id): Model
    {
        $d = $this->definition($key);
        abort_unless($id && Str::isUuid($id), 404, 'Not found.');

        return $this->query($d)->where($this->table($d).'.uuid', $id)->first() ?? abort(404, 'Not found.');
    }

    /** @param Collection<int, Model> $models @return list<array> */
    public function rows(string $key, Collection $models): array
    {
        $d = $this->definition($key);
        $raw = $models->map(fn (Model $m) => $m->getAttributes());
        $uuids = [];
        foreach ($d['refs'] ?? [] as $name => [$column, $table]) {
            $ids = $raw->pluck($column)->filter()->unique()->values()->all();
            $uuids[$name] = $ids ? DB::table($table)->whereIn('id', $ids)->pluck('uuid', 'id')->all() : [];
        }
        $names = [];
        foreach ($d['names'] ?? [] as $name => [$column, $table, $label]) {
            $ids = $raw->pluck($column)->filter()->unique()->values()->all();
            $names[$name] = $ids ? DB::table($table)->whereIn('id', $ids)->pluck($label, 'id')->all() : [];
        }

        return $raw->map(function (array $a) use ($d, $uuids, $names) {
            $row = ['id' => $a['uuid'] ?? null];
            foreach ($d['refs'] ?? [] as $name => [$column]) {
                $row[$name] = isset($a[$column]) ? ($uuids[$name][$a[$column]] ?? null) : null;
            }
            foreach ($d['names'] ?? [] as $name => [$column]) {
                $row[$name] = isset($a[$column]) ? ($names[$name][$a[$column]] ?? null) : null;
            }
            foreach ($d['fields'] as $f => $type) {
                $row[$f] = self::value($a[$f] ?? null, $type);
            }
            $row['created_at'] = self::value($a['created_at'] ?? null, 'datetime');
            $row['updated_at'] = self::value($a['updated_at'] ?? null, 'datetime');

            return $row;
        })->values()->all();
    }

    /** A page of rows as a GeoJSON FeatureCollection, the boundary as each feature's geometry. */
    public static function featureCollection(array $page): array
    {
        return ['type' => 'FeatureCollection', 'features' => array_map(function ($r) {
            $geometry = $r['boundary'] ?? null;
            unset($r['boundary']);

            return ['type' => 'Feature', 'id' => $r['id'], 'geometry' => $geometry, 'properties' => $r];
        }, $page['data']), 'meta' => $page['meta']];
    }

    public static function value(mixed $v, string $type): mixed
    {
        if ($v === null) {
            return null;
        }

        return match ($type) {
            'number' => (float) $v,
            'integer' => (int) $v,
            'boolean' => (bool) $v,
            'date' => substr((string) $v, 0, 10),
            'datetime' => Carbon::parse((string) $v, config('app.timezone'))->utc()->toIso8601ZuluString(),
            'geojson', 'object', 'array' => is_string($v) ? json_decode($v, true) : $v,
            default => (string) $v,
        };
    }

    public function encodeCursor(int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['after' => $id])), '+/', '-_'), '=');
    }

    public function decodeCursor(string $cursor): int
    {
        $d = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        if (! is_array($d) || ! is_int($d['after'] ?? null)) {
            throw new HttpException(400, 'That cursor is not one this API gave out.');
        }

        return $d['after'];
    }

    public function limit(array $q): int
    {
        $limit = (int) ($q['limit'] ?? 50);

        return max(1, min((int) config('agri.api.max_page', 200), $limit ?: 50));
    }

    /** Tables a filter may name a record of, with the model that keeps it within reach. */
    private const LOOKUP = ['farms' => Models\Farm::class, 'farm_fields' => Models\FarmField::class, 'crop_plans' => Models\CropPlan::class,
        'crop_seasons' => Models\CropSeason::class, 'field_activities' => Models\FieldActivity::class, 'animals' => Models\Animal::class,
        'livestock_groups' => Models\LivestockGroup::class];

    /** An id for a public uuid among the records the token reaches; 0 (matches nothing) when it is not one of them. */
    public function idFor(string $table, string $uuid): int
    {
        $model = self::LOOKUP[$table] ?? null;
        if (! $model || ! Str::isUuid($uuid)) {
            return 0;
        }

        return (int) $model::query()->where((new $model)->getTable().'.uuid', $uuid)->value((new $model)->getTable().'.id');
    }

    public function moment(mixed $v): string
    {
        try {
            return Carbon::parse((string) $v)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new HttpException(400, 'updated_since must be an ISO 8601 moment, e.g. 2026-10-01T00:00:00Z.');
        }
    }

    private function date(mixed $v, string $name): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
            throw new HttpException(400, "{$name} must be a date, YYYY-MM-DD.");
        }

        return (string) $v;
    }

    private function query(array $d): Builder
    {
        /** @var Builder $q */
        $q = $d['model']::query();
        if (! empty($d['manual_reach']) && ($reach = app(FarmAccess::class)->farmIdsFor()) !== null) {
            // Not a farm-owned model: confine it to the token's farms (and organisation-wide rows) by hand.
            $q->where(fn ($w) => $w->whereNull($this->table($d).'.farm_id')->orWhereIn($this->table($d).'.farm_id', $reach ?: [0]));
        }

        return $q;
    }

    private function table(array $d): string
    {
        return (new $d['model'])->getTable();
    }
}
