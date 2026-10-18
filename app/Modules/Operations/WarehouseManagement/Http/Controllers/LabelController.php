<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockBin;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Concerns\RefusesPolitely;
use App\Modules\Operations\WarehouseManagement\Models\Package;
use App\Modules\Operations\WarehouseManagement\Models\Shipment;
use App\Modules\Operations\WarehouseManagement\Services\LabelPrinting;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use App\Modules\Tenancy\Services\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** WMS-152: label templates, printing to the browser or as ZPL, and network label printers. */
class LabelController extends Controller
{
    use RefusesPolitely;

    public function index(Request $request, LabelPrinting $labels): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Labels/Setup', [
            'templates' => collect(LabelPrinting::KINDS)->map(fn ($k) => ['kind' => $k, 'fields_available' => LabelPrinting::allFields($k)] + $labels->template($k)
                + ['uuid' => DB::table('wms_label_templates')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('kind', $k)->orderByDesc('is_default')->value('uuid')])->values(),
            'printers' => DB::table('wms_printers as p')->leftJoin('fin_warehouses as w', 'w.id', '=', 'p.warehouse_id')->where('p.company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->orderBy('p.name')
                ->get(['p.uuid', 'p.name', 'p.host', 'p.port', 'p.dpi', 'p.is_active', 'w.code as warehouse']),
            'warehouses' => FinWarehouse::query()->where('is_active', true)->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['id' => $w->id, 'title' => $w->code]),
            'can' => ['manage' => $request->user()->can(RoleCatalog::MANAGE_WAREHOUSES)],
        ]);
    }

    public function saveTemplate(Request $request, LabelPrinting $labels): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_WAREHOUSES), 403);
        $data = $request->validate(['uuid' => ['nullable', 'uuid'], 'kind' => ['required', Rule::in(LabelPrinting::KINDS)], 'name' => ['required', 'string', 'max:120'],
            'width_mm' => ['required', 'numeric', 'between:20,300'], 'height_mm' => ['required', 'numeric', 'between:10,300'],
            'symbology' => ['required', Rule::in(['CODE128', 'GS1_128', 'QR'])], 'fields' => ['required', 'array', 'min:1'], 'fields.*' => ['string', 'max:32']]);

        return $this->attempt(fn () => $labels->saveTemplate($data, (int) (app(CurrentCompany::class)->id() ?? $request->user()->company_id), $data['uuid'] ?? null), 'Template saved.');
    }

    public function savePrinter(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can(RoleCatalog::MANAGE_WAREHOUSES), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'host' => ['required', 'ip'], 'port' => ['required', 'integer', 'between:1,65535'],
            'dpi' => ['required', Rule::in([203, 300, 600])], 'warehouse_id' => ['nullable', 'integer']]);
        if (! LabelPrinting::isPrivateAddress($data['host'])) {
            return back()->withErrors(['host' => 'Use the printer\'s address on the warehouse network (a private address).']);
        }
        if (! empty($data['warehouse_id'])) {
            FinWarehouse::query()->findOrFail($data['warehouse_id']);
        }
        DB::table('wms_printers')->insert($data + ['uuid' => (string) Str::uuid(), 'company_id' => app(CurrentCompany::class)->id() ?? $request->user()->company_id,
            'language' => 'ZPL', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Printer added.');
    }

    /** Print a set of labels: in the browser, as a ZPL file, or straight to a network printer. */
    public function print(Request $request, LabelPrinting $labels)
    {
        $this->authorize('viewAny', FinStockItem::class);
        $data = $request->validate(['kind' => ['required', Rule::in(LabelPrinting::KINDS)], 'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['string'],
            'output' => ['required', Rule::in(['BROWSER', 'ZPL', 'PRINTER'])], 'printer' => ['required_if:output,PRINTER', 'nullable', 'uuid']]);
        $subjects = match ($data['kind']) {
            'PRODUCT' => FinStockItem::query()->whereIn('uuid', $data['ids'])->get(),
            'BIN' => FinStockBin::query()->whereIn('uuid', $data['ids'])->with('warehouse:id,code')->get(),
            'DISPATCH' => Shipment::query()->whereIn('uuid', $data['ids'])->with('order')->get(),
            default => Package::query()->whereIn('uuid', $data['ids'])->with('order')->get(),
        };
        $set = $labels->labels($data['kind'], $subjects);
        if ($data['output'] === 'BROWSER') {
            // On a sheet printer the value travels as a QR code; true 1D barcodes come from label printers (ZPL).
            $writer = new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(new \BaconQrCode\Renderer\RendererStyle\RendererStyle(160, 1), new \BaconQrCode\Renderer\Image\SvgImageBackEnd()));

            return Inertia::render('Modules/Warehouse/Labels/Print', ['template' => $labels->template($data['kind']), 'kind' => $data['kind'],
                'labels' => array_map(fn ($l) => $l + ['qr' => $writer->writeString($l['barcode'])], $set)]);
        }
        $dpi = $data['output'] === 'PRINTER' ? (int) DB::table('wms_printers')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $data['printer'])->value('dpi') : 203;
        $zpl = $labels->zpl($data['kind'], $set, $dpi ?: 203);
        if ($data['output'] === 'ZPL') {
            return response($zpl, 200, ['Content-Type' => 'text/plain', 'Content-Disposition' => 'attachment; filename="labels-'.strtolower($data['kind']).'.zpl"']);
        }

        return $this->attempt(fn () => $labels->send($data['printer'], $zpl), count($set).' label(s) sent to the printer.');
    }
}
