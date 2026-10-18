<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Labels for every thing the floor scans (WMS-152): products, bins, pallets and cartons (by their
 * SSCC), handling units and dispatches -- each from a template the company sets: size, what to
 * print, and the barcode type. A label set comes out as data for the browser to print, or as ZPL
 * for a thermal printer, sent to a network printer on the warehouse's own network.
 */
class LabelPrinting
{
    public const KINDS = ['PRODUCT', 'BIN', 'PALLET', 'CARTON', 'HANDLING_UNIT', 'DISPATCH'];

    /** kind => [name, width, height, symbology, fields] when the company has not set its own. */
    public const DEFAULTS = [
        'PRODUCT' => ['Product label', 50, 30, 'CODE128', ['code', 'name', 'barcode']],
        'BIN' => ['Location label', 100, 50, 'QR', ['code', 'warehouse', 'barcode']],
        'PALLET' => ['Pallet label (SSCC)', 100, 150, 'GS1_128', ['sscc', 'order', 'ship_to', 'contents', 'weight']],
        'CARTON' => ['Carton label (SSCC)', 100, 75, 'GS1_128', ['sscc', 'order', 'contents']],
        'HANDLING_UNIT' => ['Handling unit', 100, 75, 'GS1_128', ['sscc', 'contents', 'weight']],
        'DISPATCH' => ['Dispatch label', 100, 150, 'CODE128', ['shipment', 'order', 'ship_to', 'carrier', 'tracking', 'package_of']],
    ];

