<?php

namespace App\Modules\Agri\Support;

/**
 * Reading the coordinate system a boundary file was saved in, and bringing its coordinates to
 * WGS84 longitude/latitude.
 *
 * Covers what South African farm files actually arrive in: plain WGS84 or Hartebeesthoek94
 * degrees, UTM zones (WGS84 / Hartebeesthoek94, which share an ellipsoid, so no datum shift is
 * needed) and the national Lo system (Transverse Mercator on odd meridians, scale 1, in either
 * east/north or the surveyors' westing/southing). The old Cape datum (Clarke 1880) is recognised
 * and refused with a clear message: converting it properly needs a datum shift that a few metres of
 * error in a field boundary would not forgive.
 */
final class Projection
{
    /** @param array{kind: string, lon0?: float, k0?: float, fe?: float, fn?: float, lat0?: float, a?: float, f?: float, unit?: float, south?: bool|null} $p */
    private function __construct(private array $p, public readonly string $label) {}

    public static function wgs84(string $label = 'WGS84 longitude/latitude'): self
    {
        return new self(['kind' => 'geographic'], $label);
    }

    /** A projection from an ESRI .prj / OGC WKT, or a sentence saying why it cannot be read. */
    public static function fromWkt(string $wkt): self|string
    {
        $wkt = trim($wkt);
        if ($wkt === '') {
            return self::wgs84('No coordinate system given; read as WGS84 longitude/latitude');
        }
        $upper = strtoupper($wkt);
        $datum = preg_match('/DATUM\s*\[\s*"([^"]+)"/i', $wkt, $m) ? $m[1] : '';
        $spheroid = preg_match('/SPHEROID\s*\[\s*"([^"]*)"\s*,\s*([\d.]+)\s*,\s*([\d.]+)/i', $wkt, $s) ? $s : null;
        $name = preg_match('/^\s*(?:PROJCS|GEOGCS|PROJCRS|GEOGCRS)\s*\[\s*"([^"]+)"/i', $wkt, $n) ? $n[1] : 'unnamed';

        if (self::isCapeDatum($datum.' '.$name, $spheroid[1] ?? '')) {
            return "The file uses the old Cape datum ({$name}, Clarke 1880). Re-export it in WGS84 or Hartebeesthoek94 (for example as Lo or UTM on Hartebeesthoek94, or plain latitude/longitude) and import it again.";
        }
        $wgsLike = $datum === '' || preg_match('/WGS[_ ]?(19)?84|HARTEBEESTHOEK|ITRF|ETRS|GRS[_ ]?1980/i', $datum) === 1;
        if (! $wgsLike) {
            return "The file's datum ({$datum}) is not one this import can convert. Re-export it in WGS84 longitude/latitude.";
        }

        if (! str_starts_with($upper, 'PROJCS') && ! str_starts_with($upper, 'PROJCRS')) {
            return self::wgs84("{$name} (geographic degrees)");
        }

        $projection = preg_match('/PROJECTION\s*\[\s*"([^"]+)"/i', $wkt, $pm) ? $pm[1] : (preg_match('/METHOD\s*\[\s*"([^"]+)"/i', $wkt, $pm) ? $pm[1] : '');
        if (! preg_match('/transverse[_ ]?mercator|gauss[_ ]?kruger/i', $projection)) {
            return "The file is projected with {$projection} ({$name}), which this import cannot convert. Re-export it in WGS84 longitude/latitude, UTM or Lo.";
        }
        $param = function (string $key, float $default) use ($wkt) {
            return preg_match('/PARAMETER\s*\[\s*"'.$key.'"\s*,\s*(-?[\d.eE+]+)/i', $wkt, $x) ? (float) $x[1] : $default;
        };
        // The linear unit is the last UNIT of a PROJCS.
        $unit = preg_match_all('/UNIT\s*\[\s*"([^"]+)"\s*,\s*([\d.eE+]+)/i', $wkt, $u) ? (float) end($u[2]) : 1.0;
        $south = stripos($projection, 'south') !== false ? true : null;   // null: decide from the coordinates

        return new self([
            'kind' => 'tm',
            'lon0' => $param('central_meridian', $param('longitude_of_origin', 0)),
            'lat0' => $param('latitude_of_origin', 0),
            'k0' => $param('scale_factor', 1),
            'fe' => $param('false_easting', 0),
            'fn' => $param('false_northing', 0),
            'a' => $spheroid ? (float) $spheroid[2] : 6378137.0,
            'f' => $spheroid && (float) $spheroid[3] > 0 ? 1 / (float) $spheroid[3] : 1 / 298.257223563,
            'unit' => $unit > 0 ? $unit : 1.0,
            'south' => $south,
        ], "{$name} (Transverse Mercator, central meridian ".$param('central_meridian', 0).'°)');
    }

