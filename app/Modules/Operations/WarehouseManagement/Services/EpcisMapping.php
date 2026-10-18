<?php

namespace App\Modules\Operations\WarehouseManagement\Services;

use App\Modules\Operations\WarehouseManagement\Concerns\ScopesRawQueries;
use App\Modules\Operations\WarehouseManagement\Support\Gs1Parser;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Traceability events expressed in GS1 terms (WMS-173): the stock ledger's movements, production
 * transformations and packed shipments mapped onto EPCIS 2.0 event types and CBV business steps and
 * dispositions, with products, lots, serials, pallets and locations named by GS1 Digital Link URIs
 * where the warehouse holds the GS1 key.
 *
 * This is a mapping, not a certified implementation. It has not been validated against the EPCIS
 * schema or a GS1 conformance test, and the document says so. Wherever a GS1 key is missing -- an
 * item with no GTIN, a warehouse with no GLN -- an internal URN stands in and the gap is listed, so
 * the work needed to exchange with a trading partner is visible rather than papered over.
 */
class EpcisMapping
{
    use ScopesRawQueries;

    /** movement type => [EPCIS event type, action, CBV bizStep, CBV disposition] */
    public const MOVEMENTS = [
        'receipt' => ['ObjectEvent', 'ADD', 'receiving', 'in_progress'],
        'cust_return' => ['ObjectEvent', 'OBSERVE', 'receiving', 'returned'],
        'mfg_return' => ['ObjectEvent', 'OBSERVE', 'receiving', 'in_progress'],
        'dispatch' => ['ObjectEvent', 'OBSERVE', 'shipping', 'in_transit'],
        'issue' => ['ObjectEvent', 'OBSERVE', 'departing', 'in_transit'],
        'transfer' => ['ObjectEvent', 'OBSERVE', 'storing', 'sellable_not_accessible'],
        'status' => ['ObjectEvent', 'OBSERVE', 'holding', null],
        'adjustment' => ['ObjectEvent', 'OBSERVE', 'cycle_counting', null],
        'relot' => ['ObjectEvent', 'OBSERVE', 'inspecting', null],
    ];

    /** Ledger stock status => CBV disposition. */
    public const DISPOSITIONS = [
        'AVAILABLE' => 'sellable_accessible', 'QUARANTINE' => 'non_sellable_other', 'ON_HOLD' => 'non_sellable_other', 'INSPECTION' => 'non_sellable_other',
        'REJECTED' => 'non_conformant', 'DAMAGED' => 'damaged', 'RECALLED' => 'recalled', 'EXPIRED' => 'expired', 'RESERVED' => 'reserved',
    ];

    private const UOM = ['kg' => 'KGM', 'g' => 'GRM', 'l' => 'LTR', 'ml' => 'MLT', 'm' => 'MTR'];

    private array $gaps = ['items_without_gtin' => [], 'warehouses_without_gln' => [], 'unmapped_movement_types' => []];

    private array $counts = ['gs1' => 0, 'internal' => 0];

