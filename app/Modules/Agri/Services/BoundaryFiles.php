<?php

namespace App\Modules\Agri\Services;

use App\Modules\Agri\Support\Geo;
use App\Modules\Agri\Support\Projection;
use Illuminate\Validation\ValidationException;

/**
 * Reading field boundaries out of the files people have — GeoJSON, KML, KMZ (a zipped KML) and
 * ESRI shapefiles (a .zip holding .shp, .dbf and .prj) — and writing a farm's map back out as
 * GeoJSON or KML.
 *
 * Parsing is done here, in PHP, with no GIS library: a shapefile's polygons and its .dbf
 * attributes are simple binary records. Whatever the file's coordinate system, what comes out is
 * WGS84 longitude/latitude or a clear refusal (see Projection).
 */
class BoundaryFiles
{
    public const FORMATS = ['geojson', 'kml', 'kmz', 'shapefile'];

    /** Largest file accepted, and the most a zip may expand to. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public const MAX_UNZIPPED = 60 * 1024 * 1024;

    public const MAX_FEATURES = 1000;

    /** Attribute names that usually hold a field's name or code. */
    private const NAME_KEYS = ['name', 'field_name', 'fieldname', 'field', 'naam', 'title', 'label', 'block', 'blok'];

    private const CODE_KEYS = ['code', 'field_code', 'fieldcode', 'field_id', 'fieldid', 'kode', 'block_code', 'ref', 'id'];

