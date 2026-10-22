<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmLocation;
use App\Modules\Agri\Models\FieldBoundaryVersion;
use App\Modules\Agri\Models\FieldMapImport;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Models\SoilTest;
use App\Modules\Agri\Support\Geo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules of a farm's map, whoever changes it — the map editor, a boundary import or the demo.
 *
 * A boundary is validated (closed, not crossing itself, not trivially small) before it is kept.
 * What is merely suspicious is a warning, not a refusal: a field reaching past the farm boundary,
 * a field overlapping another active field, a mapped area well off the recorded one. People often
 * know something the map does not, so they are told and the change is kept.
 *
 * Every boundary change writes a version (old shape, new shape, both areas, who, when), so the
 * area a crop plan was costed on can always be traced. Zones must lie inside their field and may
 * not overlap each other, because statistics and application rates will be attached to them.
 */
class FieldMaps
{
    public function __construct(private FarmRegistry $registry) {}

    // ── boundaries ───────────────────────────────────────────────────────────────────────────

    /**
     * Set (or, with null, remove) a field's boundary. Returns the warnings, if any.
     *
     * @return list<string>
     */
    public function setFieldBoundary(FarmField $field, ?array $geometry, User $actor, string $source = FieldBoundaryVersion::EDITED,
        ?string $sourceName = null, ?string $reason = null): array
    {
        $farm = $field->farm()->withoutGlobalScope('farm_access')->firstOrFail();
        $previous = Geo::polygon($field->boundary);
        $previousHa = $previous ? ($field->mapped_area_ha !== null ? (float) $field->mapped_area_ha : Geo::hectares($previous)) : null;

        if ($geometry === null) {
            if (! $previous) {
                return [];
            }
            $zones = FieldZone::query()->where('field_id', $field->id)->where('status', FieldZone::ACTIVE)->pluck('name');
            if ($zones->isNotEmpty()) {
                throw ValidationException::withMessages(['boundary' => "{$field->code} has management zones ({$zones->implode(', ')}). Remove them before removing the field's boundary."]);
            }

            return DB::transaction(function () use ($field, $farm, $previous, $previousHa, $actor, $reason) {
                $this->version($farm, $field, null, $previous, null, $previousHa, FieldBoundaryVersion::REMOVED, null, $reason, $actor);
                $field->forceFill(['boundary' => null, 'mapped_area_ha' => null])->save();

                return [];
            });
        }

        $g = $this->accept($geometry, 'boundary');
        $ha = Geo::hectares($g);
        $warnings = $this->fieldWarnings($farm, $field, $g, $ha);

        if ($previous && Geo::tidy($previous) == $g) {
            return $warnings;
        }

        return DB::transaction(function () use ($field, $farm, $g, $ha, $previous, $previousHa, $actor, $source, $sourceName, $reason, $warnings) {
            $this->version($farm, $field, $g, $previous, $ha, $previousHa, $previous && $source === FieldBoundaryVersion::DRAWN ? FieldBoundaryVersion::EDITED : $source, $sourceName, $reason, $actor);
            $centre = Geo::centroid($g);
            $field->forceFill(array_filter([
                'boundary' => $g,
                'mapped_area_ha' => $ha,
                // A point is kept where someone set one; otherwise the field's point is its middle.
                'latitude' => $field->latitude === null ? $centre[1] : null,
                'longitude' => $field->longitude === null ? $centre[0] : null,
                // An area nobody recorded is taken from the map.
                'area' => $field->area === null ? $ha : null,
                'area_unit' => $field->area === null ? 'ha' : null,
            ], fn ($v) => $v !== null))->save();

            foreach (FieldZone::query()->where('field_id', $field->id)->where('status', FieldZone::ACTIVE)->get() as $z) {
                if (Geo::outsideCount($g, Geo::polygon($z->boundary)) > 0) {
                    $warnings[] = "Zone {$z->name} is no longer inside {$field->code}'s boundary; redraw it.";
                }
            }

            return $warnings;
        });
    }

