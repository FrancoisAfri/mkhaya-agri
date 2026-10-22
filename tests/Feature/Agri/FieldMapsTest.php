<?php

namespace Tests\Feature\Agri;

use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\FarmLocation;
use App\Modules\Agri\Models\FieldBoundaryVersion;
use App\Modules\Agri\Models\FieldMapImport;
use App\Modules\Agri\Models\FieldZone;
use App\Modules\Agri\Services\BoundaryFiles;
use App\Modules\Agri\Support\Geo;
use App\Modules\Agri\Support\Projection;
use Illuminate\Http\UploadedFile;

/**
 * Field maps: boundaries drawn, edited, imported and exported; their history; management zones;
 * and who may change them.
 */
class FieldMapsTest extends AgriTestCase
{
    private const LAT = -28.2;

    private const LNG = 28.3;

    /** A square $side metres across, its south-west corner $east/$north metres from the base point. */
    private function square(float $side, float $east = 0, float $north = 0): array
    {
        $dLat = fn (float $m) => $m / 111319.49;
        $dLng = fn (float $m) => $m / (111319.49 * cos(deg2rad(self::LAT)));
        $x0 = self::LNG + $dLng($east);
        $y0 = self::LAT + $dLat($north);
        $x1 = $x0 + $dLng($side);
        $y1 = $y0 + $dLat($side);

        return ['type' => 'Polygon', 'coordinates' => [[[$x0, $y0], [$x1, $y0], [$x1, $y1], [$x0, $y1], [$x0, $y0]]]];
    }