    /** From an EPSG code (GeoJSON "crs" members name one). */
    public static function fromEpsg(int $code): self|string
    {
        if (in_array($code, [4326, 4148, 4258, 4269], true)) {
            return self::wgs84("EPSG:{$code} (geographic degrees)");
        }
        if (($code >= 32601 && $code <= 32660) || ($code >= 32701 && $code <= 32760)) {
            $zone = $code % 100;
            $south = $code > 32700;

            return new self(['kind' => 'tm', 'lon0' => $zone * 6 - 183, 'lat0' => 0, 'k0' => 0.9996, 'fe' => 500000, 'fn' => $south ? 10000000 : 0,
                'a' => 6378137.0, 'f' => 1 / 298.257223563, 'unit' => 1.0, 'south' => false], "WGS 84 / UTM zone {$zone}".($south ? 'S' : 'N'));
        }
        if ($code >= 2046 && $code <= 2055) {   // Hartebeesthoek94 / Lo15 … Lo33, westing/southing
            $lo = 15 + 2 * ($code - 2046);

            return new self(['kind' => 'tm', 'lon0' => $lo, 'lat0' => 0, 'k0' => 1, 'fe' => 0, 'fn' => 0,
                'a' => 6378137.0, 'f' => 1 / 298.257223563, 'unit' => 1.0, 'south' => true], "Hartebeesthoek94 / Lo{$lo}");
        }
        if ($code >= 22275 && $code <= 22293) {
            return "The file uses the old Cape datum (EPSG:{$code}). Re-export it in WGS84 or Hartebeesthoek94 and import it again.";
        }

        return "The file's coordinate system (EPSG:{$code}) is not one this import can convert. Re-export it in WGS84 longitude/latitude.";
    }

    /**
     * Settle whether a Transverse Mercator file holds westing/southing. South African Lo files with
     * no false northing have negative northings when stored east/north; positive ones are southings.
     *
     * @param list<array{0: float, 1: float}> $sample
     */
    public function settle(array $sample): void
    {
        if (($this->p['kind'] ?? '') !== 'tm' || $this->p['south'] !== null) {
            return;
        }
        $ys = array_map(fn ($c) => $c[1], $sample);
        sort($ys);
        $median = $ys ? $ys[intdiv(count($ys), 2)] : -1;
        $this->p['south'] = $this->p['fn'] == 0 && $this->p['k0'] == 1.0 && $median > 0;
    }

    public function isGeographic(): bool
    {
        return $this->p['kind'] === 'geographic';
    }

    public function southOrientated(): bool
    {
        return (bool) ($this->p['south'] ?? false);
    }

