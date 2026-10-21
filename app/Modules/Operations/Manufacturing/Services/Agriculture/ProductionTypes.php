<?php

namespace App\Modules\Operations\Manufacturing\Services\Agriculture;

use App\Core\User\Models\User;
use App\Modules\Operations\Manufacturing\Models\Agriculture\ProductionType;
use App\Modules\Operations\Manufacturing\Models\Agriculture\Threshold;
use App\Modules\Operations\Manufacturing\Models\Agriculture\TypeVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Production types as configuration: one engine for broilers, layers, maize, beef, dairy, tilapia,
 * tunnel tomatoes and a processing line, each defined by its stages, measurements, custom fields
 * and metric formulas rather than by code.
 *
 * A subtype ("free-range broilers") starts from its parent and adds to it; an entry with the same
 * code replaces the parent's. Every change is a new version kept in full, and a cycle is measured
 * by the version it started on.
 */
class ProductionTypes
{
    /** What Metrics measures for every cycle, so a formula can only ask for something that exists. */
    public const VARIABLES = ['opening_count', 'current_count', 'mortality', 'culls', 'feed_kg', 'water_l', 'avg_weight_kg', 'live_weight_kg',
        'opening_weight_kg', 'output_qty', 'harvest_qty', 'eggs', 'milk_l', 'area', 'cost', 'age_days', 'treatments'];

    public function installDefaults(int $companyId): void
    {
        foreach (self::defaults() as $code => $d) {
            if (ProductionType::query()->where('company_id', $companyId)->where('code', $code)->exists()) {
                continue;
            }
            $parent = isset($d['parent']) ? ProductionType::query()->where('company_id', $companyId)->where('code', $d['parent'])->value('id') : null;
            $type = $this->create(['company_id' => $companyId, 'code' => $code, 'parent_id' => $parent] + collect($d)->except(['parent', 'thresholds'])->all(), null, 'Installed default');
            foreach ($d['thresholds'] ?? [] as [$metric, $min, $max, $severity, $sustain]) {
                Threshold::create(['company_id' => $companyId, 'production_type_id' => $type->id, 'metric' => $metric, 'min_value' => $min,
                    'max_value' => $max, 'severity' => $severity, 'sustain_seconds' => $sustain]);
            }
        }
    }

    public function create(array $data, ?User $actor, string $note = 'Created'): ProductionType
    {
        $this->check($data);

        return DB::transaction(function () use ($data, $actor, $note) {
            $type = ProductionType::create($data + ['version' => 1]);
            $this->snapshot($type, $actor, $note);

            return $type;
        });
    }

    /** A subtype: the parent's definition, with these additions and replacements. */
    public function subtype(ProductionType $parent, array $data, ?User $actor): ProductionType
    {
        return $this->create(['company_id' => $parent->company_id, 'parent_id' => $parent->id, 'category' => $parent->category,
            'unit_kind' => $data['unit_kind'] ?? $parent->unit_kind, 'count_unit' => $data['count_unit'] ?? $parent->count_unit,
            'output_unit' => $data['output_unit'] ?? $parent->output_unit] + $data, $actor, "Subtype of {$parent->code}");
    }

    /** A change to a type: a new version, the old one kept for the cycles that started on it. */
    public function revise(ProductionType $type, array $changes, ?User $actor, string $note): ProductionType
    {
        $changes = collect($changes)->only(['name', 'stages', 'measurements', 'custom_fields', 'metrics', 'defaults', 'is_active', 'unit_kind', 'count_unit', 'output_unit'])->all();
        $this->check($changes + $type->only(['category']));
        if (trim($note) === '') {
            throw ValidationException::withMessages(['change_note' => 'Say why the type is changing; the old version stays on the cycles that used it.']);
        }

        return DB::transaction(function () use ($type, $changes, $actor, $note) {
            $type->update($changes + ['version' => $type->version + 1]);
            $this->snapshot($type->fresh(), $actor, $note);

            return $type->fresh();
        });
    }

