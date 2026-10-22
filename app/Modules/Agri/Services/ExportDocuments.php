<?php

namespace App\Modules\Agri\Services;

use App\Core\User\Models\User;
use App\Modules\Agri\Models\CropPlan;
use App\Modules\Agri\Models\CropVariety;
use App\Modules\Agri\Models\ExportCode;
use App\Modules\Agri\Models\ExportConsignment;
use App\Modules\Agri\Models\ExportConsignmentLine;
use App\Modules\Agri\Models\ExportDocument;
use App\Modules\Agri\Models\Farm;
use App\Modules\Agri\Models\ProduceLot;
use App\Modules\Agri\Support\MrlStarterSet;
use App\Modules\Agri\Support\UiLabels;
use App\Modules\Finance\Accounting\Models\FinParty;
use App\Modules\Finance\Accounting\Models\FinSalesOrder;
use App\Modules\Operations\DocumentManagement\Models\Document;
use App\Modules\Operations\WarehouseManagement\Models\FulfilmentOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The export document pack of a load.
 *
 * An export load (one going to a country other than home) gets an export consignment: who it goes
 * to and how (consignee, notify party, incoterm, container and seal, vessel and voyage or air
 * waybill, ports), the cold chain, and a line per lot with its pallets, cartons, net and gross mass,
 * HS code and price. From it the pack is generated: the commercial invoice, the packing list, the
 * certificate of origin application, the phytosanitary certificate application (for the national
 * plant protection organisation — in South Africa DALRRD's ePhyto), the PPECB export inspection
 * request and the shipping instruction. The phytosanitary certificate and the certificate of origin
 * themselves are issued by the authorities; we prepare their application data and the trader's own
 * documents.
 *
 * Each document is a PDF filed in Document Management against the farm and the consignment; a
 * change to the consignment regenerates what was already generated, as a new version. Before
 * generating, the data each document needs is checked; and the load does not ship until every
 * required document exists for the consignment as it now stands.
 */
class ExportDocuments
{
    public const KINDS = ['commercial_invoice', 'packing_list', 'certificate_of_origin', 'phytosanitary', 'ppecb_inspection', 'shipping_instruction'];

    /** Issued by an authority: we prepare the application data only. */
    public const APPLICATIONS = ['certificate_of_origin', 'phytosanitary'];

    public const BOTANICAL = ['CITRUS' => 'Citrus spp.', 'GRAPE' => 'Vitis vinifera', 'APPLE' => 'Malus domestica', 'AVOCADO' => 'Persea americana', 'MACADAMIA' => 'Macadamia spp.'];

    public function __construct(private readonly ResidueControls $residues, private readonly FarmDocuments $documents) {}

    // ── which loads ──────────────────────────────────────────────────────────────────────────

    /** The farm a load belongs to (its sales order's farm), or null for a load that is not a farm's. */
    public function farmOf(FulfilmentOrder $load): ?Farm
    {
        if ($load->source !== 'SALES_ORDER') {
            return null;
        }
        $order = FinSalesOrder::withoutGlobalScopes()->find($load->source_id);
        $farmId = $order ? ProduceDispatches::farmIdOf($order) : null;

        return $farmId ? Farm::withoutGlobalScopes()->find($farmId) : null;
    }

    public function isExport(FulfilmentOrder $load): bool
    {
        return $this->farmOf($load) !== null && $this->residues->destinationOf($load) !== ResidueControls::homeCountry();
    }

    public function consignmentFor(FulfilmentOrder $load): ?ExportConsignment
    {
        return ExportConsignment::withoutGlobalScopes()->where('wms_order_id', $load->id)->first();
    }

    // ── capture ──────────────────────────────────────────────────────────────────────────────

    /** The farm's (and its packhouses') export registration codes, by kind. @return array<string, list<string>> */
    public function codes(Farm $farm): array
    {
        return ExportCode::withoutGlobalScopes()->where('farm_id', $farm->id)->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->orderBy('kind')->get()->groupBy('kind')->map(fn ($rows) => $rows->pluck('code')->all())->all();
    }

    public function saveCode(Farm $farm, array $data): ExportCode
    {
        return ExportCode::withoutGlobalScopes()->updateOrCreate(['company_id' => $farm->company_id, 'farm_id' => $farm->id, 'store_id' => $data['store_id'] ?? null,
            'kind' => $data['kind'], 'code' => trim($data['code'])], ['valid_until' => $data['valid_until'] ?? null, 'notes' => $data['notes'] ?? null]);
    }

    /**
     * Open (or change) the export consignment of a load. A new one starts from the load: the customer
     * as consignee, the seal on the pallets, and a line per lot with what the pick put on it.
     */
    public function prepare(FulfilmentOrder $load, array $data, User $actor): ExportConsignment
    {
        $farm = $this->farmOf($load) ?? throw ValidationException::withMessages(['consignment' => 'Only a farm load has an export consignment.']);
        if (! empty($data['destination_country'])) {
            $data['destination_country'] = ResidueControls::country($data['destination_country'])
                ?? throw ValidationException::withMessages(['destination_country' => 'Give the destination as a two-letter country code (NL, GB, CN…).']);
        }
        if (! empty($data['incoterm']) && ! in_array(strtoupper($data['incoterm']), ExportConsignment::INCOTERMS, true)) {
            throw ValidationException::withMessages(['incoterm' => 'That is not an Incoterm.']);
        }
        if (isset($data['required_documents'])) {
            $data['required_documents'] = array_values(array_intersect(self::KINDS, (array) $data['required_documents']));
        }
        $fields = collect($data)->only((new ExportConsignment)->getConnection()->getSchemaBuilder()->getColumnListing('agri_export_consignments'))
            ->except(['id', 'uuid', 'company_id', 'farm_id', 'wms_order_id', 'reference', 'created_by', 'created_at', 'updated_at'])->all();
        if (isset($fields['incoterm'])) {
            $fields['incoterm'] = strtoupper($fields['incoterm']);
        }

        return DB::transaction(function () use ($load, $farm, $fields, $actor) {
            $c = $this->consignmentFor($load);
            if ($c) {
                $c->update($fields);
                $this->regenerateChanged($c->fresh(), $actor);

                return $c->fresh();
            }
            $order = FinSalesOrder::withoutGlobalScopes()->find($load->source_id);
            $party = $load->customer_party_id ? FinParty::withoutGlobalScopes()->find($load->customer_party_id) : null;
            $company = DB::table('companies')->where('id', $farm->company_id)->first();
            $codes = $this->codes($farm);
            $c = ExportConsignment::create($fields + [
                'company_id' => $farm->company_id, 'farm_id' => $farm->id, 'wms_order_id' => $load->id, 'reference' => 'EXP-'.$load->number,
                'exporter_name' => $company->name ?? $farm->name, 'exporter_address' => $farm->address ?: $farm->location, 'exporter_code' => $codes['EXPORTER'][0] ?? null,
                'consignee_name' => $party?->name ?? $load->customer_name, 'consignee_address' => $party?->address ?? ($load->ship_to['address'] ?? $order?->delivery_address),
                'destination_country' => $fields['destination_country'] ?? ResidueControls::country($party?->country),
                'currency' => $order?->currency ?? $farm->currency, 'seal_no' => $load->packages()->pluck('tracking_number')->filter()->implode(', ') ?: null,
                'required_documents' => $fields['required_documents'] ?? self::KINDS, 'created_by' => $actor->id]);
            $this->buildLines($c, $load, $farm);

            return $c->fresh();
        });
    }

    /** A line per lot on the load, with the quantity picked, its pallets and the order's price. */
    private function buildLines(ExportConsignment $c, FulfilmentOrder $load, Farm $farm): void
    {
        $contents = DB::table('wms_package_contents as pc')->join('wms_packages as p', 'p.id', '=', 'pc.package_id')->where('p.order_id', $load->id)
            ->leftJoin('wms_order_lines as ol', 'ol.id', '=', 'pc.order_line_id')
            ->get(['pc.lot_id', 'pc.qty', 'p.sscc', 'p.id as package_id', 'ol.source_line_id']);
        $prices = DB::table('fin_sales_order_lines')->whereIn('id', $contents->pluck('source_line_id')->filter())->pluck('unit_price', 'id');
        $puc = $this->codes($farm)['PUC'][0] ?? null;
        foreach ($contents->groupBy('lot_id') as $stockLot => $rows) {
            $lot = ProduceLot::withoutGlobalScopes()->where('company_id', $farm->company_id)->where('stock_lot_id', $stockLot)->first();
            $qty = (float) $rows->sum('qty');
            $origins = $lot ? $this->residues->origins($lot) : null;
            $plan = $origins && $origins['plan_ids'] ? CropPlan::withoutGlobalScopes()->find($origins['plan_ids'][0]) : null;
            $perCarton = $lot && preg_match('/(\d+(?:[.,]\d+)?)\s*kg/i', (string) $lot->product, $m) ? (float) str_replace(',', '.', $m[1]) : null;
            ExportConsignmentLine::create(['company_id' => $c->company_id, 'farm_id' => $c->farm_id, 'consignment_id' => $c->id, 'produce_lot_id' => $lot?->id,
                'description' => trim(($lot->product ?? 'Produce').' '.($lot->grade ?? '')), 'hs_code' => MrlStarterSet::HS_CODES[$origins['crop_code'] ?? ''] ?? null,
                'pallet_ids' => $rows->pluck('sscc')->filter()->unique()->implode(', ') ?: null, 'pallets' => $rows->pluck('package_id')->unique()->count(),
                'cartons' => $qty, 'unit' => $lot?->unit, 'net_kg' => $perCarton ? round($perCarton * $qty, 2) : null,
                'unit_price' => isset($prices[$rows->first()->source_line_id]) ? (float) $prices[$rows->first()->source_line_id] : null,
                'variety' => $plan?->variety_id ? CropVariety::withoutGlobalScopes()->whereKey($plan->variety_id)->value('name') : null, 'grade' => $lot?->grade, 'puc' => $puc]);
        }
    }

    /** Change the lines (pallets, cartons, masses, HS codes, prices); the pack regenerates. */
    public function updateLines(ExportConsignment $c, array $lines, User $actor): ExportConsignment
    {
        DB::transaction(function () use ($c, $lines, $actor) {
            foreach ($lines as $l) {
                $line = ExportConsignmentLine::withoutGlobalScopes()->where('consignment_id', $c->id)->where('uuid', $l['id'] ?? '')->first()
                    ?? throw ValidationException::withMessages(['lines' => 'That line is not on this consignment.']);
                $line->update(collect($l)->only(['description', 'hs_code', 'pallet_ids', 'pallets', 'cartons', 'net_kg', 'gross_kg', 'unit_price', 'variety', 'grade', 'puc'])->all());
            }
            $this->regenerateChanged($c->fresh(), $actor);
        });

        return $c->fresh();
    }

    // ── the data, and what is missing ────────────────────────────────────────────────────────

    /** Everything the documents are made from. */
    public function data(ExportConsignment $c): array
    {
        $farm = Farm::withoutGlobalScopes()->findOrFail($c->farm_id);
        $load = FulfilmentOrder::withoutGlobalScopes()->find($c->wms_order_id);
        $order = $load ? FinSalesOrder::withoutGlobalScopes()->find($load->source_id) : null;
        $lines = $c->lines()->withoutGlobalScopes()->orderBy('id')->get();
        $lots = ProduceLot::withoutGlobalScopes()->whereIn('id', $lines->pluck('produce_lot_id')->filter())->get()->keyBy('id');
        $codes = $this->codes($farm);

        return [
            'consignment' => collect($c->getAttributes())->except(['id', 'uuid', 'company_id', 'farm_id', 'wms_order_id', 'created_by', 'created_at', 'updated_at', 'required_documents'])
                ->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)->all(),
            'farm' => ['name' => $farm->name, 'code' => $farm->code, 'location' => $farm->location],
            'codes' => $codes,
            'origin_country' => ResidueControls::homeCountry(),
            'load' => $load?->number, 'order' => $order?->number, 'customer_ref' => $order?->customer_ref,
            'lines' => $lines->map(function ($l) use ($lots) {
                $lot = $lots[$l->produce_lot_id] ?? null;
                $crop = $lot ? $this->residues->origins($lot)['crop_code'] : null;

                return ['description' => $l->description, 'hs_code' => $l->hs_code, 'lot' => $lot?->code, 'pallet_ids' => $l->pallet_ids, 'pallets' => $l->pallets,
                    'cartons' => $l->cartons, 'unit' => $l->unit, 'net_kg' => $l->net_kg, 'gross_kg' => $l->gross_kg, 'unit_price' => $l->unit_price,
                    'amount' => $l->unit_price !== null && $l->cartons !== null ? round($l->unit_price * $l->cartons, 2) : null, 'variety' => $l->variety, 'grade' => $l->grade,
                    'puc' => $l->puc, 'botanical' => self::BOTANICAL[$crop ?? ''] ?? null, 'harvested_on' => $lot?->produced_on?->toDateString()];
            })->all(),
        ];
    }

    /** What a document still needs before it can be generated. @return list<string> field labels' keys */
    public function missing(ExportConsignment $c, string $kind, ?array $data = null): array
    {
        $data ??= $this->data($c);
        $x = $data['consignment'];
        $need = ['exporter_name', 'consignee_name', 'consignee_address', 'destination_country'];
        $need = array_merge($need, match ($kind) {
            'commercial_invoice' => ['incoterm', 'currency'],
            'packing_list' => [],
            'certificate_of_origin' => ['exporter_address', 'transport_mode', 'port_of_loading', 'port_of_discharge'],
            'phytosanitary' => ['transport_mode', 'port_of_loading', 'port_of_discharge'],
            'ppecb_inspection' => ['inspection_point', 'inspection_on', 'temperature_setpoint_c'],
            'shipping_instruction' => ['transport_mode', 'incoterm', 'port_of_loading', 'port_of_discharge', 'departure_on', 'notify_party'],
            default => [],
        });
        if (in_array($kind, ['shipping_instruction', 'ppecb_inspection'], true) && ($x['transport_mode'] ?? null) === 'sea') {
            $need = array_merge($need, ['container_no', 'seal_no'], $kind === 'shipping_instruction' ? ['vessel', 'voyage', 'booking_ref'] : []);
        }
        if ($kind === 'shipping_instruction' && ($x['transport_mode'] ?? null) === 'air') {
            $need = array_merge($need, ['awb_no', 'flight_no']);
        }
        $missing = array_values(array_filter($need, fn ($f) => blank($x[$f] ?? null)));
        if (! $data['lines']) {
            $missing[] = 'lines';
        }
        $lineNeeds = match ($kind) {
            'commercial_invoice' => ['hs_code', 'unit_price', 'cartons'],
            'packing_list' => ['pallets', 'cartons', 'net_kg', 'gross_kg'],
            'certificate_of_origin' => ['hs_code', 'cartons', 'gross_kg'],
            'phytosanitary' => ['puc', 'cartons', 'net_kg'],
            'ppecb_inspection' => ['puc', 'pallet_ids', 'cartons'],
            'shipping_instruction' => ['pallets', 'cartons', 'gross_kg'],
            default => [],
        };
        foreach ($lineNeeds as $f) {
            if (collect($data['lines'])->contains(fn ($l) => blank($l[$f] ?? null))) {
                $missing[] = "line.{$f}";
            }
        }
        if (in_array($kind, ['phytosanitary', 'ppecb_inspection'], true) && empty($data['codes']['PHC'])) {
            $missing[] = 'code.PHC';
        }

        return array_values(array_unique($missing));
    }

    private function hash(string $kind, array $data): string
    {
        return hash('sha256', $kind.'|'.json_encode($data));
    }

    // ── generating ───────────────────────────────────────────────────────────────────────────

    /**
     * Generate one document (or the whole required pack) as a PDF in Document Management, linked to
     * the farm and the consignment. Already generated: a new version of the same document.
     *
     * @return list<ExportDocument>
     */
    public function generate(ExportConsignment $c, ?string $kind, User $actor): array
    {
        $kinds = $kind ? [$kind] : ($c->required_documents ?: self::KINDS);
        if ($kind && ! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'That is not an export document.']);
        }
        $data = $this->data($c);
        $errors = [];
        foreach ($kinds as $k) {
            if ($m = $this->missing($c, $k, $data)) {
                $errors[] = UiLabels::get("agri.exports.kinds.{$k}").': '.collect($m)->map(fn ($f) => UiLabels::get('agri.exports.fields.'.str_replace(['line.', 'code.'], ['line_', 'code_'], $f)))->implode(', ');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['documents' => 'Complete the consignment first. '.implode('. ', $errors).'.']);
        }

        return DB::transaction(fn () => array_map(fn ($k) => $this->file($c, $k, $data, $actor), $kinds));
    }

    private function file(ExportConsignment $c, string $kind, array $data, User $actor): ExportDocument
    {
        $farm = Farm::withoutGlobalScopes()->findOrFail($c->farm_id);
        $current = ExportDocument::withoutGlobalScopes()->where('consignment_id', $c->id)->where('kind', $kind)->where('status', 'current')->first();
        $version = (int) ExportDocument::withoutGlobalScopes()->where('consignment_id', $c->id)->where('kind', $kind)->max('version') + 1;
        $title = UiLabels::get("agri.exports.kinds.{$kind}")." — {$c->reference}";
        $pdf = Pdf::loadHTML($this->html($kind, $data, $c, $version))->setPaper('a4')->output();
        $file = ['original_name' => str_replace('_', '-', $kind)."-{$c->reference}.pdf", 'mime' => 'application/pdf', 'data' => base64_encode($pdf)];
        $existing = $current ? Document::withoutGlobalScopes()->find($current->document_id) : null;
        $doc = $existing
            ? $this->documents->file($farm, ['change_note' => "Regenerated: version {$version}"], $file, $actor, $existing)
            : $this->documents->file($farm, ['document_type' => 'export_document', 'title' => $title, 'reference' => $c->reference, 'issued_on' => today()->toDateString(),
                'description' => "Export document for consignment {$c->reference} ({$farm->name})", 'subject' => $c], $file, $actor);
        $current?->update(['status' => 'superseded']);

        return ExportDocument::create(['company_id' => $c->company_id, 'farm_id' => $c->farm_id, 'consignment_id' => $c->id, 'kind' => $kind, 'version' => $version,
            'document_id' => $doc->id, 'data_hash' => $this->hash($kind, $data), 'status' => 'current', 'generated_by' => $actor->id, 'generated_at' => now()]);
    }

    /** After a change: every document already generated whose data changed is regenerated (when it still can be). */
    public function regenerateChanged(ExportConsignment $c, User $actor): int
    {
        $data = $this->data($c);
        $n = 0;
        foreach (ExportDocument::withoutGlobalScopes()->where('consignment_id', $c->id)->where('status', 'current')->get() as $d) {
            if ($d->data_hash !== $this->hash($d->kind, $data) && ! $this->missing($c, $d->kind, $data)) {
                $this->file($c, $d->kind, $data, $actor);
                $n++;
            }
        }

        return $n;
    }

    /** Per document: required, current version, out of date, what is missing, and the version history. */
    public function packStatus(ExportConsignment $c): array
    {
        $data = $this->data($c);
        $docs = ExportDocument::withoutGlobalScopes()->where('consignment_id', $c->id)->orderByDesc('version')->get()->groupBy('kind');
        $dm = Document::withoutGlobalScopes()->whereIn('id', $docs->flatten()->pluck('document_id')->unique())->get(['id', 'uuid', 'approval_status'])->keyBy('id');

        return collect(self::KINDS)->map(function ($k) use ($c, $data, $docs, $dm) {
            $rows = $docs[$k] ?? collect();
            $cur = $rows->firstWhere('status', 'current');

            return ['kind' => $k, 'required' => in_array($k, $c->required_documents ?? [], true), 'application' => in_array($k, self::APPLICATIONS, true),
                'version' => $cur?->version, 'stale' => $cur && $cur->data_hash !== $this->hash($k, $data), 'missing' => $this->missing($c, $k, $data),
                'url' => $cur && isset($dm[$cur->document_id]) ? FarmDocuments::url($dm[$cur->document_id]) : null,
                'approval' => $cur ? ($dm[$cur->document_id] ?? null)?->approval_status : null,
                'history' => $rows->map(fn ($r) => ['version' => $r->version, 'status' => $r->status, 'at' => $r->generated_at?->format('Y-m-d H:i')])->values()->all()];
        })->all();
    }

    /** Why an export load may not ship yet, or null (registered as a dispatch guard). */
    public function dispatchRefusal(FulfilmentOrder $load): ?string
    {
        if (! $this->isExport($load)) {
            return null;
        }
        $c = $this->consignmentFor($load);
        if (! $c) {
            return "{$load->number} is an export load: prepare its export consignment and generate the document pack before it ships.";
        }
        $gaps = collect($this->packStatus($c))->filter(fn ($d) => $d['required'] && (! $d['version'] || $d['stale']))
            ->map(fn ($d) => UiLabels::get("agri.exports.kinds.{$d['kind']}").(! $d['version'] ? '' : ' (out of date)'));

        return $gaps->isEmpty() ? null : "The export pack of {$c->reference} is not complete: ".$gaps->implode(', ').'. Generate it before the load ships.';
    }

    // ── the documents ────────────────────────────────────────────────────────────────────────

    /** The HTML a document of the pack is printed from (what the PDF shows), for the current data. */
    public function documentHtml(ExportConsignment $c, string $kind): string
    {
        $version = (int) ExportDocument::withoutGlobalScopes()->where('consignment_id', $c->id)->where('kind', $kind)->max('version');

        return $this->html($kind, $this->data($c), $c, max(1, $version));
    }

    private function html(string $kind, array $d, ExportConsignment $c, int $version): string
    {
        $L = fn (string $k, array $p = []) => e(UiLabels::get("agri.exports.pdf.{$k}", $p));
        $F = fn (string $k) => e(UiLabels::get("agri.exports.fields.{$k}"));
        $x = $d['consignment'];
        $v = fn ($k) => e((string) ($x[$k] ?? '—'));
        $codes = collect($d['codes'])->map(fn ($list, $k) => e($k).': '.e(implode(', ', $list)))->implode(' · ');
        $kv = function (array $keys) use ($F, $v) {
            return '<table class="kv">'.collect($keys)->chunk(2)->map(fn ($pair) => '<tr>'.$pair->map(fn ($k) => '<th>'.$F($k).'</th><td>'.$v($k).'</td>')->implode('').'</tr>')->implode('').'</table>';
        };
        $out = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:9.5px;color:#222}h1{font-size:16px;margin:0 0 2px}h2{font-size:11px;margin:12px 0 4px}'
            .'table{width:100%;border-collapse:collapse;margin-bottom:6px}td,th{border:1px solid #bbb;padding:3px;text-align:left;vertical-align:top}th{background:#f0f0f0}'
            .'.kv th{width:18%}.note{border:1px solid #ef6c00;background:#fff3e0;padding:5px;margin:6px 0}.muted{color:#666}.r{text-align:right}</style></head><body>';
        $brand = \App\Modules\Agri\Support\OutputBranding::for((int) $c->company_id);
        $out .= \App\Modules\Agri\Support\OutputBranding::pdfHeader($brand);
        $out .= '<h1>'.e(UiLabels::get("agri.exports.kinds.{$kind}")).'</h1><div class="muted">'.$L('header', ['ref' => $c->reference, 'version' => $version, 'date' => today()->toDateString(),
            'load' => $d['load'] ?? '—', 'order' => $d['order'] ?? '—']).'</div>';
        if (in_array($kind, self::APPLICATIONS, true)) {
            $out .= '<div class="note">'.$L($kind === 'phytosanitary' ? 'phyto_note' : 'coo_note').'</div>';
        }
        $out .= '<h2>'.$L('parties').'</h2>'.$kv(['exporter_name', 'exporter_address', 'exporter_code', 'consignee_name', 'consignee_address', 'destination_country', 'notify_party']);
        $out .= '<div class="muted">'.$L('codes').': '.($codes ?: '—').' · '.$L('origin', ['country' => $d['origin_country'], 'farm' => e($d['farm']['name'])]).'</div>';
        $out .= '<h2>'.$L('transport').'</h2>'.$kv(match ($kind) {
            'ppecb_inspection' => ['transport_mode', 'container_no', 'seal_no', 'inspection_point', 'inspection_on', 'temperature_setpoint_c', 'temperature_min_c', 'temperature_max_c',
                'cold_treatment', 'cold_treatment_protocol', 'ventilation', 'port_of_loading'],
            'commercial_invoice' => ['incoterm', 'incoterm_place', 'currency', 'transport_mode', 'port_of_loading', 'port_of_discharge'],
            'phytosanitary' => ['transport_mode', 'vessel', 'awb_no', 'port_of_loading', 'port_of_discharge', 'final_destination', 'import_permit_no', 'cold_treatment', 'cold_treatment_protocol', 'additional_declarations'],
            default => ['transport_mode', 'carrier', 'booking_ref', 'container_no', 'seal_no', 'vessel', 'voyage', 'awb_no', 'flight_no', 'port_of_loading', 'port_of_discharge',
                'final_destination', 'departure_on', 'arrival_on', 'incoterm', 'temperature_setpoint_c'],
        });
        $cols = match ($kind) {
            'commercial_invoice' => ['description', 'hs_code', 'lot', 'cartons', 'unit_price', 'amount'],
            'packing_list' => ['lot', 'description', 'pallet_ids', 'pallets', 'cartons', 'net_kg', 'gross_kg'],
            'certificate_of_origin' => ['description', 'hs_code', 'cartons', 'gross_kg'],
            'phytosanitary' => ['botanical', 'description', 'variety', 'puc', 'lot', 'cartons', 'net_kg'],
            'ppecb_inspection' => ['lot', 'description', 'grade', 'puc', 'pallet_ids', 'pallets', 'cartons', 'harvested_on'],
            default => ['description', 'pallets', 'cartons', 'net_kg', 'gross_kg'],
        };
        $num = ['cartons', 'pallets', 'net_kg', 'gross_kg', 'unit_price', 'amount'];
        $out .= '<h2>'.$L('goods').'</h2><table><tr>'.collect($cols)->map(fn ($k) => '<th>'.$F("line_{$k}").'</th>')->implode('').'</tr>';
        foreach ($d['lines'] as $l) {
            $out .= '<tr>'.collect($cols)->map(fn ($k) => '<td'.(in_array($k, $num, true) ? ' class="r"' : '').'>'.e((string) ($l[$k] ?? '—')).'</td>')->implode('').'</tr>';
        }
        $sum = fn ($k) => collect($d['lines'])->sum(fn ($l) => (float) ($l[$k] ?? 0));
        $out .= '<tr>'.collect($cols)->map(fn ($k, $i) => '<th'.(in_array($k, $num, true) ? ' class="r"' : '').'>'.($i === 0 ? $L('total') : (in_array($k, ['cartons', 'pallets', 'net_kg', 'gross_kg', 'amount'], true) ? e(number_format($sum($k), 2)) : '')).'</th>')->implode('').'</tr></table>';
        if ($kind === 'commercial_invoice') {
            $out .= '<div>'.$L('invoice_total', ['currency' => $v('currency'), 'amount' => number_format($sum('amount'), 2), 'incoterm' => $v('incoterm')]).'</div>';
        }
        $out .= '<p class="muted">'.$L('footer', ['brand' => \App\Modules\Agri\Support\OutputBranding::credit($brand)]).'</p></body></html>';

        return $out;
    }
}