    /** @return array{0: float, 1: float} [lng, lat] */
    public function toLngLat(float $x, float $y): array
    {
        if ($this->p['kind'] === 'geographic') {
            return [$x, $y];
        }
        $p = $this->p;
        $x *= $p['unit'];
        $y *= $p['unit'];
        if ($p['south']) {
            // Westing/southing: positive west and south of the origin.
            [$x, $y] = [-$x, -$y];
        }
        $a = $p['a'];
        $e2 = $p['f'] * (2 - $p['f']);
        $ep2 = $e2 / (1 - $e2);
        $k0 = $p['k0'];
        $m0 = self::meridianArc(deg2rad($p['lat0']), $a, $e2);
        $m = $m0 + ($y - $p['fn']) / $k0;
        $mu = $m / ($a * (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256));
        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));
        $phi1 = $mu + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu)
            + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu)
            + (1097 * $e1 ** 4 / 512) * sin(8 * $mu);
        $c1 = $ep2 * cos($phi1) ** 2;
        $t1 = tan($phi1) ** 2;
        $n1 = $a / sqrt(1 - $e2 * sin($phi1) ** 2);
        $r1 = $a * (1 - $e2) / (1 - $e2 * sin($phi1) ** 2) ** 1.5;
        $d = ($x - $p['fe']) / ($n1 * $k0);
        $lat = $phi1 - ($n1 * tan($phi1) / $r1) * ($d ** 2 / 2
            - (5 + 3 * $t1 + 10 * $c1 - 4 * $c1 ** 2 - 9 * $ep2) * $d ** 4 / 24
            + (61 + 90 * $t1 + 298 * $c1 + 45 * $t1 ** 2 - 252 * $ep2 - 3 * $c1 ** 2) * $d ** 6 / 720);
        $lng = deg2rad($p['lon0']) + ($d - (1 + 2 * $t1 + $c1) * $d ** 3 / 6
            + (5 - 2 * $c1 + 28 * $t1 - 3 * $c1 ** 2 + 8 * $ep2 + 24 * $t1 ** 2) * $d ** 5 / 120) / cos($phi1);

        return [round(rad2deg($lng), 8), round(rad2deg($lat), 8)];
    }

    /** Forward Transverse Mercator, for tests and fixtures. @return array{0: float, 1: float} [x, y] in the file's units */
    public function fromLngLat(float $lng, float $lat): array
    {
        if ($this->p['kind'] === 'geographic') {
            return [$lng, $lat];
        }
        $p = $this->p;
        $a = $p['a'];
        $e2 = $p['f'] * (2 - $p['f']);
        $ep2 = $e2 / (1 - $e2);
        $phi = deg2rad($lat);
        $n = $a / sqrt(1 - $e2 * sin($phi) ** 2);
        $t = tan($phi) ** 2;
        $c = $ep2 * cos($phi) ** 2;
        $aa = cos($phi) * deg2rad($lng - $p['lon0']);
        $m = self::meridianArc($phi, $a, $e2);
        $m0 = self::meridianArc(deg2rad($p['lat0']), $a, $e2);
        $x = $p['fe'] + $p['k0'] * $n * ($aa + (1 - $t + $c) * $aa ** 3 / 6 + (5 - 18 * $t + $t ** 2 + 72 * $c - 58 * $ep2) * $aa ** 5 / 120);
        $y = $p['fn'] + $p['k0'] * ($m - $m0 + $n * tan($phi) * ($aa ** 2 / 2 + (5 - $t + 9 * $c + 4 * $c ** 2) * $aa ** 4 / 24
            + (61 - 58 * $t + $t ** 2 + 600 * $c - 330 * $ep2) * $aa ** 6 / 720));
        if ($p['south']) {
            [$x, $y] = [-$x, -$y];
        }

        return [$x / $p['unit'], $y / $p['unit']];
    }

    private static function meridianArc(float $phi, float $a, float $e2): float
    {
        return $a * ((1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256) * $phi
            - (3 * $e2 / 8 + 3 * $e2 ** 2 / 32 + 45 * $e2 ** 3 / 1024) * sin(2 * $phi)
            + (15 * $e2 ** 2 / 256 + 45 * $e2 ** 3 / 1024) * sin(4 * $phi)
            - (35 * $e2 ** 3 / 3072) * sin(6 * $phi));
    }

    private static function isCapeDatum(string $names, string $spheroid): bool
    {
        return preg_match('/\bCape\b|D_Cape|Clarke[_ ]?1880/i', $names.' '.$spheroid) === 1;
    }
}