    /** Stages, measurements, fields, metrics and defaults with every ancestor folded in. */
    public function definition(ProductionType $type): array
    {
        $chain = [];
        for ($t = $type; $t; $t = $t->parent_id ? ProductionType::query()->find($t->parent_id) : null) {
            array_unshift($chain, $t);
            if (count($chain) > 5) {
                break;
            }
        }
        $def = ['code' => $type->code, 'name' => $type->name, 'category' => $type->category, 'unit_kind' => $type->unit_kind,
            'count_unit' => $type->count_unit, 'output_unit' => $type->output_unit, 'version' => $type->version,
            'stages' => [], 'measurements' => [], 'custom_fields' => [], 'metrics' => [], 'defaults' => []];
        foreach ($chain as $t) {
            foreach (['stages', 'measurements', 'custom_fields', 'metrics'] as $k) {
                foreach ((array) $t->{$k} as $entry) {
                    $i = collect($def[$k])->search(fn ($e) => ($e['code'] ?? null) === ($entry['code'] ?? null));
                    $i === false ? $def[$k][] = $entry : $def[$k][$i] = $entry;
                }
            }
            $def['defaults'] = array_merge($def['defaults'], (array) $t->defaults);
        }

        return $def;
    }

    private function snapshot(ProductionType $type, ?User $actor, string $note): void
    {
        TypeVersion::create(['company_id' => $type->company_id, 'production_type_id' => $type->id, 'version' => $type->version,
            'definition' => $this->definition($type), 'change_note' => $note, 'changed_by' => $actor?->id]);
    }

    private function check(array $d): void
    {
        if (isset($d['category']) && ! in_array($d['category'], ProductionType::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Choose a production category.']);
        }
        foreach ((array) ($d['metrics'] ?? []) as $m) {
            try {
                Formula::validate((string) ($m['formula'] ?? ''), self::VARIABLES);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['metrics' => ($m['name'] ?? $m['code'] ?? 'A metric').': '.$e->getMessage()]);
            }
        }
        foreach ((array) ($d['custom_fields'] ?? []) as $f) {
            if (empty($f['code']) || ! in_array($f['type'] ?? 'text', ['text', 'number', 'date', 'choice', 'boolean'], true)) {
                throw ValidationException::withMessages(['custom_fields' => 'Each custom field needs a code and a type (text, number, date, choice or yes/no).']);
            }
        }
        $codes = array_column((array) ($d['stages'] ?? []), 'code');
        if (count($codes) !== count(array_unique($codes))) {
            throw ValidationException::withMessages(['stages' => 'Two stages share a code.']);
        }
    }

