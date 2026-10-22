<?php

namespace App\Modules\Agri\Http\Controllers;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmLocation;
use App\Modules\Agri\Models\FieldBoundaryVersion;
use App\Modules\Agri\Models\FieldMapImport;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Services\BoundaryFiles;
use App\Modules\Agri\Services\FarmAccess;
use App\Modules\Agri\Services\FieldMaps;
use App\Modules\Agri\Support\Geo;
use App\Modules\Agri\Support\RoleCatalog as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The field map editor: a farm's boundary, fields, field parts, management zones, locations and
 * soil samples on an aerial basemap, drawn, imported, edited and exported.
 *
 * Whoever may manage fields edits field boundaries, locations and zones (an agronomist who plans
 * crops may draw zones too); the farm boundary is the farm's registration, so it needs farm
 * management. A viewer membership reads the map and changes nothing, and a farm outside the
 * person's farms is a 404 like everywhere else.
 */
class FieldMapController extends AgriController
{
    public function __construct(private FieldMaps $maps) {}

    /** The hub card: the first farm the person reaches, or a page saying there is none. */
    public function home(Request $request): Response|RedirectResponse
    {
        $this->allow($request, R::VIEW_FARMS);
        $farm = Farm::query()->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderBy('name')->first();

        return $farm ? redirect()->route('agri.farms.map', $farm->uuid) : Inertia::render('Modules/Agri/Farms/Map', [
            'farm' => null, 'farms' => [], 'map' => null, 'import' => null, 'focus' => null,
            'options' => $this->options(), 'can' => ['edit' => false, 'edit_farm' => false, 'zones' => false],
        ]);
    }

    public function show(Request $request, Farm $farm): Response
    {
        $this->allow($request, R::VIEW_FARMS);
        $import = $request->query('import')
            ? FieldMapImport::query()->where('farm_id', $farm->id)->where('uuid', $request->query('import'))->where('status', FieldMapImport::PREVIEWED)->first()
            : null;

        return Inertia::render('Modules/Agri/Farms/Map', [
            'farm' => ['id' => $farm->uuid, 'name' => $farm->name],
            'farms' => Farm::query()->orderBy('name')->get(['uuid', 'name'])->map(fn ($f) => ['id' => $f->uuid, 'name' => $f->name]),
            'map' => $this->maps->mapData($farm),
            'import' => $import ? ['id' => $import->uuid, 'file_name' => $import->file_name, 'format' => $import->format,
                'crs' => $import->crs_note, 'features' => $import->features] : null,
            'focus' => $request->query('field'),
            'options' => $this->options(),
            'can' => $this->abilities($request, $farm),
        ]);
    }

    /** Live feedback while drawing: the area, what is wrong and what is worth a warning. */
    public function check(Request $request, Farm $farm): JsonResponse
    {
        $this->allow($request, R::VIEW_FARMS);
        $data = $request->validate(['boundary' => ['required', 'array'], 'field_id' => ['nullable', 'string'], 'kind' => ['nullable', Rule::in(['field', 'farm', 'zone'])]]);
        $g = Geo::polygon($data['boundary']);
        $kind = $data['kind'] ?? 'field';
        $problems = Geo::problems($g, $kind === 'zone' ? 0.001 : Geo::MIN_HECTARES);
        $field = blank($data['field_id'] ?? null) ? null : FarmField::query()->where('farm_id', $farm->id)->where('uuid', $data['field_id'])->first();
        $warnings = [];
        if (! $problems && $kind === 'field') {
            $warnings = $this->maps->fieldWarnings($farm, $field, Geo::tidy($g));
        } elseif (! $problems && $kind === 'zone' && $field && ($shape = Geo::polygon($field->boundary)) && Geo::outsideCount($shape, $g) > 0) {
            $problems[] = "The zone must lie inside {$field->code}'s boundary.";
        }

        return response()->json(['area_ha' => $problems ? null : Geo::hectares($g), 'problems' => $problems, 'warnings' => $warnings]);
    }

    // ── boundaries ───────────────────────────────────────────────────────────────────────────

    public function farmBoundary(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FARMS);
        $this->writable($request, $farm);
        $data = $request->validate(['boundary' => ['nullable', 'array']]);

        $warnings = $this->maps->setFarmBoundary($farm, $data['boundary'] ?? null, $request->user(), FieldBoundaryVersion::DRAWN);