    public function document(CarbonInterface $from, CarbonInterface $to, ?int $warehouseId = null, int $limit = 5000): array
    {
        $cid = $this->tenantId();
        $events = [];
        $movements = DB::table('fin_stock_movements as m')->join('fin_warehouses as w', 'w.id', '=', 'm.warehouse_id')->where('w.is_wms_managed', true)
            ->when($cid, fn ($q, $c) => $q->where('m.company_id', $c))->when($warehouseId, fn ($q, $w) => $q->where('m.warehouse_id', $w))
            ->whereBetween(DB::raw('COALESCE(m.occurred_at, m.created_at)'), [$from, $to])->whereNull('m.reversal_of_id')
            ->orderBy('m.id')->limit($limit)->get(['m.*']);

        foreach ($movements->whereNotIn('type', ['mfg_issue', 'mfg_receipt']) as $m) {
            if (! isset(self::MOVEMENTS[$m->type])) {
                $this->gaps['unmapped_movement_types'][$m->type] = true;

                continue;
            }
            [$type, $action, $step, $disposition] = self::MOVEMENTS[$m->type];
            $disposition ??= self::DISPOSITIONS[$m->to_stock_status ?? $m->stock_status ?? 'AVAILABLE'] ?? null;
            $events[] = array_filter(['type' => $type, 'eventID' => "urn:uuid:{$m->uuid}"] + $this->when($m, (int) $m->warehouse_id) + [
                'action' => $action, 'bizStep' => $step, 'disposition' => $disposition,
                'quantityList' => [$this->quantity((int) $m->item_id, $m->lot_id ? (int) $m->lot_id : null, abs((float) $m->qty))],
                'readPoint' => ['id' => $this->location((int) $m->warehouse_id)], 'bizLocation' => ['id' => $this->location((int) ($m->to_warehouse_id ?: $m->warehouse_id))],
                'bizTransactionList' => $m->reference ? [['type' => 'urn:mkhaya:btt:reference', 'bizTransaction' => 'urn:mkhaya:reference:'.rawurlencode((string) $m->reference)]] : null,
            ], fn ($v) => $v !== null);
        }

        // Production: what an order consumed and what it made, as one transformation.
        foreach ($movements->whereIn('type', ['mfg_issue', 'mfg_receipt'])->groupBy('source_id') as $orderId => $g) {
            $first = $g->first();
            $events[] = ['type' => 'TransformationEvent', 'eventID' => 'urn:mkhaya:transformation:'.$orderId] + $this->when($first, (int) $first->warehouse_id) + [
                'transformationID' => 'urn:mkhaya:production-order:'.(DB::table('mfg_production_orders')->where('id', $orderId)->value('uuid') ?? $orderId),
                'bizStep' => 'commissioning',
                'inputQuantityList' => $g->where('type', 'mfg_issue')->map(fn ($m) => $this->quantity((int) $m->item_id, $m->lot_id ? (int) $m->lot_id : null, abs((float) $m->qty)))->values()->all(),
                'outputQuantityList' => $g->where('type', 'mfg_receipt')->map(fn ($m) => $this->quantity((int) $m->item_id, $m->lot_id ? (int) $m->lot_id : null, abs((float) $m->qty)))->values()->all(),
                'readPoint' => ['id' => $this->location((int) $first->warehouse_id)],
            ];
        }

        // Packing: each dispatched pallet or carton with what is in it.
        $packages = DB::table('wms_packages as p')->join('wms_shipment_packages as sp', 'sp.package_id', '=', 'p.id')->join('wms_shipments as s', 's.id', '=', 'sp.shipment_id')
            ->when($cid, fn ($q, $c) => $q->where('p.company_id', $c))->when($warehouseId, fn ($q, $w) => $q->where('s.warehouse_id', $w))
            ->whereBetween('s.dispatched_at', [$from, $to])->limit($limit)->get(['p.id', 'p.sscc', 'p.closed_at', 's.warehouse_id', 's.dispatched_at']);
        foreach ($packages as $p) {
            $contents = DB::table('wms_package_contents')->where('package_id', $p->id)->get(['item_id', 'lot_id', 'qty']);
            $this->counts['gs1']++;
            $events[] = ['type' => 'AggregationEvent', 'eventID' => 'urn:mkhaya:aggregation:'.$p->id]
                + $this->when((object) ['occurred_at' => $p->closed_at ?? $p->dispatched_at, 'created_at' => $p->dispatched_at], (int) $p->warehouse_id) + [
                    'parentID' => 'https://id.gs1.org/00/'.$p->sscc, 'action' => 'ADD', 'bizStep' => 'packing', 'disposition' => 'container_closed',
                    'childQuantityList' => $contents->map(fn ($c) => $this->quantity((int) $c->item_id, $c->lot_id ? (int) $c->lot_id : null, (float) $c->qty))->values()->all(),
                    'readPoint' => ['id' => $this->location((int) $p->warehouse_id)],
                ];
        }

        usort($events, fn ($a, $b) => strcmp($a['eventTime'], $b['eventTime']));

        return [
            '@context' => ['https://ref.gs1.org/standards/epcis/2.0.0/epcis-context.jsonld'],
            'type' => 'EPCISDocument', 'schemaVersion' => '2.0', 'creationDate' => now()->toIso8601String(),
            'epcisBody' => ['eventList' => $events],
            'mkhaya:conformance' => 'Mapping only. Not validated against the EPCIS 2.0 schema or certified by GS1; do not present as a conformant EPCIS repository.',
            'mkhaya:coverage' => ['events' => count($events), 'identifiers_gs1' => $this->counts['gs1'], 'identifiers_internal' => $this->counts['internal'],
                'items_without_gtin' => array_keys($this->gaps['items_without_gtin']), 'warehouses_without_gln' => array_keys($this->gaps['warehouses_without_gln']),
                'unmapped_movement_types' => array_keys($this->gaps['unmapped_movement_types'])],
        ];
    }

    private function when(object $m, int $warehouseId): array
    {
        $tz = DB::table('fin_warehouses')->where('id', $warehouseId)->value('timezone') ?: 'UTC';
        $at = Carbon::parse($m->occurred_at ?? $m->created_at)->setTimezone($tz);

        return ['eventTime' => $at->toIso8601String(), 'eventTimeZoneOffset' => $at->format('P')];
    }

    private function quantity(int $itemId, ?int $lotId, float $qty): array
    {
        $item = DB::table('fin_stock_items')->where('id', $itemId)->first(['uuid', 'code', 'uom']);
        $gtin = $this->gtin($itemId);
        $lot = $lotId ? DB::table('fin_stock_lots')->where('id', $lotId)->value('lot_number') : null;
        if ($gtin) {
            $this->counts['gs1']++;
            $class = "https://id.gs1.org/01/{$gtin}".($lot !== null ? '/10/'.rawurlencode($lot) : '');
        } else {
            $this->counts['internal']++;
            $this->gaps['items_without_gtin'][$item->code ?? $itemId] = true;
            $class = "urn:mkhaya:item:{$item?->uuid}".($lot !== null ? ':lot:'.rawurlencode($lot) : '');
        }

        return array_filter(['epcClass' => $class, 'quantity' => round($qty, 4), 'uom' => self::UOM[strtolower((string) $item?->uom)] ?? null], fn ($v) => $v !== null);
    }

    private function gtin(int $itemId): ?string
    {
        $g = DB::table('wms_item_packaging')->where('stock_item_id', $itemId)->where('level', 'EACH')->whereNotNull('gtin')->where('is_active', true)->value('gtin')
            ?? DB::table('wms_item_barcodes')->where('stock_item_id', $itemId)->whereIn('symbology', ['EAN13', 'EAN8', 'UPCA', 'GTIN14'])->where('is_active', true)->whereNull('packaging_id')->value('barcode');

        return $g ? Gs1Parser::normaliseGtin((string) $g) : null;
    }

    private function location(int $warehouseId): string
    {
        $w = DB::table('fin_warehouses')->where('id', $warehouseId)->first(['uuid', 'code', 'policies']);
        $gln = json_decode((string) ($w->policies ?? 'null'), true)['gln'] ?? null;
        if ($gln && preg_match('/^\d{13}$/', (string) $gln)) {
            return "https://id.gs1.org/414/{$gln}";
        }
        $this->gaps['warehouses_without_gln'][$w->code ?? $warehouseId] = true;

        return "urn:mkhaya:warehouse:{$w?->uuid}";
    }
}