    /** The types a new organisation starts with. Thresholds: [metric, min, max, severity, sustain seconds]. */
    public static function defaults(): array
    {
        $poultryMetrics = [
            ['code' => 'livability', 'name' => 'Livability', 'formula' => '(opening_count - mortality - culls) / opening_count * 100', 'unit' => '%', 'better' => 'higher'],
            ['code' => 'mortality_pct', 'name' => 'Mortality', 'formula' => '(mortality + culls) / opening_count * 100', 'unit' => '%', 'better' => 'lower'],
            ['code' => 'fcr', 'name' => 'Feed conversion ratio', 'formula' => 'feed_kg / (live_weight_kg + harvest_qty - opening_weight_kg)', 'unit' => 'kg/kg', 'better' => 'lower'],
            ['code' => 'water_per_bird', 'name' => 'Water per bird', 'formula' => 'water_l / current_count', 'unit' => 'l', 'better' => 'neutral'],
            ['code' => 'water_feed_ratio', 'name' => 'Water to feed', 'formula' => 'water_l / feed_kg', 'unit' => 'l/kg', 'better' => 'neutral'],
            ['code' => 'cost_per_bird', 'name' => 'Cost per live bird', 'formula' => 'cost / current_count', 'unit' => 'R', 'better' => 'lower'],
        ];
        $poultryMeasures = [
            ['code' => 'temperature', 'name' => 'House temperature', 'unit' => '°C', 'kind' => 'sensor'],
            ['code' => 'humidity', 'name' => 'Relative humidity', 'unit' => '%', 'kind' => 'sensor'],
            ['code' => 'ammonia', 'name' => 'Ammonia', 'unit' => 'ppm', 'kind' => 'sensor'],
            ['code' => 'co2', 'name' => 'CO₂', 'unit' => 'ppm', 'kind' => 'sensor'],
            ['code' => 'water_flow', 'name' => 'Water flow', 'unit' => 'l/h', 'kind' => 'sensor'],
            ['code' => 'mortality', 'name' => 'Mortality', 'unit' => 'birds', 'kind' => 'record'],
            ['code' => 'feed', 'name' => 'Feed delivered', 'unit' => 'kg', 'kind' => 'record'],
            ['code' => 'weight', 'name' => 'Average weight', 'unit' => 'kg', 'kind' => 'record'],
        ];
        $poultryLimits = [['temperature', 18, 32, 'critical', 600], ['humidity', 40, 75, 'warning', 1800], ['ammonia', null, 25, 'warning', 900],
            ['co2', null, 3000, 'warning', 900], ['water_flow', 0.01, null, 'critical', 1800]];

        return [
            'BROILER' => ['name' => 'Broilers', 'category' => 'poultry', 'unit_kind' => 'house', 'count_unit' => 'birds', 'output_unit' => 'kg',
                'stages' => [['code' => 'placement', 'name' => 'Placement', 'day_to' => 0], ['code' => 'brooding', 'name' => 'Brooding', 'day_to' => 10],
                    ['code' => 'grower', 'name' => 'Grower', 'day_to' => 24], ['code' => 'finisher', 'name' => 'Finisher', 'day_to' => 35],
                    ['code' => 'depletion', 'name' => 'Depletion'], ['code' => 'cleanout', 'name' => 'Clean-out']],
                'measurements' => $poultryMeasures, 'metrics' => $poultryMetrics,
                'custom_fields' => [['code' => 'hatchery', 'name' => 'Hatchery', 'type' => 'text', 'required' => false]],
                'defaults' => ['cycle_days' => 35, 'target_weight_kg' => 2.1, 'species' => 'CHICKEN-B', 'target_fcr' => 1.6, 'daily_mortality_pct' => 0.1],
                'thresholds' => $poultryLimits],
            'LAYER' => ['name' => 'Layers', 'category' => 'poultry', 'unit_kind' => 'house', 'count_unit' => 'birds', 'output_unit' => 'eggs',
                'stages' => [['code' => 'rearing', 'name' => 'Rearing', 'day_to' => 126], ['code' => 'laying', 'name' => 'In lay'], ['code' => 'depletion', 'name' => 'Depletion']],
                'measurements' => $poultryMeasures, 'metrics' => [...array_slice($poultryMetrics, 0, 2),
                    ['code' => 'eggs_per_hen', 'name' => 'Eggs per hen housed', 'formula' => 'eggs / opening_count', 'unit' => 'eggs', 'better' => 'higher']],
                'defaults' => ['cycle_days' => 500, 'species' => 'CHICKEN-L'], 'thresholds' => $poultryLimits],
            'MAIZE' => ['name' => 'Maize', 'category' => 'crop', 'unit_kind' => 'field', 'output_unit' => 't',
                'stages' => [['code' => 'land_prep', 'name' => 'Land preparation'], ['code' => 'planting', 'name' => 'Planting'], ['code' => 'vegetative', 'name' => 'Vegetative'],
                    ['code' => 'tasselling', 'name' => 'Tasselling'], ['code' => 'grain_fill', 'name' => 'Grain fill'], ['code' => 'harvest', 'name' => 'Harvest']],
                'measurements' => [['code' => 'soil_moisture', 'name' => 'Soil moisture', 'unit' => '%', 'kind' => 'sensor'], ['code' => 'rain', 'name' => 'Rainfall', 'unit' => 'mm', 'kind' => 'sensor']],
                'metrics' => [['code' => 'yield_per_area', 'name' => 'Yield per hectare', 'formula' => 'harvest_qty / area', 'unit' => 't/ha', 'better' => 'higher'],
                    ['code' => 'cost_per_area', 'name' => 'Cost per hectare', 'formula' => 'cost / area', 'unit' => 'R/ha', 'better' => 'lower'],
                    ['code' => 'cost_per_unit', 'name' => 'Cost per tonne', 'formula' => 'cost / harvest_qty', 'unit' => 'R/t', 'better' => 'lower']],
                'defaults' => ['cycle_days' => 150, 'crop_type' => 'MAIZE-W'], 'thresholds' => [['soil_moisture', 15, null, 'warning', 3600]]],
            'VEG' => ['name' => 'Open-field vegetables', 'category' => 'crop', 'unit_kind' => 'field', 'output_unit' => 'kg',
                'stages' => [['code' => 'planting', 'name' => 'Planting'], ['code' => 'growing', 'name' => 'Growing'], ['code' => 'harvest', 'name' => 'Harvest']],
                'metrics' => [['code' => 'yield_per_area', 'name' => 'Yield per hectare', 'formula' => 'harvest_qty / area', 'unit' => 'kg/ha', 'better' => 'higher']],
                'defaults' => ['cycle_days' => 90]],
            'BEEF' => ['name' => 'Beef cattle', 'category' => 'livestock', 'unit_kind' => 'pen', 'count_unit' => 'head', 'output_unit' => 'kg',
                'stages' => [['code' => 'backgrounding', 'name' => 'Backgrounding'], ['code' => 'feedlot', 'name' => 'Feedlot'], ['code' => 'market', 'name' => 'Market']],
                'metrics' => [['code' => 'adg', 'name' => 'Average daily gain', 'formula' => '(avg_weight_kg - opening_weight_kg / opening_count) / age_days', 'unit' => 'kg/day', 'better' => 'higher'],
                    ['code' => 'mortality_pct', 'name' => 'Mortality', 'formula' => 'mortality / opening_count * 100', 'unit' => '%', 'better' => 'lower']],
                'defaults' => ['cycle_days' => 120, 'species' => 'CATTLE']],
            'DAIRY' => ['name' => 'Dairy herd', 'category' => 'dairy', 'unit_kind' => 'pen', 'count_unit' => 'cows', 'output_unit' => 'l',
                'stages' => [['code' => 'lactation', 'name' => 'Lactation'], ['code' => 'dry', 'name' => 'Dry period']],
                'metrics' => [['code' => 'milk_per_cow', 'name' => 'Milk per cow', 'formula' => 'milk_l / current_count', 'unit' => 'l', 'better' => 'higher']],
                'defaults' => ['species' => 'CATTLE']],
            'TILAPIA' => ['name' => 'Tilapia', 'category' => 'aquaculture', 'unit_kind' => 'pond', 'count_unit' => 'fish', 'output_unit' => 'kg',
                'stages' => [['code' => 'stocking', 'name' => 'Stocking'], ['code' => 'nursery', 'name' => 'Nursery'], ['code' => 'grow_out', 'name' => 'Grow-out'], ['code' => 'harvest', 'name' => 'Harvest']],
                'measurements' => [['code' => 'dissolved_oxygen', 'name' => 'Dissolved oxygen', 'unit' => 'mg/l', 'kind' => 'sensor'],
                    ['code' => 'water_temperature', 'name' => 'Water temperature', 'unit' => '°C', 'kind' => 'sensor'], ['code' => 'ph', 'name' => 'pH', 'unit' => 'pH', 'kind' => 'sensor']],
                'metrics' => [['code' => 'survival', 'name' => 'Survival', 'formula' => '(opening_count - mortality - culls) / opening_count * 100', 'unit' => '%', 'better' => 'higher'],
                    ['code' => 'fcr', 'name' => 'Feed conversion ratio', 'formula' => 'feed_kg / (live_weight_kg + harvest_qty - opening_weight_kg)', 'unit' => 'kg/kg', 'better' => 'lower']],
                'defaults' => ['cycle_days' => 210], 'thresholds' => [['dissolved_oxygen', 4, null, 'critical', 900], ['ph', 6.5, 9, 'warning', 1800]]],
            'TUNNEL-TOMATO' => ['name' => 'Tunnel tomatoes', 'category' => 'greenhouse', 'unit_kind' => 'greenhouse', 'count_unit' => 'plants', 'output_unit' => 'kg',
                'stages' => [['code' => 'transplant', 'name' => 'Transplant'], ['code' => 'vegetative', 'name' => 'Vegetative'], ['code' => 'harvesting', 'name' => 'Harvesting'], ['code' => 'removal', 'name' => 'Crop removal']],
                'measurements' => [['code' => 'temperature', 'name' => 'Tunnel temperature', 'unit' => '°C', 'kind' => 'sensor'], ['code' => 'humidity', 'name' => 'Relative humidity', 'unit' => '%', 'kind' => 'sensor'],
                    ['code' => 'ec', 'name' => 'Nutrient EC', 'unit' => 'mS/cm', 'kind' => 'sensor']],
                'metrics' => [['code' => 'yield_per_plant', 'name' => 'Yield per plant', 'formula' => 'harvest_qty / opening_count', 'unit' => 'kg', 'better' => 'higher']],
                'defaults' => ['cycle_days' => 270], 'thresholds' => [['temperature', 12, 35, 'warning', 1800]]],
            'PROCESSING' => ['name' => 'Processing line', 'category' => 'processing', 'unit_kind' => 'line', 'output_unit' => 'kg',
                'stages' => [['code' => 'intake', 'name' => 'Intake'], ['code' => 'processing', 'name' => 'Processing'], ['code' => 'qa', 'name' => 'Quality release'], ['code' => 'dispatch', 'name' => 'Dispatch']],
                'metrics' => [['code' => 'cost_per_unit', 'name' => 'Cost per unit made', 'formula' => 'cost / output_qty', 'unit' => 'R', 'better' => 'lower']],
                'defaults' => []],
        ];
    }
}