    /** @return list<string> */
    public function setFarmBoundary(Farm $farm, ?array $geometry, User $actor, string $source = FieldBoundaryVersion::EDITED, ?string $sourceName = null): array
    {
        $previous = Geo::polygon($farm->boundary);
        $previousHa = $previous ? Geo::hectares($previous) : null;
        $g = $geometry === null ? null : $this->accept($geometry, 'boundary');
        if ($g && $previous && Geo::tidy($previous) == $g) {
            return [];
        }
        $ha = $g ? Geo::hectares($g) : null;
        $warnings = [];
        if ($g) {
            $outside = $this->activeFields($farm)->filter(fn ($f) => Geo::polygon($f->boundary) && Geo::outsideCount($g, Geo::polygon($f->boundary)) > 0)->pluck('code');
            if ($outside->isNotEmpty()) {
                $warnings[] = 'These fields reach outside the new farm boundary: '.$outside->implode(', ').'.';
            }
            $recorded = Farm::hectares($farm->area !== null ? (float) $farm->area : null, $farm->area_unit);
            if (Geo::areaDiffers($recorded, $ha)) {
                $warnings[] = sprintf('The farm boundary measures %s ha; the registered area is %s ha.', round($ha, 2), round($recorded, 2));
            }
        }
        DB::transaction(function () use ($farm, $g, $previous, $ha, $previousHa, $source, $sourceName, $actor) {
            $this->version($farm, null, $g, $previous, $ha, $previousHa, $g ? ($previous && $source === FieldBoundaryVersion::DRAWN ? FieldBoundaryVersion::EDITED : $source) : FieldBoundaryVersion::REMOVED, $sourceName, null, $actor);
            $centre = $g ? Geo::centroid($g) : null;
            $farm->forceFill(array_filter(['boundary' => $g, 'mapped_area_ha' => $ha,
                'latitude' => $centre && $farm->latitude === null ? $centre[1] : null,
                'longitude' => $centre && $farm->longitude === null ? $centre[0] : null], fn ($v) => $v !== null)
                + ($g ? [] : ['boundary' => null, 'mapped_area_ha' => null]))->save();
        });

        return $warnings;
    }

    /**
     * A new field drawn on the map: registered the usual way (code unique on the farm, farm
     * operational), with its boundary as version 1. Its area defaults to the mapped one.
     *
     * @return array{0: FarmField, 1: list<string>}
     */
    public function createField(Farm $farm, array $data, array $geometry, User $actor, string $source = FieldBoundaryVersion::DRAWN, ?string $sourceName = null): array
    {
        $g = $this->accept($geometry, 'boundary');

        return DB::transaction(function () use ($farm, $data, $g, $actor, $source, $sourceName) {
            $field = $this->registry->createField($farm, $data + ['area' => null, 'area_unit' => 'ha', 'land_use_type' => 'field'], $actor);
            $warnings = $this->setFieldBoundary($field, $g, $actor, $source, $sourceName);

            return [$field->fresh(), $warnings];
        });
    }

    /**
     * What is worth knowing about a field's boundary, without refusing it.
     *
     * @return list<string>
     */
    public function fieldWarnings(Farm $farm, ?FarmField $field, array $g, ?float $ha = null): array
    {
        $ha ??= Geo::hectares($g);
        $code = $field?->code ?? 'The new field';
        $out = [];
        $farmShape = Geo::polygon($farm->boundary);
        if ($farmShape && Geo::outsideCount($farmShape, $g) > 0) {
            $out[] = "{$code} reaches outside the farm boundary.";
        }
        $related = $field ? array_filter([$field->id, $field->parent_field_id]) : [];
        $hits = $this->activeFields($farm)
            // A part of a split field sits inside its whole by design; parts of one field must not overlap each other.
            ->reject(fn ($f) => in_array($f->id, $related, true) || ($field && $f->parent_field_id === $field->id))
            ->filter(fn ($f) => ($s = Geo::polygon($f->boundary)) && Geo::overlaps($s, $g))
            ->pluck('code');
        if ($hits->isNotEmpty()) {
            $out[] = "{$code} overlaps ".$hits->implode(', ').'.';
        }
        if ($field) {
            $recorded = $field->hectares();
            if (Geo::areaDiffers($recorded, $ha)) {
                $out[] = sprintf('%s measures %s ha on the map; its recorded area is %s ha.', $code, round($ha, 2), round($recorded, 2));
            }
        }

        return $out;
    }