    private function drawField(Farm $farm, string $code, array $shape, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('agri.farms.map.fields.store', $farm->uuid), $extra + [
            'code' => $code, 'name' => "Land {$code}", 'land_use_type' => 'field', 'boundary' => $shape,
        ]);
    }

    private function fieldRow(Farm $farm, string $code): FarmField
    {
        return FarmField::withoutGlobalScopes()->where('farm_id', $farm->id)->where('code', $code)->firstOrFail();
    }

    // ── drawing, area, history ───────────────────────────────────────────────────────────────

    public function test_drawing_a_field_measures_it_and_every_change_is_kept_in_its_history(): void
    {
        $farm = $this->farm('NORTH');

        $this->drawField($farm, 'L1', $this->square(200))->assertSessionHasNoErrors()->assertRedirect();
        $field = $this->fieldRow($farm, 'L1');
        $this->assertEqualsWithDelta(4.0, (float) $field->mapped_area_ha, 0.02, 'A 200 m square is 4 ha.');
        $this->assertEqualsWithDelta(4.0, (float) $field->area, 0.02, 'An area nobody recorded is taken from the map.');
        $this->assertNotNull($field->latitude, 'The field gets a point in its middle.');

        $v1 = FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $field->id)->sole();
        $this->assertSame([1, 'drawn', null], [$v1->version, $v1->source, $v1->previous_area_ha]);
        $this->assertSame($this->admin->id, (int) $v1->changed_by);

        // Redrawn larger: version 2 keeps the old shape and area beside the new.
        $this->actingAs($this->admin)->put(route('agri.farms.map.fields.boundary', [$farm->uuid, $field->uuid]), [
            'boundary' => $this->square(300), 'reason' => 'Walked the fence with GPS',
        ])->assertSessionHasNoErrors();
        $v2 = FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $field->id)->where('version', 2)->sole();
        $this->assertSame('edited', $v2->source);
        $this->assertEqualsWithDelta(4.0, (float) $v2->previous_area_ha, 0.02);
        $this->assertEqualsWithDelta(9.0, (float) $v2->area_ha, 0.03);
        $this->assertEqualsWithDelta(4.0, (float) $v2->recorded_area_ha, 0.02, 'The recorded area at the time is kept for the audit.');
        $this->assertSame('Walked the fence with GPS', $v2->reason);
        $this->assertEquals($v1->boundary, $v2->previous_boundary);

        // The page shows it: mapped against recorded, flagged, with its history.
        $this->actingAs($this->admin)->get(route('agri.farms.map', $farm->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Agri/Farms/Map')
                ->where('map.fields.0.code', 'L1')
                ->where('map.fields.0.area_differs', true)
                ->where('map.fields.0.history.0.version', 2)
                ->where('map.fields.0.history.1.version', 1)
                ->where('can.edit', true));

        // Removing the boundary is a version too.
        $this->actingAs($this->admin)->put(route('agri.farms.map.fields.boundary', [$farm->uuid, $field->uuid]), ['boundary' => null])->assertSessionHasNoErrors();
        $this->assertNull($field->fresh()->boundary);
        $this->assertSame('removed', FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $field->id)->where('version', 3)->value('source'));
    }

    public function test_an_unchanged_boundary_adds_no_version(): void
    {
        $farm = $this->farm('NORTH');
        $this->drawField($farm, 'L1', $this->square(200));
        $field = $this->fieldRow($farm, 'L1');

        $this->actingAs($this->admin)->put(route('agri.farms.map.fields.boundary', [$farm->uuid, $field->uuid]), ['boundary' => $field->boundary])->assertSessionHasNoErrors();

        $this->assertSame(1, FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $field->id)->count());
    }

    public function test_invalid_polygons_are_refused_and_nothing_changes(): void
    {
        $farm = $this->farm('NORTH');
        $field = $this->field($farm, 'L1', 10);
        $url = route('agri.farms.map.fields.boundary', [$farm->uuid, $field->uuid]);
        $sq = $this->square(200)['coordinates'][0];

        $bowtie = ['type' => 'Polygon', 'coordinates' => [[$sq[0], $sq[2], $sq[1], $sq[3], $sq[0]]]];
        $this->actingAs($this->admin)->put($url, ['boundary' => $bowtie])->assertSessionHasErrors('boundary');
        $this->assertStringContainsString('crosses itself', session('errors')->first('boundary'));

        $open = ['type' => 'Polygon', 'coordinates' => [array_slice($sq, 0, 4)]];
        $this->actingAs($this->admin)->put($url, ['boundary' => $open])->assertSessionHasErrors('boundary');
        $this->assertStringContainsString('not closed', session('errors')->first('boundary'));

        $this->actingAs($this->admin)->put($url, ['boundary' => $this->square(5)])->assertSessionHasErrors('boundary');
        $this->assertStringContainsString('too small', session('errors')->first('boundary'));

        $this->actingAs($this->admin)->put($url, ['boundary' => ['type' => 'Point', 'coordinates' => [self::LNG, self::LAT]]])->assertSessionHasErrors('boundary');

        $this->assertNull($field->fresh()->boundary);
        $this->assertSame(0, FieldBoundaryVersion::withoutGlobalScopes()->count());
    }

    public function test_a_field_outside_the_farm_or_over_another_field_is_kept_with_a_warning(): void
    {
        $farm = $this->farm('NORTH');
        $this->asCompany($this->company, fn () => $farm->forceFill(['boundary' => $this->square(1000)])->save());
        $this->drawField($farm, 'L1', $this->square(200, 100, 100))->assertSessionHasNoErrors()->assertSessionMissing('warning');

        // Neighbours sharing a fence line are not an overlap.
        $this->drawField($farm, 'L2', $this->square(200, 300, 100))->assertSessionHasNoErrors()->assertSessionMissing('warning');

        // Half over L1, and reaching past the farm's southern boundary.
        $this->drawField($farm, 'L3', $this->square(200, 200, -50))->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($w) => str_contains($w, 'outside the farm boundary') && str_contains($w, 'overlaps L1, L2'));
        $this->assertNotNull($this->fieldRow($farm, 'L3')->boundary, 'A warning does not stop the change.');

        $this->actingAs($this->admin)->get(route('agri.farms.map', $farm->uuid))
            ->assertInertia(fn ($p) => $p->where('map.fields.2.code', 'L3')->where('map.fields.2.outside_farm', true)
                ->where('map.fields.2.overlaps', ['L1', 'L2'])->where('map.fields.0.overlaps', ['L3']));

        // The live check says the same while somebody draws.
        $this->actingAs($this->admin)->postJson(route('agri.farms.map.check', $farm->uuid), ['boundary' => $this->square(200, 150, 150), 'kind' => 'field'])
            ->assertOk()->assertJsonPath('problems', [])->assertJson(fn ($j) => $j->where('warnings.0', 'The new field overlaps L1, L2.')->etc());
        $this->actingAs($this->admin)->postJson(route('agri.farms.map.check', $farm->uuid), ['boundary' => $this->square(3), 'kind' => 'field'])
            ->assertOk()->assertJsonPath('area_ha', null)->assertJsonCount(1, 'problems');
    }

    public function test_the_farm_boundary_is_versioned_and_warns_about_fields_left_outside(): void
    {
        $farm = $this->farm('NORTH');
        $this->drawField($farm, 'L1', $this->square(200, 900, 900));

        $this->actingAs($this->admin)->put(route('agri.farms.map.boundary', $farm->uuid), ['boundary' => $this->square(1000)])
            ->assertSessionHasNoErrors()->assertSessionHas('warning', fn ($w) => str_contains($w, 'L1'));

        $farm->refresh();
        $this->assertEqualsWithDelta(100, (float) $farm->mapped_area_ha, 0.3);
        $this->assertSame(1, FieldBoundaryVersion::withoutGlobalScopes()->where('farm_id', $farm->id)->whereNull('field_id')->count());
    }

    // ── zones ────────────────────────────────────────────────────────────────────────────────

    public function test_zones_lie_inside_their_field_and_do_not_overlap(): void
    {
        $farm = $this->farm('NORTH');
        $bare = $this->field($farm, 'BARE', 5);
        $this->drawField($farm, 'L1', $this->square(400));
        $field = $this->fieldRow($farm, 'L1');
        $url = route('agri.farms.map.zones.store', [$farm->uuid, $field->uuid]);
        $zone = fn (array $shape, string $name = 'Zone A', string $class = 'high') => ['name' => $name, 'zone_class' => $class, 'soil_type' => 'Hutton', 'boundary' => $shape];

        $this->actingAs($this->admin)->post($url, $zone($this->square(200, 10, 10)))->assertSessionHasNoErrors();
        $z = FieldZone::withoutGlobalScopes()->where('field_id', $field->id)->sole();
        $this->assertEqualsWithDelta(4.0, (float) $z->area_ha, 0.02);
        $this->assertSame([$farm->id, 'high', 'Hutton'], [(int) $z->farm_id, $z->zone_class, $z->soil_type]);

        // Beside it, sharing an edge: fine.
        $this->actingAs($this->admin)->post($url, $zone($this->square(150, 210, 10), 'Zone B', 'low'))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post($url, $zone($this->square(200, 300, 10), 'Outside'))->assertSessionHasErrors('boundary');
        $this->assertStringContainsString('inside L1', session('errors')->first('boundary'));

        $this->actingAs($this->admin)->post($url, $zone($this->square(100, 150, 50), 'Over A'))->assertSessionHasErrors('boundary');
        $this->assertStringContainsString('overlaps Zone A', session('errors')->first('boundary'));

        $this->actingAs($this->admin)->post(route('agri.farms.map.zones.store', [$farm->uuid, $bare->uuid]), $zone($this->square(50)))
            ->assertSessionHasErrors('boundary');

        // A boundary with zones in it cannot simply be removed; archiving the zones frees it.
        $this->actingAs($this->admin)->put(route('agri.farms.map.fields.boundary', [$farm->uuid, $field->uuid]), ['boundary' => null])->assertSessionHasErrors('boundary');
        foreach (FieldZone::withoutGlobalScopes()->where('field_id', $field->id)->get() as $row) {
            $this->actingAs($this->admin)->delete(route('agri.farms.map.zones.destroy', [$farm->uuid, $field->uuid, $row->uuid]))->assertSessionHasNoErrors();
        }
        $this->assertSame(0, FieldZone::withoutGlobalScopes()->where('status', 'active')->count());
        $this->assertSame(2, FieldZone::withoutGlobalScopes()->where('status', 'archived')->count(), 'Zones are archived, never lost.');
        $this->actingAs($this->admin)->put(route('agri.farms.map.fields.boundary', [$farm->uuid, $field->uuid]), ['boundary' => null])->assertSessionHasNoErrors();
    }

    public function test_a_zone_can_be_reshaped_but_not_out_of_its_field(): void
    {
        $farm = $this->farm('NORTH');
        $this->drawField($farm, 'L1', $this->square(400));
        $field = $this->fieldRow($farm, 'L1');
        $this->actingAs($this->admin)->post(route('agri.farms.map.zones.store', [$farm->uuid, $field->uuid]),
            ['name' => 'Wet corner', 'zone_class' => 'low', 'boundary' => $this->square(100, 10, 10)])->assertSessionHasNoErrors();
        $z = FieldZone::withoutGlobalScopes()->sole();
        $url = route('agri.farms.map.zones.update', [$farm->uuid, $field->uuid, $z->uuid]);

        $this->actingAs($this->admin)->patch($url, ['name' => 'Wet corner', 'zone_class' => 'medium', 'boundary' => $this->square(150, 10, 10)])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(2.25, (float) $z->fresh()->area_ha, 0.02);
        $this->assertSame('medium', $z->fresh()->zone_class);

        $this->actingAs($this->admin)->patch($url, ['name' => 'Wet corner', 'zone_class' => 'medium', 'boundary' => $this->square(150, 350, 10)])->assertSessionHasErrors('boundary');
        $this->assertEqualsWithDelta(2.25, (float) $z->fresh()->area_ha, 0.02);
    }

    // ── locations ────────────────────────────────────────────────────────────────────────────

    public function test_locations_are_placed_and_moved_on_the_map(): void
    {
        $farm = $this->farm('NORTH');
        $this->asCompany($this->company, fn () => $farm->forceFill(['boundary' => $this->square(1000)])->save());

        $this->actingAs($this->admin)->post(route('agri.farms.map.locations.store', $farm->uuid), [
            'name' => 'Borehole 3', 'location_type' => 'borehole', 'latitude' => self::LAT + 0.002, 'longitude' => self::LNG + 0.002,
        ])->assertSessionHasNoErrors()->assertSessionMissing('warning');
        $place = FarmLocation::withoutGlobalScopes()->where('name', 'Borehole 3')->sole();

        $this->actingAs($this->admin)->patch(route('agri.farms.map.locations.move', [$farm->uuid, $place->uuid]), [
            'latitude' => self::LAT - 0.01, 'longitude' => self::LNG,
        ])->assertSessionHasNoErrors()->assertSessionHas('warning', 'The point is outside the farm boundary.');
        $this->assertEqualsWithDelta(self::LAT - 0.01, (float) $place->fresh()->latitude, 1e-7);
    }

    // ── imports ──────────────────────────────────────────────────────────────────────────────

    private function kml(): string
    {
        $ring = fn (array $shape) => implode(' ', array_map(fn ($c) => "{$c[0]},{$c[1]},0", $shape['coordinates'][0]));

        return '<?xml version="1.0" encoding="UTF-8"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document><Folder>'
            .'<Placemark><name>L1</name><description>Old land</description><Polygon><outerBoundaryIs><LinearRing><coordinates>'.$ring($this->square(200)).'</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark>'
            .'<Placemark><name>Nuwe Land</name><ExtendedData><Data name="code"><value>NEW1</value></Data></ExtendedData><Polygon><outerBoundaryIs><LinearRing><coordinates>'.$ring($this->square(300, 500)).'</coordinates></LinearRing></outerBoundaryIs></Polygon></Placemark>'
            .'<Placemark><name>Gate</name><Point><coordinates>'.self::LNG.','.self::LAT.'</coordinates></Point></Placemark>'
            .'</Folder></Document></kml>';
    }

    private function upload(Farm $farm, string $name, string $bytes)
    {
        return $this->actingAs($this->admin)->post(route('agri.farms.map.imports.store', $farm->uuid), [
            'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        ]);
    }

    private function preview(Farm $farm): FieldMapImport
    {
        return FieldMapImport::withoutGlobalScopes()->where('farm_id', $farm->id)->latest('id')->firstOrFail();
    }

    public function test_a_kml_import_is_previewed_matched_by_name_and_applied(): void
    {
        $farm = $this->farm('NORTH');
        $old = $this->field($farm, 'L1', 4);

        $this->upload($farm, 'lande.kml', $this->kml())->assertSessionHasNoErrors()->assertRedirect();
        $import = $this->preview($farm);
        $this->assertSame(['kml', 'previewed'], [$import->format, $import->status]);
        $this->assertNotNull($import->data, 'The file itself is kept, in the database.');
        $this->assertSame($this->kml(), base64_decode($import->data));
        $this->assertStringContainsString('1 non-polygon', $import->crs_note, 'The gate (a point) is left out and said so.');
        [$a, $b] = $import->features;
        $this->assertSame([$old->uuid, 'match'], [$a['match'], $a['suggested']]);
        $this->assertSame(['Nuwe Land', 'NEW1', 'create'], [$b['name'], $b['code'], $b['suggested']]);
        $this->assertEqualsWithDelta(9.0, $b['area_ha'], 0.03);
        $this->assertNull($old->fresh()->boundary, 'A preview changes nothing.');

        $this->actingAs($this->admin)->get(route('agri.farms.map', [$farm->uuid, 'import' => $import->uuid]))
            ->assertInertia(fn ($p) => $p->where('import.id', $import->uuid)->has('import.features', 2));

        $this->actingAs($this->admin)->post(route('agri.farms.map.imports.apply', [$farm->uuid, $import->uuid]), ['rows' => [
            ['index' => 0, 'action' => 'match', 'field_id' => $old->uuid],
            ['index' => 1, 'action' => 'create', 'code' => 'NEW1', 'name' => 'Nuwe Land'],
        ]])->assertSessionHasNoErrors()->assertRedirect(route('agri.farms.map', $farm->uuid));

        $this->assertEqualsWithDelta(4.0, (float) $old->fresh()->mapped_area_ha, 0.02);
        $new = $this->fieldRow($farm, 'NEW1');
        $this->assertSame('Nuwe Land', $new->name);
        $this->assertEqualsWithDelta(9.0, (float) $new->mapped_area_ha, 0.03);
        $this->assertSame(['imported', 'lande.kml'], [FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $new->id)->value('source'),
            FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $new->id)->value('source_name')]);
        $this->assertSame('applied', $import->fresh()->status);

        // Applied once only.
        $this->actingAs($this->admin)->post(route('agri.farms.map.imports.apply', [$farm->uuid, $import->uuid]), ['rows' => [['index' => 0, 'action' => 'skip']]])
            ->assertSessionHasErrors('import');
    }

    public function test_a_kmz_is_a_zipped_kml(): void
    {
        $farm = $this->farm('NORTH');
        $this->field($farm, 'L1', 4);

        $this->upload($farm, 'lande.kmz', $this->zip(['doc.kml' => $this->kml(), 'files/logo.png' => 'not an image']))->assertSessionHasNoErrors();

        $import = $this->preview($farm);
        $this->assertSame('kmz', $import->format);
        $this->assertCount(2, $import->features);
        $this->assertSame('L1', $import->features[0]['match_code']);
    }

    public function test_geojson_imports_including_a_utm_coordinate_system(): void
    {
        $farm = $this->farm('NORTH');
        $this->field($farm, 'L1', 4);
        $utm = Projection::fromEpsg(32735);
        $projected = ['type' => 'Polygon', 'coordinates' => [array_map(fn ($c) => $utm->fromLngLat($c[0], $c[1]), $this->square(200)['coordinates'][0])]];
        $doc = ['type' => 'FeatureCollection', 'crs' => ['type' => 'name', 'properties' => ['name' => 'urn:ogc:def:crs:EPSG::32735']],
            'features' => [['type' => 'Feature', 'properties' => ['CODE' => 'L1', 'crop' => 'Maize'], 'geometry' => $projected]]];

        $this->upload($farm, 'lande.geojson', json_encode($doc))->assertSessionHasNoErrors();

        $f = $this->preview($farm)->features[0];
        $this->assertSame(['L1', 'match', 'Maize'], [$f['match_code'], $f['suggested'], $f['properties']['crop']]);
        $this->assertEqualsWithDelta(4.0, $f['area_ha'], 0.02);
        $this->assertEqualsWithDelta(self::LNG, $f['geometry']['coordinates'][0][0][0], 1e-6, 'UTM is brought back to longitude/latitude.');
        $this->assertStringContainsString('UTM zone 35S', $this->preview($farm)->crs_note);
    }

    public function test_a_zipped_shapefile_is_read_with_its_attributes_and_projection(): void
    {
        $farm = $this->farm('NORTH');
        $l1 = $this->field($farm, 'L1', 4);

        // Plain WGS84 degrees.
        $this->upload($farm, 'lande.zip', $this->shapefile([
            ['L1', 'Ou land', $this->square(200)['coordinates'][0]],
            ['K9', 'Kamp nege', $this->square(100, 400)['coordinates'][0]],
        ], 'GEOGCS["GCS_WGS_1984",DATUM["D_WGS_1984",SPHEROID["WGS_1984",6378137.0,298.257223563]],PRIMEM["Greenwich",0.0],UNIT["Degree",0.0174532925199433]]'))
            ->assertSessionHasNoErrors();
        $import = $this->preview($farm);
        $this->assertSame('shapefile', $import->format);
        [$a, $b] = $import->features;
        $this->assertSame(['L1', 'Ou land', $l1->uuid], [$a['code'], $a['name'], $a['match']]);
        $this->assertSame(['K9', 'Kamp nege', 'create'], [$b['code'], $b['name'], $b['suggested']]);
        $this->assertEqualsWithDelta(1.0, $b['area_ha'], 0.01);

        // The national Lo29 grid, written as westing/southing: reprojected.
        $lo = Projection::fromEpsg(2053);   // Hartebeesthoek94 / Lo29
        $ring = array_map(fn ($c) => $lo->fromLngLat($c[0], $c[1]), $this->square(200)['coordinates'][0]);
        $this->assertGreaterThan(0, $ring[0][1], 'Southings are positive.');
        $this->upload($farm, 'lo29.zip', $this->shapefile([['L1', 'Ou land', $ring]],
            'PROJCS["Hartebeesthoek94_Lo29",GEOGCS["GCS_Hartebeesthoek_1994",DATUM["D_Hartebeesthoek_1994",SPHEROID["WGS_1984",6378137.0,298.257223563]],PRIMEM["Greenwich",0.0],UNIT["Degree",0.0174532925199433]],PROJECTION["Transverse_Mercator"],PARAMETER["False_Easting",0.0],PARAMETER["False_Northing",0.0],PARAMETER["Central_Meridian",29.0],PARAMETER["Scale_Factor",1.0],PARAMETER["Latitude_Of_Origin",0.0],UNIT["Meter",1.0]]'))
            ->assertSessionHasNoErrors();
        $f = $this->preview($farm)->features[0];
        $this->assertEqualsWithDelta(4.0, $f['area_ha'], 0.02);
        $this->assertEqualsWithDelta(self::LAT, $f['geometry']['coordinates'][0][0][1], 1e-6);
        $this->assertStringContainsString('westing/southing', $this->preview($farm)->crs_note);

        $this->actingAs($this->admin)->post(route('agri.farms.map.imports.apply', [$farm->uuid, $this->preview($farm)->uuid]), ['rows' => [
            ['index' => 0, 'action' => 'match', 'field_id' => $l1->uuid]]])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(4.0, (float) $l1->fresh()->mapped_area_ha, 0.02);
    }

    public function test_files_that_cannot_be_read_are_refused_with_a_reason(): void
    {
        $farm = $this->farm('NORTH');
        $square = $this->square(200)['coordinates'][0];

        $this->upload($farm, 'cape.zip', $this->shapefile([['L1', 'x', $square]],
            'PROJCS["Cape_Lo29",GEOGCS["GCS_Cape",DATUM["D_Cape",SPHEROID["Clarke_1880_Arc",6378249.145,293.466307656]],PRIMEM["Greenwich",0.0],UNIT["Degree",0.0174532925199433]],PROJECTION["Transverse_Mercator"],PARAMETER["Central_Meridian",29.0],UNIT["Meter",1.0]]'))
            ->assertSessionHasErrors('file');
        $this->assertStringContainsString('Cape datum', session('errors')->first('file'));

        $utm = Projection::fromEpsg(32735);
        $this->upload($farm, 'noprj.zip', $this->shapefile([['L1', 'x', array_map(fn ($c) => $utm->fromLngLat($c[0], $c[1]), $square)]], null))
            ->assertSessionHasErrors('file');
        $this->assertStringContainsString('.prj', session('errors')->first('file'));

        $this->upload($farm, 'lande.shp', 'not really')->assertSessionHasErrors('file');
        $this->assertStringContainsString('zip the .shp', session('errors')->first('file'));

        $this->upload($farm, 'lande.kml', '<?xml version="1.0"?><!DOCTYPE kml [<!ENTITY x SYSTEM "file:///etc/passwd">]><kml><Placemark><name>&x;</name></Placemark></kml>')
            ->assertSessionHasErrors('file');

        $this->upload($farm, 'lande.geojson', '{"type":"FeatureCollection","features":[]}')->assertSessionHasErrors('file');
        $this->assertSame(0, FieldMapImport::withoutGlobalScopes()->count());
    }

    public function test_a_bad_shape_in_an_import_must_be_left_out(): void
    {
        $farm = $this->farm('NORTH');
        $sq = $this->square(200)['coordinates'][0];
        $doc = ['type' => 'Feature', 'properties' => ['name' => 'Twisted'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[$sq[0], $sq[2], $sq[1], $sq[3], $sq[0]]]]];
        $this->upload($farm, 'twisted.json', json_encode($doc))->assertSessionHasNoErrors();
        $import = $this->preview($farm);
        $this->assertSame('skip', $import->features[0]['suggested']);
        $this->assertNotEmpty($import->features[0]['problems']);

        $this->actingAs($this->admin)->post(route('agri.farms.map.imports.apply', [$farm->uuid, $import->uuid]), ['rows' => [
            ['index' => 0, 'action' => 'create', 'code' => 'TW1']]])->assertSessionHasErrors('rows');
        $this->assertSame(0, FarmField::withoutGlobalScopes()->count());
    }

    // ── export ───────────────────────────────────────────────────────────────────────────────

    public function test_geojson_and_kml_exports_read_back_to_the_same_fields(): void
    {
        $farm = $this->farm('NORTH');
        $this->drawField($farm, 'L1', $this->square(200));
        $this->drawField($farm, 'L2', $this->square(300, 400));
        $l1 = $this->fieldRow($farm, 'L1');
        $this->actingAs($this->admin)->post(route('agri.farms.map.zones.store', [$farm->uuid, $l1->uuid]),
            ['name' => 'Wet corner', 'zone_class' => 'low', 'boundary' => $this->square(50, 10, 10)])->assertSessionHasNoErrors();
        $this->asCompany($this->company, fn () => FarmLocation::create(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'name' => 'Gate',
            'location_type' => 'other', 'latitude' => self::LAT, 'longitude' => self::LNG, 'status' => 'active']));

        $geojson = $this->actingAs($this->admin)->get(route('agri.farms.map.export', [$farm->uuid, 'geojson']))->assertOk()
            ->assertHeader('Content-Type', 'application/geo+json')->getContent();
        $doc = json_decode($geojson, true);
        $this->assertSame(['field', 'field', 'zone', 'location'], array_column(array_column($doc['features'], 'properties'), 'kind'));
        $this->assertSame('L1', $doc['features'][0]['properties']['code']);

        $kml = $this->actingAs($this->admin)->get(route('agri.farms.map.export', [$farm->uuid, 'kml']))->assertOk()->getContent();
        $this->assertStringContainsString('<kml xmlns="http://www.opengis.net/kml/2.2">', $kml);

        foreach (['map.geojson' => $geojson, 'map.kml' => $kml] as $name => $bytes) {
            $read = app(BoundaryFiles::class)->read($bytes, $name);
            $byCode = collect($read['features'])->where('kind', 'field')->keyBy('code');
            $this->assertEqualsCanonicalizing(['L1', 'L2'], $byCode->keys()->all(), $name);
            $this->assertEqualsWithDelta((float) $l1->mapped_area_ha, Geo::hectares($byCode['L1']['geometry']), 0.0005, $name);
            $this->assertEquals(Geo::polygon($l1->boundary), $byCode['L1']['geometry'], "{$name}: the shape round-trips");
            $this->assertSame('Wet corner', collect($read['features'])->firstWhere('kind', 'zone')['name'], $name);
        }

        // Read back into the farm, every field is matched by its code and the zone is left out.
        $this->upload($farm, 'map.geojson', $geojson)->assertSessionHasNoErrors();
        $this->assertSame(['match', 'match', 'skip'], array_column($this->preview($farm)->features, 'suggested'));
    }

    // ── who may do what ──────────────────────────────────────────────────────────────────────

    public function test_viewers_read_the_map_and_cannot_change_it(): void
    {
        $farm = $this->farm('NORTH');
        $other = $this->farm('SOUTH');
        $this->drawField($farm, 'L1', $this->square(200));
        $field = $this->fieldRow($farm, 'L1');
        $boundaryUrl = route('agri.farms.map.fields.boundary', [$farm->uuid, $field->uuid]);

        // A manager whose membership is read-only.
        $this->member($this->manager, $farm, 'viewer');
        $this->actingAs($this->manager)->get(route('agri.farms.map', $farm->uuid))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Agri/Farms/Map')->where('can', ['edit' => false, 'edit_farm' => false, 'zones' => false]));
        $this->actingAs($this->manager)->put($boundaryUrl, ['boundary' => $this->square(300)])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.farms.map.zones.store', [$farm->uuid, $field->uuid]),
            ['name' => 'Z', 'zone_class' => 'high', 'boundary' => $this->square(50, 10, 10)])->assertForbidden();
        $this->actingAs($this->manager)->post(route('agri.farms.map.imports.store', $farm->uuid),
            ['file' => UploadedFile::fake()->createWithContent('x.kml', $this->kml())])->assertForbidden();
        $this->actingAs($this->manager)->get(route('agri.farms.map.export', [$farm->uuid, 'geojson']))->assertOk();

        // A farm the person was not given is not found at all.
        $this->actingAs($this->manager)->get(route('agri.farms.map', $other->uuid))->assertNotFound();
        $this->actingAs($this->manager)->get(route('agri.farms.map.export', [$other->uuid, 'kml']))->assertNotFound();

        // A worker sees the map but may not manage fields; somebody with no farm role sees nothing.
        $this->member($this->worker, $farm, 'worker');
        $this->actingAs($this->worker)->get(route('agri.farms.map', $farm->uuid))->assertOk()->assertInertia(fn ($p) => $p->where('can.edit', false));
        $this->actingAs($this->worker)->put($boundaryUrl, ['boundary' => $this->square(300)])->assertForbidden();
        $this->assertContains($this->actingAs($this->outsider)->get(route('agri.farms.map', $farm->uuid))->status(), [403, 404]);

        $this->assertSame(1, FieldBoundaryVersion::withoutGlobalScopes()->where('field_id', $field->id)->count(), 'Nothing they tried changed the map.');
    }

    public function test_an_archived_farm_map_is_read_only(): void
    {
        $farm = $this->farm('OLD');
        $field = $this->field($farm, 'L1', 4);
        $this->asCompany($this->company, fn () => $farm->forceFill(['status' => Farm::ARCHIVED])->save());

        $this->actingAs($this->admin)->get(route('agri.farms.map', $farm->uuid))->assertInertia(fn ($p) => $p->where('can.edit', false));
        $this->actingAs($this->admin)->put(route('agri.farms.map.fields.boundary', [$farm->uuid, $field->uuid]), ['boundary' => $this->square(200)])
            ->assertSessionHasErrors('farm');
    }

    public function test_the_hub_card_opens_the_first_farm_map_or_says_there_is_none(): void
    {
        $this->actingAs($this->admin)->get(route('agri.field-map'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Modules/Agri/Farms/Map')->where('map', null));

        $farm = $this->farm('NORTH');
        $this->actingAs($this->admin)->get(route('agri.field-map'))->assertRedirect(route('agri.farms.map', $farm->uuid));

        $this->actingAs($this->admin)->get(route('agri.farms.show', $farm->uuid))->assertOk()->assertInertia(fn ($p) => $p->component('Modules/Agri/Farms/Show'));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agri-test-');
        $zip = new \ZipArchive;
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $out = file_get_contents($tmp);
        unlink($tmp);

        return $out;
    }

    /**
     * A small ESRI shapefile (.shp, .shx-less, .dbf with CODE and NAME, optional .prj), zipped.
     *
     * @param list<array{0: string, 1: string, 2: list<array{0: float, 1: float}>}> $records
     */
    private function shapefile(array $records, ?string $prj): string
    {
        $all = array_merge(...array_map(fn ($r) => $r[2], $records));
        $box = fn (array $pts) => pack('e4', min(array_column($pts, 0)), min(array_column($pts, 1)), max(array_column($pts, 0)), max(array_column($pts, 1)));
        $body = '';
        foreach ($records as $i => [, , $ring]) {
            // Outer rings are clockwise in a shapefile: a GeoJSON square is anticlockwise, so reverse it.
            $ring = array_reverse($ring);
            $content = pack('V', 5).$box($ring).pack('V', 1).pack('V', count($ring)).pack('V', 0);
            foreach ($ring as $c) {
                $content .= pack('e2', $c[0], $c[1]);
            }
            $body .= pack('N', $i + 1).pack('N', strlen($content) / 2).$content;
        }
        $shp = pack('N', 9994).str_repeat("\0", 20).pack('N', (100 + strlen($body)) / 2).pack('V', 1000).pack('V', 5).$box($all).pack('e4', 0, 0, 0, 0).$body;

        $fields = [['CODE', 10], ['NAME', 40]];
        $dbf = pack('C', 3).pack('C3', 126, 10, 22).pack('V', count($records)).pack('v', 32 + 32 * count($fields) + 1)
            .pack('v', 1 + array_sum(array_column($fields, 1))).str_repeat("\0", 20);
        foreach ($fields as [$name, $len]) {
            $dbf .= str_pad($name, 11, "\0").'C'.str_repeat("\0", 4).pack('C', $len).pack('C', 0).str_repeat("\0", 14);
        }
        $dbf .= "\r";
        foreach ($records as [$code, $name]) {
            $dbf .= ' '.str_pad($code, 10).str_pad($name, 40);
        }
        $dbf .= "\x1A";

        return $this->zip(array_filter(['lande.shp' => $shp, 'lande.dbf' => $dbf, 'lande.prj' => $prj]));
    }
}
