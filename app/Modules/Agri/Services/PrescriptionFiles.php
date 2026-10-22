<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\FarmField;
use App\Modules\Agri\Models\Prescription;
use App\Modules\Agri\Models\PrescriptionFile;
use App\Modules\Agri\Support\Geo;
use Illuminate\Validation\ValidationException;

/**
 * The machine files made from a prescription, one per format and version, kept in the database:
 *
 *  - a zipped ESRI shapefile, polygon per zone with its rate (RATE), unit, class, product and area —
 *    the format every monitor reads, and what Operations Center takes;
 *  - ISO-XML (ISO 11783-10): a TASKDATA folder with TASKDATA.XML, the field as a partfield with
 *    its boundary, the product, and a task whose treatment zones (one per distinct rate) carry the
 *    rate as a setpoint process-data value and their polygons;
 *  - GeoJSON, for anything else (and for people).
 */
class PrescriptionFiles
{
    public const MIME = ['shapefile' => 'application/zip', 'isoxml' => 'application/zip', 'geojson' => 'application/geo+json'];

    public function __construct(private readonly BoundaryFiles $files) {}

    /** The file for the prescription's current version, made now if it is not there yet. */
    public function ensure(Prescription $rx, string $format, ?User $actor = null): PrescriptionFile
    {
        if (! in_array($format, PrescriptionFile::FORMATS, true)) {
            throw ValidationException::withMessages(['format' => 'Choose a shapefile, ISO-XML or GeoJSON.']);
        }
        $existing = PrescriptionFile::withoutGlobalScopes()->where('prescription_id', $rx->id)->where('format', $format)->where('version', $rx->version)->first();
        if ($existing) {
            return $existing;
        }
        [$name, $bytes] = $this->make($rx, $format);

        return PrescriptionFile::withoutGlobalScopes()->create(['company_id' => $rx->company_id, 'farm_id' => $rx->farm_id, 'prescription_id' => $rx->id,
            'format' => $format, 'version' => $rx->version, 'file_name' => $name, 'mime' => self::MIME[$format], 'data' => base64_encode($bytes),
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'created_by' => $actor?->id]);
    }