    /** The format a file name says it is, or null. */
    public static function formatOf(string $fileName): ?string
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'geojson', 'json' => 'geojson',
            'kml' => 'kml',
            'kmz' => 'kmz',
            'zip' => 'shapefile',
            default => null,
        };
    }

    /**
     * Every polygon feature in a file.
     *
     * @return array{format: string, crs: string, features: list<array{name: ?string, code: ?string, kind: ?string, properties: array, geometry: array}>, skipped: int}
     */
    public function read(string $bytes, string $fileName): array
    {
        $format = self::formatOf($fileName);
        if ($format === null) {
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            throw $this->refuse(in_array($ext, ['shp', 'dbf', 'prj', 'shx'], true)
                ? 'A shapefile is several files: zip the .shp, .dbf and .prj together and upload the .zip.'
                : 'Upload a GeoJSON, KML, KMZ or zipped shapefile (.zip).');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw $this->refuse('The file is larger than '.(self::MAX_BYTES / 1024 / 1024).' MB.');
        }

        $out = match ($format) {
            'geojson' => $this->geojson($bytes),
            'kml' => $this->kml($bytes),
            'kmz' => $this->kml($this->kmzToKml($bytes)),
            'shapefile' => $this->shapefileZip($bytes),
        };
        if (! $out['features']) {
            throw $this->refuse('No field boundaries (polygons) were found in the file.'.($out['skipped'] ? " {$out['skipped']} other shape(s) were left out." : ''));
        }
        if (count($out['features']) > self::MAX_FEATURES) {
            throw $this->refuse('The file holds more than '.self::MAX_FEATURES.' shapes; split it into smaller files.');
        }
        foreach ($out['features'] as $i => $f) {
            foreach (Geo::polygons($f['geometry']) as $rings) {
                foreach ($rings as $ring) {
                    foreach ($ring as $c) {
                        if (abs($c[0]) > 180 || abs($c[1]) > 90) {
                            throw $this->refuse('The coordinates are not longitude/latitude, and the file does not say which coordinate system they are in. Re-export it in WGS84 longitude/latitude, or include the .prj.');
                        }
                    }
                }
            }
            $out['features'][$i]['geometry'] = Geo::tidy(Geo::close($f['geometry']));
        }

        return ['format' => $format] + $out;
    }

    // ── GeoJSON ──────────────────────────────────────────────────────────────────────────────

    private function geojson(string $bytes): array
    {
        $doc = json_decode($this->stripBom($bytes), true);
        if (! is_array($doc)) {
            throw $this->refuse('The file is not valid GeoJSON.');
        }
        $projection = Projection::wgs84();
        $name = $doc['crs']['properties']['name'] ?? null;
        if (is_string($name) && ! preg_match('/CRS84$/i', $name) && preg_match('/EPSG:{1,2}(\d+)$/i', $name, $m)) {
            $projection = Projection::fromEpsg((int) $m[1]);
            if (is_string($projection)) {
                throw $this->refuse($projection);
            }
        }
        $items = match ($doc['type'] ?? null) {
            'FeatureCollection' => $doc['features'] ?? [],
            'Feature' => [$doc],
            'Polygon', 'MultiPolygon', 'GeometryCollection', 'Point' => [['type' => 'Feature', 'properties' => [], 'geometry' => $doc]],
            default => throw $this->refuse('The file is not GeoJSON: it has no features or geometry.'),
        };

        $features = [];
        $skipped = 0;
        foreach ($items as $item) {
            $geom = $item['geometry'] ?? null;
            $parts = ($geom['type'] ?? null) === 'GeometryCollection' ? ($geom['geometries'] ?? []) : [$geom];
            $polys = [];
            foreach ($parts as $g) {
                if (in_array($g['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                    array_push($polys, ...Geo::polygons(Geo::polygon($g)));
                }
            }
            if (! $polys) {
                $skipped++;

                continue;
            }
            $polys = $this->reproject($polys, $projection);
            $features[] = $this->feature((array) ($item['properties'] ?? []), $polys, $item['properties']['name'] ?? null);
        }

        return ['crs' => $projection->label, 'features' => $features, 'skipped' => $skipped];
    }

    // ── KML / KMZ ────────────────────────────────────────────────────────────────────────────

    private function kmzToKml(string $bytes): string
    {
        return $this->withZip($bytes, function (\ZipArchive $zip) {
            $pick = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($n), '.kml') && ($pick === null || strtolower(basename($n)) === 'doc.kml')) {
                    $pick = $n;
                }
            }
            if ($pick === null) {
                throw $this->refuse('The KMZ holds no KML file.');
            }

            return $this->entry($zip, $pick);
        });
    }

    private function kml(string $bytes): array
    {
        $dom = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // No network, no entity expansion: a KML is data, never instructions to fetch more.
        $ok = $dom->loadXML($this->stripBom($bytes), LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok || $dom->doctype !== null) {
            throw $this->refuse('The file is not valid KML.');
        }
        $xp = new \DOMXPath($dom);
        $features = [];
        $skipped = 0;
        foreach ($xp->query("//*[local-name()='Placemark']") as $pm) {
            $polys = [];
            foreach ($xp->query(".//*[local-name()='Polygon']", $pm) as $poly) {
                $rings = [];
                foreach ($xp->query("./*[local-name()='outerBoundaryIs']//*[local-name()='coordinates']", $poly) as $c) {
                    $rings[] = $this->kmlCoordinates($c->textContent);
                }
                foreach ($xp->query("./*[local-name()='innerBoundaryIs']//*[local-name()='coordinates']", $poly) as $c) {
                    $rings[] = $this->kmlCoordinates($c->textContent);
                }
                if ($rings && count($rings[0]) >= 3) {
                    $polys[] = $rings;
                }
            }
            if (! $polys) {
                $skipped++;

                continue;
            }
            $props = [];
            foreach ($xp->query(".//*[local-name()='ExtendedData']//*[local-name()='Data']", $pm) as $d) {
                $v = $xp->query("./*[local-name()='value']", $d)->item(0);
                $props[$d->getAttribute('name')] = trim($v ? $v->textContent : $d->textContent);
            }
            foreach ($xp->query(".//*[local-name()='ExtendedData']//*[local-name()='SimpleData']", $pm) as $d) {
                $props[$d->getAttribute('name')] = trim($d->textContent);
            }
            $name = $xp->query("./*[local-name()='name']", $pm)->item(0)?->textContent;
            $desc = $xp->query("./*[local-name()='description']", $pm)->item(0)?->textContent;
            if ($desc !== null && trim(strip_tags($desc)) !== '' && ! isset($props['description'])) {
                $props['description'] = mb_substr(trim(strip_tags($desc)), 0, 500);
            }
            $features[] = $this->feature($props, $polys, $name !== null ? trim($name) : null);
        }

        return ['crs' => 'KML (WGS84 longitude/latitude)', 'features' => $features, 'skipped' => $skipped];
    }

    /** "lng,lat[,alt] lng,lat[,alt] …" → [[lng, lat], …] */
    private function kmlCoordinates(string $text): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($text)) as $tuple) {
            $p = explode(',', $tuple);
            if (count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
                $out[] = [(float) $p[0], (float) $p[1]];
            }
        }

        return $out;
    }

    // ── ESRI shapefile ───────────────────────────────────────────────────────────────────────

    private function shapefileZip(string $bytes): array
    {
        return $this->withZip($bytes, function (\ZipArchive $zip) {
            $layers = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (str_contains($n, '__MACOSX/')) {
                    continue;
                }
                $ext = strtolower(pathinfo($n, PATHINFO_EXTENSION));
                $base = substr($n, 0, -strlen($ext) - 1);
                $layers[strtolower($base)][$ext] = $n;
            }
            $hasKml = (bool) array_filter($layers, fn ($l) => isset($l['kml']));
            $layers = array_filter($layers, fn ($l) => isset($l['shp']));
            if (! $layers) {
                throw $this->refuse($hasKml ? 'The zip holds KML, not a shapefile; upload the .kml itself.' : 'The zip holds no shapefile (.shp).');
            }

            $features = [];
            $skipped = 0;
            $crs = [];
            foreach ($layers as $layer) {
                $prj = isset($layer['prj']) ? $this->entry($zip, $layer['prj']) : '';
                $projection = Projection::fromWkt($prj);
                if (is_string($projection)) {
                    throw $this->refuse($projection);
                }
                $shapes = $this->readShp($this->entry($zip, $layer['shp']));
                $encoding = isset($layer['cpg']) ? trim($this->entry($zip, $layer['cpg'])) : null;
                $rows = isset($layer['dbf']) ? $this->readDbf($this->entry($zip, $layer['dbf']), $encoding) : [];
                if (! isset($layer['prj'])) {
                    $sample = $shapes[0]['rings'][0][0] ?? null;
                    if ($sample && (abs($sample[0]) > 180 || abs($sample[1]) > 90)) {
                        throw $this->refuse('The shapefile has no .prj, so its coordinate system is unknown. Zip the .prj with the .shp and .dbf, or re-export it in WGS84 longitude/latitude.');
                    }
                }
                $projection->settle(array_merge(...array_map(fn ($s) => $s['rings'][0] ?? [], $shapes ?: [['rings' => [[]]]])));
                $crs[] = $projection->label.($projection->southOrientated() ? ', westing/southing' : '');
                foreach ($shapes as $idx => $shape) {
                    if ($shape['type'] !== 'polygon') {
                        $skipped++;

                        continue;
                    }
                    $polys = $this->reproject($this->assembleRings($shape['rings']), $projection);
                    $features[] = $this->feature($rows[$idx] ?? [], $polys, null);
                }
            }

            return ['crs' => implode('; ', array_unique($crs)), 'features' => $features, 'skipped' => $skipped];
        });
    }

    /**
     * The shapes of a .shp: polygons (types 5, 15, 25) with their rings; anything else is noted
     * so it can be counted as left out.
     *
     * @return list<array{type: string, rings: list<list<array{0: float, 1: float}>>}>
     */
    private function readShp(string $b): array
    {
        if (strlen($b) < 100 || unpack('N', substr($b, 0, 4))[1] !== 9994) {
            throw $this->refuse('The .shp file is not a shapefile.');
        }
        $len = strlen($b);
        $pos = 100;
        $out = [];
        while ($pos + 8 <= $len) {
            $contentBytes = unpack('N', substr($b, $pos + 4, 4))[1] * 2;
            $rec = substr($b, $pos + 8, $contentBytes);
            $pos += 8 + $contentBytes;
            if (strlen($rec) < 4) {
                break;
            }
            $type = unpack('V', substr($rec, 0, 4))[1];
            if (! in_array($type, [5, 15, 25], true)) {
                $out[] = ['type' => $type === 0 ? 'null' : 'other', 'rings' => []];

                continue;
            }
            if (strlen($rec) < 44) {
                throw $this->refuse('The .shp file is damaged.');
            }
            ['p' => $numParts, 'n' => $numPoints] = unpack('Vp/Vn', substr($rec, 36, 8));
            if ($numParts < 1 || $numPoints < 1 || 44 + 4 * $numParts + 16 * $numPoints > strlen($rec)) {
                throw $this->refuse('The .shp file is damaged.');
            }
            $parts = array_values(unpack('V'.$numParts, substr($rec, 44, 4 * $numParts)));
            $coords = array_values(unpack('e'.(2 * $numPoints), substr($rec, 44 + 4 * $numParts, 16 * $numPoints)));
            $rings = [];
            for ($p = 0; $p < $numParts; $p++) {
                $from = $parts[$p];
                $to = $p + 1 < $numParts ? $parts[$p + 1] : $numPoints;
                $ring = [];
                for ($i = $from; $i < $to; $i++) {
                    $ring[] = [$coords[2 * $i], $coords[2 * $i + 1]];
                }
                $rings[] = $ring;
            }
            $out[] = ['type' => 'polygon', 'rings' => $rings];
        }

        return $out;
    }

    /**
     * The attribute rows of a .dbf (dBASE III), in record order; deleted records stay as empty rows
     * so the numbering matches the .shp.
     *
     * @return list<array<string, string|float|null>>
     */
    private function readDbf(string $b, ?string $encoding): array
    {
        if (strlen($b) < 33) {
            return [];
        }
        ['n' => $count, 'h' => $headerLen, 'r' => $recLen] = unpack('Vn/vh/vr', substr($b, 4, 8));
        $fields = [];
        for ($o = 32; $o + 32 <= $headerLen && $b[$o] !== "\r"; $o += 32) {
            $fields[] = ['name' => rtrim(substr($b, $o, 11), "\0 "), 'type' => $b[$o + 11], 'len' => ord($b[$o + 16])];
        }
        $utf8 = $encoding !== null && preg_match('/utf-?8|65001/i', $encoding);
        $rows = [];
        for ($r = 0; $r < $count; $r++) {
            $at = $headerLen + $r * $recLen;
            if ($at + $recLen > strlen($b)) {
                break;
            }
            if ($b[$at] === '*') {
                $rows[] = [];

                continue;
            }
            $row = [];
            $o = $at + 1;
            foreach ($fields as $f) {
                $raw = trim(substr($b, $o, $f['len']));
                $o += $f['len'];
                if (! $utf8 && ! mb_check_encoding($raw, 'UTF-8')) {
                    $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
                }
                $row[$f['name']] = match ($f['type']) {
                    'N', 'F' => $raw === '' ? null : (is_numeric($raw) ? (float) $raw : $raw),
                    default => $raw === '' ? null : $raw,
                };
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Shapefile rings into polygons: a clockwise ring is an outline, an anticlockwise one a hole in
     * the outline that contains it.
     */
    private function assembleRings(array $rings): array
    {
        $polys = [];
        $holes = [];
        foreach ($rings as $ring) {
            if (count($ring) < 3) {
                continue;
            }
            $sum = 0.0;
            for ($i = 0; $i < count($ring) - 1; $i++) {
                $sum += ($ring[$i + 1][0] - $ring[$i][0]) * ($ring[$i + 1][1] + $ring[$i][1]);
            }
            $sum > 0 ? $polys[] = [$ring] : $holes[] = $ring;   // positive: clockwise
        }
        if (! $polys) {
            // Written the other way round by some tools: treat each ring as its own outline.
            return array_map(fn ($r) => [$r], $holes);
        }
        foreach ($holes as $h) {
            $home = 0;
            foreach ($polys as $k => $p) {
                if (Geo::contains(['type' => 'Polygon', 'coordinates' => [$p[0]]], $h[0])) {
                    $home = $k;

                    break;
                }
            }
            $polys[$home][] = $h;
        }

        return $polys;
    }

    // ── shared ───────────────────────────────────────────────────────────────────────────────

    /** @param list<list<list<array{0: float, 1: float}>>> $polys */
    private function reproject(array $polys, Projection $projection): array
    {
        if ($projection->isGeographic()) {
            return $polys;
        }

        return array_map(fn ($rings) => array_map(fn ($ring) => array_map(fn ($c) => $projection->toLngLat((float) $c[0], (float) $c[1]), $ring), $rings), $polys);
    }

    private function feature(array $props, array $polys, ?string $name): array
    {
        $props = array_map(fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v), $props);
        $lower = array_change_key_case($props, CASE_LOWER);
        $pick = function (array $keys) use ($lower) {
            foreach ($keys as $k) {
                if (isset($lower[$k]) && trim((string) $lower[$k]) !== '') {
                    return mb_substr(trim((string) $lower[$k]), 0, 150);
                }
            }

            return null;
        };
        $geometry = count($polys) === 1 ? ['type' => 'Polygon', 'coordinates' => $polys[0]] : ['type' => 'MultiPolygon', 'coordinates' => $polys];

        return [
            'name' => $name !== null && $name !== '' ? mb_substr($name, 0, 150) : $pick(self::NAME_KEYS),
            'code' => $pick(self::CODE_KEYS),
            'kind' => is_string($lower['kind'] ?? null) ? $lower['kind'] : null,
            'properties' => array_slice($props, 0, 30, true),
            'geometry' => $geometry,
        ];
    }

    private function withZip(string $bytes, callable $fn): mixed
    {
        // ZipArchive reads from a path; the upload is unpacked from a scratch copy that is removed at once.
        $tmp = tempnam(sys_get_temp_dir(), 'agri-map-');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive;
        try {
            if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) {
                throw $this->refuse('The file is not a valid zip archive.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            if ($total > self::MAX_UNZIPPED) {
                throw $this->refuse('The archive unpacks to more than '.(self::MAX_UNZIPPED / 1024 / 1024).' MB.');
            }

            return $fn($zip);
        } finally {
            @$zip->close();
            @unlink($tmp);
        }
    }

    private function entry(\ZipArchive $zip, string $name): string
    {
        $data = $zip->getFromName($name);
        if ($data === false) {
            throw $this->refuse("{$name} could not be read from the archive.");
        }

        return $data;
    }

    private function stripBom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }

    // ── export ───────────────────────────────────────────────────────────────────────────────

    /**
     * A farm's map as a GeoJSON FeatureCollection. Every feature says what it is (kind: farm,
     * field, zone or location), so the file reads back into this import cleanly.
     *
     * @param list<array{kind: string, properties: array, geometry: array}> $items
     */
    public function toGeoJson(array $items, string $name): string
    {
        return json_encode([
            'type' => 'FeatureCollection',
            'name' => $name,
            'features' => array_map(fn ($i) => ['type' => 'Feature', 'properties' => ['kind' => $i['kind']] + $i['properties'], 'geometry' => $i['geometry']], $items),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** The same map as KML, a folder per kind, attributes in ExtendedData. */
    public function toKml(array $items, string $name): string
    {
        $x = new \XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('kml');
        $x->writeAttribute('xmlns', 'http://www.opengis.net/kml/2.2');
        $x->startElement('Document');
        $x->writeElement('name', $name);
        foreach (['farm' => ['Farm boundary', 'ff00a5ff'], 'field' => ['Fields', 'ff2fb07c'], 'zone' => ['Management zones', 'ffd18b3f'], 'location' => ['Locations', 'ff0000ff']] as $kind => [$title, $colour]) {
            $x->startElement('Style');
            $x->writeAttribute('id', $kind);
            $x->startElement('LineStyle');
            $x->writeElement('color', $colour);
            $x->writeElement('width', '2');
            $x->endElement();
            $x->startElement('PolyStyle');
            $x->writeElement('color', '40'.substr($colour, 2));
            $x->endElement();
            $x->endElement();
        }
        foreach (['farm' => 'Farm boundary', 'field' => 'Fields', 'zone' => 'Management zones', 'location' => 'Locations'] as $kind => $title) {
            $group = array_filter($items, fn ($i) => $i['kind'] === $kind);
            if (! $group) {
                continue;
            }
            $x->startElement('Folder');
            $x->writeElement('name', $title);
            foreach ($group as $i) {
                $p = ['kind' => $kind] + $i['properties'];
                $x->startElement('Placemark');
                $x->writeElement('name', (string) ($p['code'] ?? $p['name'] ?? ''));
                if (isset($p['name']) && isset($p['code'])) {
                    $x->writeElement('description', (string) $p['name']);
                }
                $x->writeElement('styleUrl', "#{$kind}");
                $x->startElement('ExtendedData');
                foreach ($p as $k => $v) {
                    if ($v === null || is_array($v)) {
                        continue;
                    }
                    $x->startElement('Data');
                    $x->writeAttribute('name', (string) $k);
                    $x->writeElement('value', is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
                    $x->endElement();
                }
                $x->endElement();
                $this->kmlGeometry($x, $i['geometry']);
                $x->endElement();
            }
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    private function kmlGeometry(\XMLWriter $x, array $g): void
    {
        $coords = fn (array $ring) => implode(' ', array_map(fn ($c) => $c[0].','.$c[1], $ring));
        if ($g['type'] === 'Point') {
            $x->startElement('Point');
            $x->writeElement('coordinates', $g['coordinates'][0].','.$g['coordinates'][1]);
            $x->endElement();

            return;
        }
        $polys = Geo::polygons($g);
        if (count($polys) > 1) {
            $x->startElement('MultiGeometry');
        }
        foreach ($polys as $rings) {
            $x->startElement('Polygon');
            foreach ($rings as $r => $ring) {
                $x->startElement($r === 0 ? 'outerBoundaryIs' : 'innerBoundaryIs');
                $x->startElement('LinearRing');
                $x->writeElement('coordinates', $coords($ring));
                $x->endElement();
                $x->endElement();
            }
            $x->endElement();
        }
        if (count($polys) > 1) {
            $x->endElement();
        }
    }

    // ── writing a shapefile ──────────────────────────────────────────────────────────────────

    /** The .prj every shapefile we write carries: plain WGS84 longitude/latitude. */
    public const WGS84_PRJ = 'GEOGCS["GCS_WGS_1984",DATUM["D_WGS_1984",SPHEROID["WGS_1984",6378137.0,298.257223563]],PRIMEM["Greenwich",0.0],UNIT["Degree",0.0174532925199433]]';

    /**
     * Polygons and their attributes as a zipped ESRI shapefile (.shp, .shx, .dbf, .prj, .cpg) —
     * the format every monitor reads. The reverse of reading one above: rings written clockwise for
     * an outline and anticlockwise for a hole, attributes as dBASE III fields (C text, N numbers).
     *
     * @param list<array{geometry: array, properties: array}> $items
     * @param list<array{name: string, type: string, len: int, dec?: int}> $fields
     */
    public function toShapefileZip(array $items, string $layer, array $fields): string
    {
        $layer = preg_replace('/[^A-Za-z0-9_\-]/', '_', $layer) ?: 'layer';
        $records = [];
        $box = [INF, INF, -INF, -INF];
        foreach ($items as $item) {
            $parts = [];
            $points = [];
            $rbox = [INF, INF, -INF, -INF];
            foreach (Geo::polygons(Geo::close(Geo::polygon($item['geometry']) ?? ['type' => 'Polygon', 'coordinates' => []])) as $rings) {
                foreach ($rings as $i => $ring) {
                    if (count($ring) < 4) {
                        continue;
                    }
                    $clockwise = $this->ringSum($ring) > 0;
                    if (($i === 0) !== $clockwise) {
                        $ring = array_reverse($ring);
                    }
                    $parts[] = count($points);
                    foreach ($ring as $c) {
                        $points[] = [(float) $c[0], (float) $c[1]];
                        $rbox = [min($rbox[0], $c[0]), min($rbox[1], $c[1]), max($rbox[2], $c[0]), max($rbox[3], $c[1])];
                    }
                }
            }
            if (! $points) {
                $records[] = pack('V', 0);   // a null shape keeps the attribute row in step

                continue;
            }
            $box = [min($box[0], $rbox[0]), min($box[1], $rbox[1]), max($box[2], $rbox[2]), max($box[3], $rbox[3])];
            $rec = pack('V', 5).pack('e4', ...$rbox).pack('VV', count($parts), count($points));
            foreach ($parts as $p) {
                $rec .= pack('V', $p);
            }
            foreach ($points as $pt) {
                $rec .= pack('e2', $pt[0], $pt[1]);
            }
            $records[] = $rec;
        }
        if ($box[0] === INF) {
            $box = [0, 0, 0, 0];
        }
        $shp = '';
        $shx = '';
        $offset = 50;   // in 16-bit words, after the 100-byte header
        foreach ($records as $n => $rec) {
            $words = intdiv(strlen($rec), 2);
            $shp .= pack('NN', $n + 1, $words).$rec;
            $shx .= pack('NN', $offset, $words);
            $offset += 4 + $words;
        }
        $header = fn (int $lengthWords) => pack('N', 9994).str_repeat("\0", 20).pack('N', $lengthWords).pack('VV', 1000, 5).pack('e4', ...$box).pack('e4', 0, 0, 0, 0);
        $shpBytes = $header(50 + intdiv(strlen($shp), 2)).$shp;
        $shxBytes = $header(50 + intdiv(strlen($shx), 2)).$shx;

        $files = ["{$layer}.shp" => $shpBytes, "{$layer}.shx" => $shxBytes, "{$layer}.dbf" => $this->dbf($items, $fields),
            "{$layer}.prj" => self::WGS84_PRJ, "{$layer}.cpg" => 'UTF-8'];
        $tmp = tempnam(sys_get_temp_dir(), 'agri-shp-');
        $zip = new \ZipArchive;
        try {
            $zip->open($tmp, \ZipArchive::OVERWRITE);
            foreach ($files as $name => $bytes) {
                $zip->addFromString($name, $bytes);
            }
            $zip->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** A dBASE III table, one row per item, in the item order the .shp uses. */
    private function dbf(array $items, array $fields): string
    {
        $recLen = 1 + array_sum(array_map(fn ($f) => (int) $f['len'], $fields));
        $headerLen = 32 + 32 * count($fields) + 1;
        $out = pack('C4', 3, (int) date('Y') - 1900, (int) date('n'), (int) date('j')).pack('V', count($items)).pack('vv', $headerLen, $recLen).str_repeat("\0", 20);
        foreach ($fields as $f) {
            $out .= str_pad(substr(strtoupper($f['name']), 0, 10), 11, "\0").$f['type'].str_repeat("\0", 4).pack('CC', (int) $f['len'], (int) ($f['dec'] ?? 0)).str_repeat("\0", 14);
        }
        $out .= "\r";
        foreach ($items as $item) {
            $out .= ' ';
            foreach ($fields as $f) {
                $v = $item['properties'][$f['name']] ?? null;
                $len = (int) $f['len'];
                if ($f['type'] === 'N') {
                    $txt = $v === null ? '' : number_format((float) $v, (int) ($f['dec'] ?? 0), '.', '');
                    $out .= str_pad(substr($txt, 0, $len), $len, ' ', STR_PAD_LEFT);
                } else {
                    $txt = (string) ($v ?? '');
                    // Cut on a character, not a byte, so a long UTF-8 name stays readable.
                    while (strlen($txt) > $len) {
                        $txt = mb_substr($txt, 0, mb_strlen($txt) - 1);
                    }
                    $out .= str_pad($txt, $len, ' ');
                }
            }
        }

        return $out."\x1A";
    }

    /** Positive for a clockwise ring (the reader's test, above). */
    private function ringSum(array $ring): float
    {
        $sum = 0.0;
        for ($i = 0; $i < count($ring) - 1; $i++) {
            $sum += ($ring[$i + 1][0] - $ring[$i][0]) * ($ring[$i + 1][1] + $ring[$i][1]);
        }

        return $sum;
    }
}