    public function template(string $kind): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException("Unknown label type {$kind}.");
        }
        $t = DB::table('wms_label_templates')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('kind', $kind)->orderByDesc('is_default')->orderBy('id')->first();
        [$name, $w, $h, $sym, $fields] = self::DEFAULTS[$kind];

        return $t ? ['name' => $t->name, 'width_mm' => (float) $t->width_mm, 'height_mm' => (float) $t->height_mm, 'symbology' => $t->symbology,
            'fields' => json_decode($t->fields, true)] : ['name' => $name, 'width_mm' => $w, 'height_mm' => $h, 'symbology' => $sym, 'fields' => $fields];
    }

    /** @return list<array{barcode: string, lines: array<string,string>}> */
    public function labels(string $kind, Collection $subjects): array
    {
        return $subjects->map(fn ($s) => match ($kind) {
            'PRODUCT' => $this->product($s), 'BIN' => $this->bin($s), 'DISPATCH' => $this->dispatch($s),
            default => $this->package($s),
        })->flatten(1)->values()->all();
    }

    /** One ZPL document for the set, in the template's size and barcode type. */
    public function zpl(string $kind, array $labels, int $dpi = 203): string
    {
        $t = $this->template($kind);
        $dots = fn (float $mm) => (int) round($mm / 25.4 * $dpi);
        $out = '';
        foreach ($labels as $l) {
            $out .= "^XA\n^CI28\n^PW{$dots($t['width_mm'])}\n^LL{$dots($t['height_mm'])}\n";
            $y = 30;
            foreach ($t['fields'] as $f) {
                if ($f === 'barcode' || $f === 'sscc' || ! isset($l['lines'][$f])) {
                    continue;
                }
                $out .= "^FO30,{$y}^A0N,28,28^FD".$this->escape($l['lines'][$f])."^FS\n";
                $y += 36;
            }
            $value = $this->escape($l['barcode']);
            $out .= match ($t['symbology']) {
                'QR' => "^FO30,{$y}^BQN,2,6^FDQA,{$value}^FS\n",
                // GS1-128: FNC1 then the application identifier, here (00) for an SSCC.
                'GS1_128' => "^FO30,{$y}^BY3^BCN,120,Y,N,N^FD>;>8".(str_starts_with($l['barcode'], '00') ? $value : '00'.$value)."^FS\n",
                default => "^FO30,{$y}^BY2^BCN,100,Y,N,N^FD{$value}^FS\n",
            };
            $out .= "^XZ\n";
        }

        return $out;
    }

    /** Send ZPL to a printer on the warehouse network. Only private addresses: the server is never a relay to the internet. */
    public function send(string $printerUuid, string $zpl): void
    {
        $p = DB::table('wms_printers')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $printerUuid)->where('is_active', true)->first() ?? throw new RuntimeException('No such printer.');
        if (! self::isPrivateAddress($p->host)) {
            throw new RuntimeException('Printers must be on a private network address.');
        }
        $socket = @fsockopen($p->host, (int) $p->port, $errno, $error, 3.0);
        if ($socket === false) {
            throw new RuntimeException("The printer {$p->name} did not answer ({$error}).");
        }
        fwrite($socket, $zpl);
        fclose($socket);
    }

    public static function isPrivateAddress(string $host): bool
    {
        if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false; // names could resolve anywhere; an address is required
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
            && ! str_starts_with($host, '127.') && $host !== '0.0.0.0' && ! str_starts_with($host, '169.254.');
    }

    public function saveTemplate(array $data, int $companyId, ?string $uuid = null): void
    {
        $fields = array_values(array_intersect($data['fields'] ?? [], self::allFields($data['kind'])));
        if ($fields === []) {
            throw new RuntimeException('Choose at least one thing to print on the label.');
        }
        $row = ['company_id' => $companyId, 'kind' => $data['kind'], 'name' => $data['name'], 'width_mm' => $data['width_mm'], 'height_mm' => $data['height_mm'],
            'symbology' => $data['symbology'], 'fields' => json_encode($fields), 'is_default' => (bool) ($data['is_default'] ?? true), 'updated_at' => now()];
        DB::transaction(function () use ($row, $uuid, $data) {
            if ($row['is_default']) {
                DB::table('wms_label_templates')->where('company_id', $companyId)->where('kind', $data['kind'])->update(['is_default' => false]);
            }
            $uuid ? DB::table('wms_label_templates')->where('company_id', $companyId)->where('uuid', $uuid)->update($row)
                : DB::table('wms_label_templates')->insert($row + ['uuid' => (string) Str::uuid(), 'created_at' => now()]);
        });
    }

    public static function allFields(string $kind): array
    {
        return match ($kind) {
            'PRODUCT' => ['code', 'name', 'barcode', 'uom'],
            'BIN' => ['code', 'warehouse', 'barcode', 'kind'],
            'DISPATCH' => ['shipment', 'order', 'ship_to', 'carrier', 'tracking', 'package_of', 'weight'],
            default => ['sscc', 'order', 'ship_to', 'contents', 'weight', 'packed_by'],
        };
    }

    private function product(FinStockItem $i): array
    {
        $barcode = DB::table('wms_item_barcodes')->where('stock_item_id', $i->id)->where('is_active', true)->orderByDesc('is_primary')->value('barcode') ?? $i->code;

        return [['barcode' => $barcode, 'lines' => ['code' => $i->code, 'name' => $i->name, 'uom' => $i->uom]]];
    }

    private function bin(FinStockBin $b): array
    {
        return [['barcode' => $b->barcode ?? $b->code, 'lines' => ['code' => $b->code, 'warehouse' => $b->warehouse?->code, 'kind' => $b->kind]]];
    }

    private function package(Package $p): array
    {
        $order = $p->order;
        $contents = DB::table('wms_package_contents as c')->join('fin_stock_items as i', 'i.id', '=', 'c.item_id')->where('c.package_id', $p->id)
            ->get(['i.code', 'c.qty'])->map(fn ($c) => "{$c->code} ×".rtrim(rtrim(number_format((float) $c->qty, 4, '.', ''), '0'), '.'))->join(', ');

        return [['barcode' => $p->sscc, 'lines' => ['sscc' => '(00) '.$p->sscc, 'order' => $order?->number, 'ship_to' => $this->address($order?->ship_to),
            'contents' => Str::limit($contents, 80), 'weight' => $p->weight_kg ? "{$p->weight_kg} kg" : '']]];
    }

    private function dispatch(Shipment $s): array
    {
        $packages = DB::table('wms_shipment_packages as sp')->join('wms_packages as p', 'p.id', '=', 'sp.package_id')->where('sp.shipment_id', $s->id)->orderBy('p.id')
            ->get(['p.sscc', 'p.weight_kg']);
        $n = $packages->count();

        return $packages->values()->map(fn ($p, $i) => ['barcode' => $s->tracking_reference ?: $p->sscc, 'lines' => ['shipment' => $s->number, 'order' => $s->order?->number,
            'ship_to' => $this->address($s->order?->ship_to), 'carrier' => $s->carrier, 'tracking' => $s->tracking_reference, 'package_of' => ($i + 1)." of {$n}",
            'weight' => $p->weight_kg ? "{$p->weight_kg} kg" : '']])->all();
    }

    private function address($shipTo): string
    {
        $a = is_array($shipTo) ? $shipTo : (json_decode((string) $shipTo, true) ?: []);

        return trim(implode(', ', array_filter([$a['name'] ?? null, $a['line1'] ?? $a['street'] ?? null, $a['city'] ?? null, $a['postal_code'] ?? null])));
    }

    private function escape(?string $v): string
    {
        // ZPL field data must not contain the command characters.
        return str_replace(['^', '~'], [' ', ' '], (string) $v);
    }
}
