<?php

namespace App\Modules\Agri\Support;

/**
 * The geometry a field map needs, on WGS84 longitude/latitude, with no GIS extension behind it.
 *
 * Areas are geodesic (on the WGS84 sphere, the method web maps use), not planar degrees, so a
 * field near the equator and one in the Karoo measure the same way. Tests between shapes allow a
 * metre or so of tolerance: fields that share a fence line are neighbours, not an overlap.
 */
final class Geo
{
    public const EARTH_RADIUS = 6378137.0;

    /** The smallest boundary accepted, in hectares (100 m²). */
    public const MIN_HECTARES = 0.01;

    /** Mapped against recorded area: a difference beyond this share is flagged. */
    public const AREA_TOLERANCE = 0.10;

    /** Metres within which a vertex counts as on another shape's edge. */
    public const TOLERANCE_M = 1.5;

    /**
     * A Polygon or MultiPolygon from what is stored or submitted: a geometry, a Feature, or the
     * older list of [lat, lng] points. Positions are cast to floats and altitude is dropped.
     */
    public static function polygon(mixed $b): ?array
    {
        if (is_string($b)) {
            $b = json_decode($b, true);
        }
        if (! is_array($b) || ! $b) {
            return null;
        }
        if (($b['type'] ?? null) === 'Feature') {
            return self::polygon($b['geometry'] ?? null);
        }
        if (($b['type'] ?? null) === 'Polygon' && is_array($b['coordinates'] ?? null)) {
            return ['type' => 'Polygon', 'coordinates' => array_map(fn ($r) => self::ring($r), array_values($b['coordinates']))];
        }
        if (($b['type'] ?? null) === 'MultiPolygon' && is_array($b['coordinates'] ?? null)) {
            $polys = array_map(fn ($p) => array_map(fn ($r) => self::ring($r), array_values((array) $p)), array_values($b['coordinates']));

            return count($polys) === 1 ? ['type' => 'Polygon', 'coordinates' => $polys[0]] : ['type' => 'MultiPolygon', 'coordinates' => $polys];
        }
        if (array_is_list($b) && is_array($b[0] ?? null) && count($b[0]) === 2 && is_numeric($b[0][0])) {
            $ring = array_map(fn ($pt) => [(float) $pt[1], (float) $pt[0]], $b);   // [lat, lng] → [lng, lat]
            if ($ring && $ring[0] !== end($ring)) {
                $ring[] = $ring[0];
            }

            return ['type' => 'Polygon', 'coordinates' => [$ring]];
        }

        return null;
    }

    /** A point from a GeoJSON Point geometry or Feature. @return array{0: float, 1: float}|null [lng, lat] */
    public static function point(mixed $b): ?array
    {
        if (! is_array($b)) {
            return null;
        }
        if (($b['type'] ?? null) === 'Feature') {
            return self::point($b['geometry'] ?? null);
        }
        if (($b['type'] ?? null) === 'Point' && is_array($b['coordinates'] ?? null) && count($b['coordinates']) >= 2) {
            return [(float) $b['coordinates'][0], (float) $b['coordinates'][1]];
        }

        return null;
    }

    /** The polygons of a geometry, each a list of rings (outer first, then holes). @return list<list<list<array{0: float, 1: float}>>> */
    public static function polygons(?array $g): array
    {
        if (! $g) {
            return [];
        }

        return match ($g['type'] ?? null) {
            'Polygon' => [$g['coordinates']],
            'MultiPolygon' => $g['coordinates'],
            default => [],
        };
    }

    /** Close every ring that is not closed (imported files are not always tidy). */
    public static function close(array $g): array
    {
        $fix = function (array $ring) {
            if (count($ring) >= 3 && $ring[0] !== end($ring)) {
                $ring[] = $ring[0];
            }

            return $ring;
        };
        if ($g['type'] === 'Polygon') {
            $g['coordinates'] = array_map($fix, $g['coordinates']);
        } else {
            $g['coordinates'] = array_map(fn ($p) => array_map($fix, $p), $g['coordinates']);
        }

        return $g;
    }