    /** @return array{0: string, 1: string} file name and bytes */
    public function make(Prescription $rx, string $format): array
    {
        $zones = $rx->zones()->withoutGlobalScopes()->orderBy('position')->get();
        if ($zones->isEmpty()) {
            throw ValidationException::withMessages(['prescription' => 'The prescription has no zones.']);
        }
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', "{$rx->code}_v{$rx->version}");
        $props = fn ($z) => ['ZONE' => $z->label, 'CLASS' => $z->class, 'RATE' => round($z->rate, 2), 'UNIT' => self::unitLabel($rx->unit),
            'PRODUCT' => $rx->product_name, 'AREA_HA' => round($z->area_ha, 4)];

        return match ($format) {
            'shapefile' => ["{$base}.zip", $this->files->toShapefileZip($zones->map(fn ($z) => ['geometry' => $z->boundary, 'properties' => $props($z)])->all(), $base, [
                ['name' => 'ZONE', 'type' => 'C', 'len' => 60], ['name' => 'CLASS', 'type' => 'N', 'len' => 3, 'dec' => 0],
                ['name' => 'RATE', 'type' => 'N', 'len' => 14, 'dec' => 2], ['name' => 'UNIT', 'type' => 'C', 'len' => 12],
                ['name' => 'PRODUCT', 'type' => 'C', 'len' => 80], ['name' => 'AREA_HA', 'type' => 'N', 'len' => 14, 'dec' => 4]])],
            'geojson' => ["{$base}.geojson", json_encode(['type' => 'FeatureCollection', 'name' => $base,
                'properties' => ['prescription' => $rx->code, 'version' => $rx->version, 'product' => $rx->product_name, 'unit' => $rx->unit, 'operation' => $rx->operation],
                'features' => $zones->map(fn ($z) => ['type' => 'Feature', 'properties' => array_change_key_case($props($z), CASE_LOWER), 'geometry' => $z->boundary])->all()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)],
            'isoxml' => ["{$base}_TASKDATA.zip", $this->zip(['TASKDATA/TASKDATA.XML' => $this->taskData($rx, $zones)])],
        };
    }

    /** ISO 11783-10 TASKDATA.XML for the prescription. */
    public function taskData(Prescription $rx, $zones = null): string
    {
        $zones ??= $rx->zones()->withoutGlobalScopes()->orderBy('position')->get();
        $field = FarmField::withoutGlobalScopes()->findOrFail($rx->field_id);
        $farm = Farm::withoutGlobalScopes()->findOrFail($rx->farm_id);
        $ddi = config("agri.prescriptions.isoxml.{$rx->unit}") ?? ['ddi' => '0006', 'factor' => 100];
        $value = fn (float $rate) => (string) (int) round($rate * (float) $ddi['factor']);

        $x = new \XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('ISO11783_TaskData');
        foreach (['VersionMajor' => '4', 'VersionMinor' => '3', 'ManagementSoftwareManufacturer' => 'Mkhaya Agri', 'ManagementSoftwareVersion' => '1.0', 'DataTransferOrigin' => '1'] as $k => $v) {
            $x->writeAttribute($k, $v);
        }
        $this->element($x, 'CTR', ['A' => 'CTR1', 'B' => mb_strimwidth($farm->name, 0, 32)]);
        $this->element($x, 'FRM', ['A' => 'FRM1', 'B' => mb_strimwidth($farm->name, 0, 32), 'I' => 'CTR1']);
        $this->element($x, 'PDT', ['A' => 'PDT1', 'B' => mb_strimwidth($rx->product_name, 0, 32)]);

        $shape = Geo::polygon($field->boundary);
        $x->startElement('PFD');
        foreach (['A' => 'PFD1', 'C' => mb_strimwidth(trim("{$field->code} {$field->name}"), 0, 32), 'D' => (string) (int) round(($shape ? Geo::hectares($shape) : $rx->area_ha) * 10000), 'E' => 'CTR1', 'F' => 'FRM1'] as $k => $v) {
            $x->writeAttribute($k, $v);
        }
        if ($shape) {
            $this->polygon($x, $shape, '1', $field->code);
        }
        $x->endElement();

        $x->startElement('TSK');
        // G=1 planned; H, I, J: the zone used by default, when the position is lost and outside the field (all zone 0, which applies nothing).
        foreach (['A' => 'TSK1', 'B' => mb_strimwidth("{$rx->code} {$rx->name}", 0, 32), 'C' => 'CTR1', 'D' => 'FRM1', 'E' => 'PFD1', 'G' => '1', 'H' => '0', 'I' => '0', 'J' => '0'] as $k => $v) {
            $x->writeAttribute($k, $v);
        }
        $x->startElement('TZN');
        $x->writeAttribute('A', '0');
        $x->writeAttribute('B', 'Outside zones');
        $this->element($x, 'PDV', ['A' => $ddi['ddi'], 'B' => '0', 'C' => 'PDT1']);
        $x->endElement();
        $code = 0;
        foreach ($zones->groupBy(fn ($z) => number_format($z->rate, 4, '.', '')) as $rate => $group) {
            $code++;
            $x->startElement('TZN');
            $x->writeAttribute('A', (string) $code);
            $x->writeAttribute('B', mb_strimwidth($group->count() === 1 ? $group->first()->label : number_format((float) $rate, 2, '.', '').' '.self::unitLabel($rx->unit), 0, 32));
            $this->element($x, 'PDV', ['A' => $ddi['ddi'], 'B' => $value((float) $rate), 'C' => 'PDT1']);
            foreach ($group as $z) {
                $this->polygon($x, Geo::polygon($z->boundary), '2', $z->label);
            }
            $x->endElement();
        }
        $x->endElement();
        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    public static function unitLabel(string $unit): string
    {
        return ['kg_ha' => 'kg/ha', 'l_ha' => 'l/ha', 'seeds_ha' => 'seeds/ha'][$unit] ?? $unit;
    }

    private function polygon(\XMLWriter $x, ?array $g, string $type, string $label): void
    {
        foreach (Geo::polygons($g) as $rings) {
            $x->startElement('PLN');
            $x->writeAttribute('A', $type);                       // 1 partfield boundary, 2 treatment zone
            $x->writeAttribute('C', mb_strimwidth($label, 0, 32));
            foreach ($rings as $i => $ring) {
                $x->startElement('LSG');
                $x->writeAttribute('A', $i === 0 ? '1' : '2');    // 1 exterior, 2 interior
                foreach ($ring as $c) {
                    // PNT type 2 (other); C north (latitude), D east (longitude), in degrees.
                    $this->element($x, 'PNT', ['A' => '2', 'C' => number_format((float) $c[1], 9, '.', ''), 'D' => number_format((float) $c[0], 9, '.', '')]);
                }
                $x->endElement();
            }
            $x->endElement();
        }
    }

    private function element(\XMLWriter $x, string $name, array $attrs): void
    {
        $x->startElement($name);
        foreach ($attrs as $k => $v) {
            $x->writeAttribute($k, (string) $v);
        }
        $x->endElement();
    }

    private function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agri-rx-');
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
}