        return $this->done(($data['boundary'] ?? null) ? 'Farm boundary saved.' : 'Farm boundary removed.', $warnings);
    }

    public function storeField(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS);
        $this->writable($request, $farm);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/'],
            'name' => ['required', 'string', 'max:150'],
            'land_use_type' => ['required', Rule::in(FarmField::LAND_USE_TYPES)],
            'area' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'area_unit' => ['nullable', Rule::in(Farm::AREA_UNITS)],
            'soil_type' => ['nullable', 'string', 'max:60'],
            'boundary' => ['required', 'array'],
        ]);
        $boundary = $data['boundary'];
        unset($data['boundary']);
        $data['area_unit'] ??= 'ha';

        [$field, $warnings] = $this->maps->createField($farm, $data, $boundary, $request->user());

        return $this->done("Field {$field->code} added: ".round((float) $field->mapped_area_ha, 2).' ha mapped.', $warnings);
    }

    public function fieldBoundary(Request $request, Farm $farm, string $field): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS);
        $this->writable($request, $farm);
        $row = $this->fieldOf($farm, $field);
        $data = $request->validate(['boundary' => ['nullable', 'array'], 'reason' => ['nullable', 'string', 'max:300']]);

        $warnings = $this->maps->setFieldBoundary($row, $data['boundary'] ?? null, $request->user(), FieldBoundaryVersion::DRAWN, null, $data['reason'] ?? null);

        return $this->done(($data['boundary'] ?? null)
            ? "{$row->code}'s boundary saved: ".round((float) $row->fresh()->mapped_area_ha, 2).' ha mapped.'
            : "{$row->code}'s boundary removed.", $warnings);
    }

    // ── locations ────────────────────────────────────────────────────────────────────────────

    public function storeLocation(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS);
        $this->writable($request, $farm);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'location_type' => ['required', Rule::in(FarmLocation::TYPES)],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        FarmLocation::create($data + ['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'status' => 'active']);

        return $this->done("{$data['name']} placed on the map.", $this->maps->locationWarnings($farm, (float) $data['latitude'], (float) $data['longitude']));
    }

    public function moveLocation(Request $request, Farm $farm, string $location): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS);
        $this->writable($request, $farm);
        $row = FarmLocation::query()->where('farm_id', $farm->id)->where('uuid', $location)->firstOrFail();
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);
        $row->update(['latitude' => $data['latitude'] ?? null, 'longitude' => $data['longitude'] ?? null]);

        return $this->done(isset($data['latitude']) ? "{$row->name} moved." : "{$row->name} taken off the map.",
            isset($data['latitude']) ? $this->maps->locationWarnings($farm, (float) $data['latitude'], (float) $data['longitude']) : []);
    }

    // ── zones ────────────────────────────────────────────────────────────────────────────────

    public function storeZone(Request $request, Farm $farm, string $field): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS, R::PLAN_CROPS);
        $this->writable($request, $farm);
        $row = $this->fieldOf($farm, $field);
        $zone = $this->maps->saveZone($row, $this->zoneData($request), $request->input('boundary', []), $request->user());

        return $this->done("Zone {$zone->name} added to {$row->code}: ".round((float) $zone->area_ha, 2).' ha.');
    }

    public function updateZone(Request $request, Farm $farm, string $field, string $zone): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS, R::PLAN_CROPS);
        $this->writable($request, $farm);
        $row = $this->fieldOf($farm, $field);
        $z = FieldZone::query()->where('field_id', $row->id)->where('uuid', $zone)->where('status', FieldZone::ACTIVE)->firstOrFail();
        $data = $this->zoneData($request);
        $this->maps->saveZone($row, $data, $request->input('boundary', []), $request->user(), $z);

        return $this->done("Zone {$z->name} saved.");
    }

    public function destroyZone(Request $request, Farm $farm, string $field, string $zone): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS, R::PLAN_CROPS);
        $this->writable($request, $farm);
        $row = $this->fieldOf($farm, $field);
        $z = FieldZone::query()->where('field_id', $row->id)->where('uuid', $zone)->where('status', FieldZone::ACTIVE)->firstOrFail();
        // Archived, not deleted: statistics and prescriptions recorded against it keep their zone.
        $z->update(['status' => FieldZone::ARCHIVED]);

        return $this->done("Zone {$z->name} removed.");
    }

    // ── import and export ────────────────────────────────────────────────────────────────────

    public function upload(Request $request, Farm $farm): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS);
        $this->writable($request, $farm);
        $request->validate(['file' => ['required', 'file', 'max:'.(BoundaryFiles::MAX_BYTES / 1024)]]);
        $file = $request->file('file');
        $name = $file->getClientOriginalName();
        $import = $this->maps->preview($farm, (string) file_get_contents($file->getRealPath()), $name, $file->getClientMimeType(), $request->user());

        return redirect()->route('agri.farms.map', [$farm->uuid, 'import' => $import->uuid])
            ->with('success', count($import->features).' shape(s) read from '.$name.'. Match them to fields and apply.');
    }

    public function apply(Request $request, Farm $farm, string $import): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS);
        $this->writable($request, $farm);
        $row = FieldMapImport::query()->where('farm_id', $farm->id)->where('uuid', $import)->firstOrFail();
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.index' => ['required', 'integer', 'min:0'],
            'rows.*.action' => ['required', Rule::in(['match', 'create', 'farm', 'skip'])],
            'rows.*.field_id' => ['nullable', 'string'],
            'rows.*.code' => ['nullable', 'string', 'max:30'],
            'rows.*.name' => ['nullable', 'string', 'max:150'],
            'rows.*.land_use_type' => ['nullable', Rule::in(FarmField::LAND_USE_TYPES)],
        ]);
        if (collect($data['rows'])->contains('action', 'farm')) {
            $this->allow($request, R::MANAGE_FARMS);
        }
        $s = $this->maps->apply($row, $data['rows'], $request->user());

        $parts = array_filter([$s['updated'] ? "{$s['updated']} boundary(ies) updated" : null, $s['created'] ? "{$s['created']} field(s) created" : null,
            $s['farm'] ? 'farm boundary set' : null, $s['skipped'] ? "{$s['skipped']} skipped" : null]);

        return redirect()->route('agri.farms.map', $farm->uuid)->with('success', 'Import applied: '.implode(', ', $parts ?: ['nothing changed']).'.')
            ->with('warning', $s['warnings'] ? implode(' ', array_unique($s['warnings'])) : null);
    }

    public function discard(Request $request, Farm $farm, string $import): RedirectResponse
    {
        $this->allow($request, R::MANAGE_FIELDS);
        $this->writable($request, $farm);
        FieldMapImport::query()->where('farm_id', $farm->id)->where('uuid', $import)->where('status', FieldMapImport::PREVIEWED)->firstOrFail()
            ->update(['status' => FieldMapImport::DISCARDED]);

        return redirect()->route('agri.farms.map', $farm->uuid)->with('success', 'Import discarded; nothing changed.');
    }

    public function export(Request $request, Farm $farm, string $format)
    {
        $this->allow($request, R::VIEW_FARMS);
        abort_unless(in_array($format, ['geojson', 'kml'], true), 404);
        $files = app(BoundaryFiles::class);
        $items = $this->maps->exportItems($farm);
        $base = Str::slug($farm->code.'-'.$farm->name) ?: 'farm';

        return $format === 'kml'
            ? response($files->toKml($items, $farm->name), 200, ['Content-Type' => 'application/vnd.google-earth.kml+xml',
                'Content-Disposition' => "attachment; filename=\"{$base}.kml\"", 'X-Content-Type-Options' => 'nosniff'])
            : response($files->toGeoJson($items, $farm->name), 200, ['Content-Type' => 'application/geo+json',
                'Content-Disposition' => "attachment; filename=\"{$base}.geojson\"", 'X-Content-Type-Options' => 'nosniff']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function abilities(Request $request, Farm $farm): array
    {
        $u = $request->user();
        $write = $farm->isOperational() && app(FarmAccess::class)->canWrite($u, $farm->id);

        return [
            'edit' => $write && $u->can(R::MANAGE_FIELDS),
            'edit_farm' => $write && $u->can(R::MANAGE_FARMS),
            'zones' => $write && ($u->can(R::MANAGE_FIELDS) || $u->can(R::PLAN_CROPS)),
        ];
    }

    /** Changes need write access to the farm and an operational farm. */
    private function writable(Request $request, Farm $farm): void
    {
        $this->assertWritable($request, $farm->id);
        app(\App\Modules\Agri\Services\FarmRegistry::class)->assertOperational($farm);
    }

    private function fieldOf(Farm $farm, string $uuid): FarmField
    {
        return FarmField::query()->where('farm_id', $farm->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function zoneData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'zone_class' => ['required', Rule::in(FieldZone::CLASSES)],
            'soil_type' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'boundary' => ['required', 'array'],
        ]);
    }

    /** Back to the map with the outcome, and any warnings beside it. */
    private function done(string $message, array $warnings = []): RedirectResponse
    {
        $r = back()->with('success', $message);

        return $warnings ? $r->with('warning', implode(' ', array_unique($warnings))) : $r;
    }

    private function options(): array
    {
        return [
            'land_use_types' => FarmField::LAND_USE_TYPES,
            'location_types' => FarmLocation::TYPES,
            'zone_classes' => FieldZone::CLASSES,
            'area_units' => Farm::AREA_UNITS,
        ];
    }
}