    /** Geodesic area in hectares; null without a shape. */
    public static function hectares(?array $g): ?float
    {
        $polys = self::polygons($g);
        if (! $polys) {
            return null;
        }
        $m2 = 0.0;
        foreach ($polys as $rings) {
            foreach ($rings as $i => $ring) {
                $a = abs(self::ringArea($ring));
                $m2 += $i === 0 ? $a : -$a;
            }
        }

        return round(max(0, $m2) / 10000, 4);
    }

    /**
     * Why a boundary cannot be accepted, in words a person can act on; empty when it is fine.
     *
     * @return list<string>
     */
    public static function problems(?array $g, float $minHa = self::MIN_HECTARES): array
    {
        if (! $g || ! in_array($g['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
            return ['The shape must be a polygon.'];
        }
        $polys = self::polygons($g);
        if (! $polys) {
            return ['The shape has no outline.'];
        }
        $out = [];
        foreach ($polys as $p => $rings) {
            $which = count($polys) > 1 ? ' (part '.($p + 1).')' : '';
            if (! $rings) {
                $out[] = "The outline{$which} is empty.";

                continue;
            }
            foreach ($rings as $r => $ring) {
                $name = ($r === 0 ? 'The outline' : 'Hole '.$r).$which;
                foreach ($ring as $pt) {
                    if (! is_array($pt) || count($pt) < 2 || ! is_numeric($pt[0]) || ! is_numeric($pt[1])
                        || $pt[0] < -180 || $pt[0] > 180 || $pt[1] < -90 || $pt[1] > 90) {
                        $out[] = "{$name} has a point that is not a longitude/latitude.";

                        continue 2;
                    }
                }
                if (count($ring) < 4) {
                    $out[] = "{$name} needs at least three corners.";

                    continue;
                }
                if ($ring[0] !== end($ring)) {
                    $out[] = "{$name} is not closed: its last point must be its first.";

                    continue;
                }
                if (count(array_unique(array_map(fn ($c) => round($c[0], 8).','.round($c[1], 8), $ring))) < 3) {
                    $out[] = "{$name} needs at least three different corners.";

                    continue;
                }
                if (self::selfIntersects($ring)) {
                    $out[] = "{$name} crosses itself. Move the corners so the edges do not cross.";
                }
                if ($r > 0 && ! self::ringInsideRing($ring, $rings[0])) {
                    $out[] = "{$name} is not inside the outline.";
                }
            }
            for ($a = 0; $a < count($rings); $a++) {
                for ($b = $a + 1; $b < count($rings); $b++) {
                    if (self::ringsCross($rings[$a], $rings[$b])) {
                        $out[] = "The outline and its holes cross each other{$which}.";

                        break 2;
                    }
                }
            }
        }
        if (! $out) {
            $ha = self::hectares($g);
            if ($ha < $minHa) {
                $out[] = sprintf('The shape is too small (%s m²); the smallest boundary is %s m².', round($ha * 10000), round($minHa * 10000));
            }
        }

        return array_values(array_unique($out));
    }

    /** The middle of a shape (area-weighted centroid of its largest outline). @return array{0: float, 1: float}|null [lng, lat] */
    public static function centroid(?array $g): ?array
    {
        $best = null;
        $bestArea = -1;
        foreach (self::polygons($g) as $rings) {
            $a = abs(self::ringArea($rings[0] ?? []));
            if ($a > $bestArea) {
                $bestArea = $a;
                $best = $rings[0];
            }
        }
        if (! $best || count($best) < 4) {
            return null;
        }
        $x0 = $best[0][0];
        $y0 = $best[0][1];
        $cx = $cy = $area = 0.0;
        for ($i = 0, $n = count($best) - 1; $i < $n; $i++) {
            [$x1, $y1] = [$best[$i][0] - $x0, $best[$i][1] - $y0];
            [$x2, $y2] = [$best[$i + 1][0] - $x0, $best[$i + 1][1] - $y0];
            $f = $x1 * $y2 - $x2 * $y1;
            $area += $f;
            $cx += ($x1 + $x2) * $f;
            $cy += ($y1 + $y2) * $f;
        }
        if (abs($area) < 1e-18) {
            return [$x0, $y0];
        }

        return [round($x0 + $cx / (3 * $area), 7), round($y0 + $cy / (3 * $area), 7)];
    }

    /** [minLng, minLat, maxLng, maxLat] of a shape, or null. */
    public static function bounds(?array $g): ?array
    {
        $pts = array_merge(...array_map(fn ($rings) => $rings[0] ?? [], self::polygons($g) ?: [[]]));
        if (! $pts) {
            return null;
        }

        return [min(array_column($pts, 0)), min(array_column($pts, 1)), max(array_column($pts, 0)), max(array_column($pts, 1))];
    }

    /** True when a point [lng, lat] is inside a shape (holes excluded). */
    public static function contains(?array $g, array $pt): bool
    {
        foreach (self::polygons($g) as $rings) {
            if (self::inRing($pt, $rings[0]) && ! array_filter(array_slice($rings, 1), fn ($h) => self::inRing($pt, $h))) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many of the inner shape's corners lie outside the outer one (allowing for the tolerance),
     * plus one when an edge crosses out and back in. Zero means the inner shape is inside.
     */
    public static function outsideCount(?array $outer, ?array $inner): int
    {
        $n = 0;
        foreach (self::vertices($inner) as $pt) {
            if (! self::contains($outer, $pt) && self::distanceToEdges($pt, $outer) > self::TOLERANCE_M) {
                $n++;
            }
        }
        if ($n === 0 && self::shapesCross($outer, $inner)) {
            $n = 1;
        }

        return $n;
    }

    /** True when two shapes share more than an edge or a corner. */
    public static function overlaps(?array $a, ?array $b): bool
    {
        if (! $a || ! $b || ! self::boundsTouch(self::bounds($a), self::bounds($b))) {
            return false;
        }
        if (self::shapesCross($a, $b)) {
            return true;
        }
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            foreach ([...self::vertices($x), self::centroid($x), ...self::samples($x)] as $pt) {
                if ($pt && self::contains($y, $pt) && self::distanceToEdges($pt, $y) > self::TOLERANCE_M) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Points spread over the inside of a shape, clear of its edges: shapes that only share edges
     * and corners can still overlap, and a corner test alone would miss it.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function samples(?array $g, int $n = 7): array
    {
        $b = self::bounds($g);
        if (! $b) {
            return [];
        }
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $pt = [$b[0] + ($b[2] - $b[0]) * $i / ($n + 1), $b[1] + ($b[3] - $b[1]) * $j / ($n + 1)];
                if (self::contains($g, $pt) && self::distanceToEdges($pt, $g) > self::TOLERANCE_M) {
                    $out[] = $pt;
                }
            }
        }

        return $out;
    }

    /** True when the recorded and mapped areas differ by more than the tolerance. */
    public static function areaDiffers(?float $recordedHa, ?float $mappedHa): bool
    {
        if ($recordedHa === null || $mappedHa === null || $recordedHa <= 0) {
            return false;
        }

        return abs($mappedHa - $recordedHa) / $recordedHa > self::AREA_TOLERANCE;
    }

    /** Round every position to 7 decimals (about a centimetre). */
    public static function tidy(array $g): array
    {
        $r = fn (array $ring) => array_map(fn ($c) => [round((float) $c[0], 7), round((float) $c[1], 7)], $ring);
        if ($g['type'] === 'Polygon') {
            $g['coordinates'] = array_map($r, $g['coordinates']);
        } else {
            $g['coordinates'] = array_map(fn ($p) => array_map($r, $p), $g['coordinates']);
        }

        return $g;
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    private static function ring(mixed $r): array
    {
        return array_values(array_map(fn ($c) => is_array($c) && count($c) >= 2 && is_numeric($c[0] ?? null) && is_numeric($c[1] ?? null)
            ? [(float) $c[0], (float) $c[1]] : $c, array_values((array) $r)));
    }

    /** Signed area of a ring on the sphere, m² (Chamberlain & Duquette, as web maps compute it). */
    private static function ringArea(array $ring): float
    {
        $n = count($ring);
        if ($n < 3) {
            return 0.0;
        }
        $sum = 0.0;
        for ($i = 0; $i < $n - 1; $i++) {
            [$lng1, $lat1] = $ring[$i];
            [$lng2, $lat2] = $ring[$i + 1];
            $sum += deg2rad($lng2 - $lng1) * (2 + sin(deg2rad($lat1)) + sin(deg2rad($lat2)));
        }

        return $sum * self::EARTH_RADIUS * self::EARTH_RADIUS / 2;
    }

    /** @return list<array{0: float, 1: float}> every corner, closing points left out */
    private static function vertices(?array $g): array
    {
        $out = [];
        foreach (self::polygons($g) as $rings) {
            foreach ($rings as $ring) {
                array_push($out, ...array_slice($ring, 0, max(0, count($ring) - 1)));
            }
        }

        return $out;
    }

    private static function inRing(array $pt, array $ring): bool
    {
        [$x, $y] = $pt;
        $in = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-18) + $xi)) {
                $in = ! $in;
            }
        }

        return $in;
    }

    private static function ringInsideRing(array $inner, array $outer): bool
    {
        foreach (array_slice($inner, 0, -1) as $pt) {
            if (! self::inRing($pt, $outer)) {
                return false;
            }
        }

        return ! self::ringsCross($inner, $outer);
    }

    /** Orientation of c relative to a→b, with a small dead band so collinear stays collinear. */
    private static function orient(array $a, array $b, array $c): int
    {
        $v = ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);

        return abs($v) < 1e-16 ? 0 : ($v > 0 ? 1 : -1);
    }