    // ── zones ────────────────────────────────────────────────────────────────────────────────

    public function saveZone(FarmField $field, array $data, array $geometry, User $actor, ?FieldZone $zone = null): FieldZone
    {
        $shape = Geo::polygon($field->boundary);
        if (! $shape) {
            throw ValidationException::withMessages(['boundary' => "Map {$field->code}'s boundary before drawing zones in it."]);
        }
        $g = $this->accept($geometry, 'boundary', 0.001);
        if (Geo::outsideCount($shape, $g) > 0) {
            throw ValidationException::withMessages(['boundary' => "The zone must lie inside {$field->code}'s boundary."]);
        }
        $clash = FieldZone::query()->where('field_id', $field->id)->where('status', FieldZone::ACTIVE)
            ->when($zone, fn ($q) => $q->whereKeyNot($zone->id))->get()
            ->filter(fn ($z) => Geo::overlaps(Geo::polygon($z->boundary), $g))->pluck('name');
        if ($clash->isNotEmpty()) {
            throw ValidationException::withMessages(['boundary' => 'The zone overlaps '.$clash->implode(', ').'. Zones in a field may not overlap.']);
        }
        $name = trim($data['name']);
        $taken = FieldZone::query()->where('field_id', $field->id)->where('status', FieldZone::ACTIVE)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->when($zone, fn ($q) => $q->whereKeyNot($zone->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => "{$field->code} already has a zone called {$name}."]);
        }
        $values = ['name' => $name, 'zone_class' => $data['zone_class'], 'soil_type' => $data['soil_type'] ?? null,
            'notes' => $data['notes'] ?? null, 'boundary' => $g, 'area_ha' => Geo::hectares($g)];
        if ($zone) {
            $zone->update($values);

            return $zone;
        }