    /** Segments p1p2 and p3p4 cross at a point inside both (touching or sharing an end is not crossing). */
    private static function properCross(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d1 = self::orient($p3, $p4, $p1);
        $d2 = self::orient($p3, $p4, $p2);
        $d3 = self::orient($p1, $p2, $p3);
        $d4 = self::orient($p1, $p2, $p4);

        return $d1 * $d2 < 0 && $d3 * $d4 < 0;
    }

    private static function selfIntersects(array $ring): bool
    {
        $n = count($ring) - 1;   // segments
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue;   // the first and last edges share the closing corner
                }
                if (self::properCross($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    return true;
                }
                // A corner visited twice (a figure of eight through one point) also crosses itself.
                if ($ring[$i] == $ring[$j]) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function ringsCross(array $a, array $b): bool
    {
        for ($i = 0; $i < count($a) - 1; $i++) {
            for ($j = 0; $j < count($b) - 1; $j++) {
                if (self::properCross($a[$i], $a[$i + 1], $b[$j], $b[$j + 1])) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function shapesCross(?array $a, ?array $b): bool
    {
        foreach (self::polygons($a) as $ra) {
            foreach (self::polygons($b) as $rb) {
                foreach ($ra as $x) {
                    foreach ($rb as $y) {
                        if (self::ringsCross($x, $y)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private static function boundsTouch(?array $a, ?array $b): bool
    {
        return $a && $b && $a[0] <= $b[2] && $b[0] <= $a[2] && $a[1] <= $b[3] && $b[1] <= $a[3];
    }

    /** Metres from a point to the nearest edge of a shape. */
    private static function distanceToEdges(array $pt, ?array $g): float
    {
        $best = INF;
        $k = cos(deg2rad($pt[1]));
        $m = fn (array $c) => [($c[0] - $pt[0]) * $k * 111320, ($c[1] - $pt[1]) * 110574];
        foreach (self::polygons($g) as $rings) {
            foreach ($rings as $ring) {
                for ($i = 0; $i < count($ring) - 1; $i++) {
                    [$ax, $ay] = $m($ring[$i]);
                    [$bx, $by] = $m($ring[$i + 1]);
                    $dx = $bx - $ax;
                    $dy = $by - $ay;
                    $len = $dx * $dx + $dy * $dy;
                    $t = $len > 0 ? max(0, min(1, -($ax * $dx + $ay * $dy) / $len)) : 0;
                    $best = min($best, hypot($ax + $t * $dx, $ay + $t * $dy));
                }
            }
        }

        return $best;
    }
}