        return FieldZone::create($values + ['company_id' => $field->company_id, 'farm_id' => $field->farm_id, 'field_id' => $field->id,
            'status' => FieldZone::ACTIVE, 'created_by' => $actor->id]);
    }

    // ── locations ────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    public function locationWarnings(Farm $farm, float $lat, float $lng): array
    {
        $shape = Geo::polygon($farm->boundary);

        return $shape && ! Geo::contains($shape, [$lng, $lat]) ? ['The point is outside the farm boundary.'] : [];
    }

    // ── imports ──────────────────────────────────────────────────────────────────────────────

    /** Keep an uploaded file with its features, each offered a match to an existing field. */
    public function preview(Farm $farm, string $bytes, string $fileName, ?string $mime, User $actor): FieldMapImport
    {
        $read = app(BoundaryFiles::class)->read($bytes, $fileName);
        $fields = FarmField::query()->where('farm_id', $farm->id)->where('status', '!=', 'archived')->get();
        $byCode = $fields->keyBy(fn ($f) => mb_strtolower($f->code));
        $byName = $fields->keyBy(fn ($f) => mb_strtolower($f->name));
        $farmShape = Geo::polygon($farm->boundary);

        $features = [];
        foreach ($read['features'] as $i => $f) {
            $match = null;
            $by = null;
            foreach ([[$f['code'], $byCode, 'code'], [$f['name'], $byCode, 'name = code'], [$f['name'], $byName, 'name'], [$f['code'], $byName, 'code = name']] as [$key, $index, $how]) {
                if ($key !== null && isset($index[mb_strtolower(trim($key))])) {
                    $match = $index[mb_strtolower(trim($key))];
                    $by = $how;

                    break;
                }
            }
            $problems = Geo::problems($f['geometry']);
            $kind = $f['kind'];
            $features[] = [
                'index' => $i,
                'name' => $f['name'], 'code' => $f['code'], 'kind' => $kind,
                'properties' => $f['properties'],
                'geometry' => $f['geometry'],
                'area_ha' => $problems ? null : Geo::hectares($f['geometry']),
                'problems' => $problems,
                'outside_farm' => ! $problems && $farmShape && Geo::outsideCount($farmShape, $f['geometry']) > 0,
                'match' => $match?->uuid, 'match_code' => $match?->code, 'match_by' => $by,
                'suggested' => $problems ? 'skip' : ($kind === 'farm' ? 'farm' : ($kind === 'zone' || $kind === 'location' ? 'skip' : ($match ? 'match' : 'create'))),
            ];
        }

        return FieldMapImport::create([
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'file_name' => mb_substr($fileName, 0, 200),
            'format' => $read['format'], 'mime' => $mime ? mb_substr($mime, 0, 120) : null, 'data' => base64_encode($bytes),
            'crs_note' => mb_substr($read['crs'].($read['skipped'] ? " · {$read['skipped']} non-polygon shape(s) left out" : ''), 0, 300),
            'features' => $features, 'status' => FieldMapImport::PREVIEWED, 'created_by' => $actor->id,
        ]);
    }

    /**
     * Apply the choices made on a preview: each feature updates a field's boundary, becomes a new
     * field, becomes the farm boundary, or is skipped. All or nothing.
     *
     * @param list<array{index: int, action: string, field_id?: ?string, code?: ?string, name?: ?string, land_use_type?: ?string}> $rows
     * @return array{updated: int, created: int, farm: bool, skipped: int, warnings: list<string>}
     */
    public function apply(FieldMapImport $import, array $rows, User $actor): array
    {
        if ($import->status !== FieldMapImport::PREVIEWED) {
            throw ValidationException::withMessages(['import' => 'This import has already been '.$import->status.'.']);
        }
        $farm = Farm::query()->findOrFail($import->farm_id);
        $features = collect($import->features)->keyBy('index');
        $byIndex = collect($rows)->keyBy(fn ($r) => (int) $r['index']);
        if ($byIndex->filter(fn ($r) => $r['action'] === 'farm')->count() > 1) {
            throw ValidationException::withMessages(['rows' => 'Only one shape can become the farm boundary.']);
        }
        $targets = $byIndex->filter(fn ($r) => $r['action'] === 'match')->map(fn ($r) => $r['field_id'] ?? null);
        if ($targets->filter()->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['rows' => 'Two shapes are matched to the same field.']);
        }

        return DB::transaction(function () use ($import, $farm, $features, $byIndex, $actor) {
            $summary = ['updated' => 0, 'created' => 0, 'farm' => false, 'skipped' => 0, 'warnings' => []];
            foreach ($byIndex as $index => $row) {
                $f = $features[$index] ?? null;
                if (! $f) {
                    throw ValidationException::withMessages(['rows' => "Shape {$index} is not in this file."]);
                }
                $label = $f['name'] ?? $f['code'] ?? 'Shape '.($index + 1);
                if ($row['action'] !== 'skip' && $f['problems']) {
                    throw ValidationException::withMessages(['rows' => "{$label}: ".implode(' ', $f['problems'])]);
                }
                switch ($row['action']) {
                    case 'skip':
                        $summary['skipped']++;
                        break;
                    case 'farm':
                        array_push($summary['warnings'], ...$this->setFarmBoundary($farm, $f['geometry'], $actor, FieldBoundaryVersion::IMPORTED, $import->file_name));
                        $summary['farm'] = true;
                        break;
                    case 'match':
                        $field = FarmField::query()->where('farm_id', $farm->id)->where('uuid', $row['field_id'] ?? '')->first();
                        if (! $field) {
                            throw ValidationException::withMessages(['rows' => "{$label}: choose a field on this farm."]);
                        }
                        array_push($summary['warnings'], ...$this->setFieldBoundary($field, $f['geometry'], $actor, FieldBoundaryVersion::IMPORTED, $import->file_name));
                        $summary['updated']++;
                        break;
                    case 'create':
                        $code = trim((string) ($row['code'] ?? $f['code'] ?? ''));
                        $name = trim((string) ($row['name'] ?? $f['name'] ?? ''));
                        if ($code === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/', $code) || mb_strlen($code) > 30) {
                            throw ValidationException::withMessages(['rows' => "{$label}: give the new field a code (letters, digits, - _ . /, up to 30)."]);
                        }
                        [$field, $w] = $this->createField($farm, ['code' => $code, 'name' => $name !== '' ? mb_substr($name, 0, 150) : $code,
                            'land_use_type' => in_array($row['land_use_type'] ?? null, FarmField::LAND_USE_TYPES, true) ? $row['land_use_type'] : 'field'],
                            $f['geometry'], $actor, FieldBoundaryVersion::IMPORTED, $import->file_name);
                        array_push($summary['warnings'], ...$w);
                        $summary['created']++;
                        break;
                    default:
                        throw ValidationException::withMessages(['rows' => "{$label}: unknown choice."]);
                }
            }
            $summary['skipped'] += $features->keys()->diff($byIndex->keys())->count();
            $import->update(['status' => FieldMapImport::APPLIED, 'summary' => $summary, 'applied_by' => $actor->id, 'applied_at' => now()]);

            return $summary;
        });
    }

    // ── reading the map ──────────────────────────────────────────────────────────────────────

    /** Everything the map editor draws for a farm. */
    public function mapData(Farm $farm): array
    {
        $fields = FarmField::query()->where('farm_id', $farm->id)->orderBy('code')->get();
        $codes = $fields->pluck('code', 'id');
        $zones = FieldZone::query()->where('farm_id', $farm->id)->where('status', FieldZone::ACTIVE)->orderBy('name')->get()->groupBy('field_id');
        $versions = FieldBoundaryVersion::query()->where('farm_id', $farm->id)->orderByDesc('version')->orderByDesc('id')->get();
        $names = User::withoutGlobalScopes()->whereIn('id', $versions->pluck('changed_by')->filter()->unique())->get()->mapWithKeys(fn ($u) => [$u->id => $u->name]);
        $history = $versions->groupBy(fn ($v) => $v->field_id ?? 0);

        $farmShape = Geo::polygon($farm->boundary);
        $active = $fields->where('status', 'active');
        $rows = $fields->map(function (FarmField $f) use ($codes, $zones, $history, $names, $farmShape, $active) {
            $shape = Geo::polygon($f->boundary);
            $mapped = $shape ? ($f->mapped_area_ha !== null ? (float) $f->mapped_area_ha : Geo::hectares($shape)) : null;
            $recorded = $f->hectares();

            return [
                'id' => $f->uuid, 'code' => $f->code, 'name' => $f->name, 'land_use_type' => $f->land_use_type, 'status' => $f->status,
                'soil_type' => $f->soil_type, 'parent' => $f->parent_field_id ? ($codes[$f->parent_field_id] ?? null) : null,
                'recorded_ha' => $recorded !== null ? round($recorded, 4) : null, 'mapped_ha' => $mapped,
                'area_differs' => Geo::areaDiffers($recorded, $mapped),
                'outside_farm' => $shape && $farmShape && $f->status === 'active' ? Geo::outsideCount($farmShape, $shape) > 0 : false,
                'overlaps' => $shape && $f->status === 'active' ? $active->reject(fn ($o) => $o->id === $f->id || $o->id === $f->parent_field_id || $o->parent_field_id === $f->id)
                    ->filter(fn ($o) => ($s = Geo::polygon($o->boundary)) && Geo::overlaps($s, $shape))->pluck('code')->values()->all() : [],
                'latitude' => $f->latitude !== null ? (float) $f->latitude : null, 'longitude' => $f->longitude !== null ? (float) $f->longitude : null,
                'boundary' => $shape,
                'zones' => ($zones[$f->id] ?? collect())->map(fn (FieldZone $z) => $this->zoneRow($z))->values(),
                'history' => ($history[$f->id] ?? collect())->map(fn ($v) => $this->versionRow($v, $names))->values(),
            ];
        })->values();

        $soil = SoilTest::query()->where('farm_id', $farm->id)->orderByDesc('sampled_on')->get()
            ->map(function (SoilTest $s) use ($codes, $fields) {
                $lat = $s->latitude;
                $lng = $s->longitude;
                if ($lat === null) {   // a sample with no point of its own is shown at its field's point
                    $f = $fields->firstWhere('id', $s->field_id);
                    $lat = $f?->latitude;
                    $lng = $f?->longitude;
                }

                return $lat === null ? null : ['id' => $s->uuid, 'field' => $codes[$s->field_id] ?? null, 'sampled_on' => $s->sampled_on?->toDateString(),
                    'latitude' => (float) $lat, 'longitude' => (float) $lng, 'placed_at_field' => $s->latitude === null,
                    'ph' => $s->ph !== null ? (float) $s->ph : null, 'organic_carbon_pct' => $s->organic_carbon_pct !== null ? (float) $s->organic_carbon_pct : null,
                    'p_mg_kg' => $s->p_mg_kg !== null ? (float) $s->p_mg_kg : null, 'k_mg_kg' => $s->k_mg_kg !== null ? (float) $s->k_mg_kg : null,
                    'ca_mg_kg' => $s->ca_mg_kg !== null ? (float) $s->ca_mg_kg : null, 'mg_mg_kg' => $s->mg_mg_kg !== null ? (float) $s->mg_mg_kg : null,
                    'laboratory' => $s->laboratory, 'sample_ref' => $s->sample_ref, 'recommendation' => $s->recommendation];
            })->filter()->values();

        return [
            'farm' => ['id' => $farm->uuid, 'code' => $farm->code, 'name' => $farm->name, 'status' => $farm->status,
                'latitude' => $farm->latitude !== null ? (float) $farm->latitude : null, 'longitude' => $farm->longitude !== null ? (float) $farm->longitude : null,
                'boundary' => $farmShape, 'mapped_ha' => $farmShape ? Geo::hectares($farmShape) : null,
                'recorded_ha' => Farm::hectares($farm->area !== null ? (float) $farm->area : null, $farm->area_unit),
                'history' => ($history[0] ?? collect())->map(fn ($v) => $this->versionRow($v, $names))->values()],
            'fields' => $rows,
            'locations' => FarmLocation::query()->where('farm_id', $farm->id)->orderBy('name')->get()->map(fn (FarmLocation $l) => [
                'id' => $l->uuid, 'name' => $l->name, 'location_type' => $l->location_type, 'status' => $l->status, 'notes' => $l->notes,
                'latitude' => $l->latitude !== null ? (float) $l->latitude : null, 'longitude' => $l->longitude !== null ? (float) $l->longitude : null,
            ])->values(),
            'soil' => $soil,
            'limits' => ['min_ha' => Geo::MIN_HECTARES, 'area_tolerance' => Geo::AREA_TOLERANCE],
        ];
    }

    /** A farm's map as export items (see BoundaryFiles::toGeoJson). */
    public function exportItems(Farm $farm): array
    {
        $items = [];
        if ($s = Geo::polygon($farm->boundary)) {
            $items[] = ['kind' => 'farm', 'properties' => ['code' => $farm->code, 'name' => $farm->name, 'mapped_area_ha' => Geo::hectares($s)], 'geometry' => $s];
        }
        $fields = FarmField::query()->where('farm_id', $farm->id)->where('status', '!=', 'archived')->orderBy('code')->get();
        $codes = $fields->pluck('code', 'id');
        foreach ($fields as $f) {
            if ($s = Geo::polygon($f->boundary)) {
                $items[] = ['kind' => 'field', 'properties' => ['code' => $f->code, 'name' => $f->name, 'land_use_type' => $f->land_use_type,
                    'area' => $f->area !== null ? (float) $f->area : null, 'area_unit' => $f->area_unit, 'mapped_area_ha' => Geo::hectares($s),
                    'soil_type' => $f->soil_type, 'irrigation' => $f->irrigation, 'parent_code' => $f->parent_field_id ? ($codes[$f->parent_field_id] ?? null) : null,
                    'status' => $f->status], 'geometry' => $s];
            }
        }
        foreach (FieldZone::query()->where('farm_id', $farm->id)->where('status', FieldZone::ACTIVE)->orderBy('name')->get() as $z) {
            $items[] = ['kind' => 'zone', 'properties' => ['name' => $z->name, 'field_code' => $codes[$z->field_id] ?? null, 'zone_class' => $z->zone_class,
                'soil_type' => $z->soil_type, 'area_ha' => (float) $z->area_ha], 'geometry' => Geo::polygon($z->boundary)];
        }
        foreach (FarmLocation::query()->where('farm_id', $farm->id)->whereNotNull('latitude')->orderBy('name')->get() as $l) {
            $items[] = ['kind' => 'location', 'properties' => ['name' => $l->name, 'location_type' => $l->location_type],
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $l->longitude, (float) $l->latitude]]];
        }

        return $items;
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    /** A boundary that passes, tidied, or a refusal naming every problem. */
    private function accept(array $geometry, string $key, float $minHa = Geo::MIN_HECTARES): array
    {
        $g = Geo::polygon($geometry);
        $problems = Geo::problems($g, $minHa);
        if ($problems) {
            throw ValidationException::withMessages([$key => $problems]);
        }

        return Geo::tidy($g);
    }

    private function activeFields(Farm $farm)
    {
        return FarmField::query()->where('farm_id', $farm->id)->where('status', 'active')->whereNotNull('boundary')->orderBy('code')->get();
    }

    private function version(Farm $farm, ?FarmField $field, ?array $g, ?array $previous, ?float $ha, ?float $previousHa, string $source,
        ?string $sourceName, ?string $reason, User $actor): FieldBoundaryVersion
    {
        $next = (int) FieldBoundaryVersion::query()->where('farm_id', $farm->id)
            ->when($field, fn ($q) => $q->where('field_id', $field->id), fn ($q) => $q->whereNull('field_id'))->max('version') + 1;

        return FieldBoundaryVersion::create([
            'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'field_id' => $field?->id, 'version' => $next,
            'source' => $source, 'source_name' => $sourceName ? mb_substr($sourceName, 0, 200) : null,
            'boundary' => $g, 'previous_boundary' => $previous, 'area_ha' => $ha, 'previous_area_ha' => $previousHa,
            'recorded_area_ha' => $field ? $field->hectares() : Farm::hectares($farm->area !== null ? (float) $farm->area : null, $farm->area_unit),
            'reason' => $reason ? mb_substr($reason, 0, 300) : null, 'changed_by' => $actor->id,
        ]);
    }

    private function zoneRow(FieldZone $z): array
    {
        return ['id' => $z->uuid, 'name' => $z->name, 'zone_class' => $z->zone_class, 'soil_type' => $z->soil_type, 'notes' => $z->notes,
            'area_ha' => (float) $z->area_ha, 'boundary' => Geo::polygon($z->boundary)];
    }

    private function versionRow(FieldBoundaryVersion $v, $names): array
    {
        return ['id' => $v->uuid, 'version' => $v->version, 'source' => $v->source, 'source_name' => $v->source_name,
            'area_ha' => $v->area_ha !== null ? (float) $v->area_ha : null, 'previous_area_ha' => $v->previous_area_ha !== null ? (float) $v->previous_area_ha : null,
            'recorded_area_ha' => $v->recorded_area_ha !== null ? (float) $v->recorded_area_ha : null,
            'reason' => $v->reason, 'by' => $names[$v->changed_by] ?? null, 'at' => $v->created_at?->format('Y-m-d H:i'),
            'boundary' => $v->boundary];
    }
}
