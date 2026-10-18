<?php

namespace App\Modules\Operations\WarehouseManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Accounting\Models\FinStockItem;
use App\Modules\Finance\Accounting\Models\FinWarehouse;
use App\Modules\Operations\WarehouseManagement\Jobs\GenerateReport;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseDashboards;
use App\Modules\Operations\WarehouseManagement\Services\WarehouseReports;
use App\Modules\Operations\WarehouseManagement\Support\RoleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** WMS-181..185: the inventory, supplier, profit-protection and executive dashboards, and report exports. */
class InsightController extends Controller
{
    public const DASHBOARDS = ['inventory', 'suppliers', 'protection', 'executive'];

    public function dashboard(Request $request, string $kind, WarehouseDashboards $dashboards): Response
    {
        $this->authorize('viewAny', FinStockItem::class);
        abort_unless(in_array($kind, self::DASHBOARDS, true), 404);
        $user = $request->user();
        // Profit protection is for investigators; the executive view is about money.
        abort_if($kind === 'protection' && ! $user->can(RoleCatalog::INVESTIGATE), 403);
        abort_if($kind === 'executive' && ! $user->can(RoleCatalog::ACCOUNTING), 403);
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'warehouse_id' => ['nullable', 'integer'], 'item' => ['nullable', 'string', 'max:60'],
            'supplier_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:20']]);
        if (! empty($f['warehouse_id'])) {
            FinWarehouse::query()->findOrFail($f['warehouse_id']);
        }
        $from = Carbon::parse($f['from'] ?? now()->subDays(30)->toDateString())->startOfDay();
        $to = Carbon::parse($f['to'] ?? now()->toDateString())->endOfDay();
        $values = $user->can(RoleCatalog::ACCOUNTING);
        $visible = app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAccess::class)->visibleIds($user);
        abort_if(! empty($f['warehouse_id']) && $visible !== null && ! in_array((int) $f['warehouse_id'], $visible, true), 403);
        $f['warehouse_ids'] = $visible;

        return Inertia::render('Modules/Warehouse/Insights/Dashboard', [
            'kind' => $kind,
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()] + array_intersect_key($f, array_flip(['warehouse_id', 'item', 'supplier_id', 'status'])),
            'data' => match ($kind) {
                'inventory' => $dashboards->inventory($f, $values),
                'suppliers' => $dashboards->suppliers($from, $to, isset($f['supplier_id']) ? (int) $f['supplier_id'] : null, $values),
                'protection' => $dashboards->protection($from, $to, $values),
                'executive' => $dashboards->executive($from, $to),
            },
            'generated_at' => now()->toIso8601String(),
            'warehouses' => FinWarehouse::query()->where('is_wms_managed', true)->when($visible !== null, fn ($q) => $q->whereIn('id', $visible))
                ->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['value' => $w->id, 'title' => $w->code]),
            'available' => collect(self::DASHBOARDS)->filter(fn ($k) => match ($k) { 'protection' => $user->can(RoleCatalog::INVESTIGATE),
                'executive' => $user->can(RoleCatalog::ACCOUNTING), default => true })->values(),
            'with_values' => $values,
        ]);
    }

    public function reports(Request $request, WarehouseReports $reports): Response
    {
        $this->authorize('viewAny', FinStockItem::class);

        return Inertia::render('Modules/Warehouse/Insights/Reports', [
            'catalogue' => $reports->catalogue($request->user()),
            'runs' => DB::table('wms_report_runs')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('requested_by', $request->user()->id)->orderByDesc('id')->limit(50)->get()
                ->map(fn ($r) => ['id' => $r->uuid, 'title' => $r->title, 'format' => $r->format, 'status' => $r->status, 'rows' => $r->rows,
                    'parameters' => $reports->labelParameters(json_decode($r->parameters, true)), 'sensitive' => (bool) $r->sensitive,
                    'requested_at' => Carbon::parse($r->created_at)->toIso8601String(), 'generated_at' => $r->generated_at ? Carbon::parse($r->generated_at)->toIso8601String() : null,
                    'failure' => $r->failure_reason, 'downloads' => (int) $r->downloads]),
            'warehouses' => FinWarehouse::query()->where('is_wms_managed', true)->orderBy('code')->get(['id', 'code'])->map(fn ($w) => ['value' => $w->id, 'title' => $w->code]),
            'suppliers' => DB::table('suppliers')->when(app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id(), fn ($q, $c) => $q->where('company_id', $c))
                ->orderBy('company_name')->limit(500)->get(['id', 'company_name'])->map(fn ($s) => ['value' => $s->id, 'title' => $s->company_name]),
            'sync_rows' => WarehouseReports::SYNC_ROWS,
        ]);
    }

    /** Run a report: small ones are built now and downloaded; large ones go to the background. */
    public function run(Request $request, WarehouseReports $reports): HttpResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $d = $request->validate(['report' => ['required', Rule::in(array_keys(WarehouseReports::REPORTS))], 'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'warehouse_id' => ['nullable', 'integer'], 'item' => ['nullable', 'string', 'max:60'],
            'supplier_id' => ['nullable', 'integer'], 'status' => ['nullable', 'string', 'max:20'], 'background' => ['boolean']]);
        if (! empty($d['warehouse_id'])) {
            FinWarehouse::query()->findOrFail($d['warehouse_id']);
        }
        if (! empty($d['supplier_id'])) {
            abort_unless(DB::table('suppliers')->where('id', $d['supplier_id'])->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->exists(), 422);
        }
        if (! $request->user()->can(WarehouseReports::REPORTS[$d['report']][1])) {
            // A refused export of something sensitive is itself worth knowing about (QA-023).
            app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit::class)->record((int) app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id(), 'export.denied', 'REPORT', $d['report'],
                'Export refused: '.WarehouseReports::REPORTS[$d['report']][0]);
            abort(403, 'You may not run this report.');
        }
        $run = $reports->request($d['report'], $d, $d['format'], $request->user());
        if (($d['background'] ?? false) || $reports->isLarge($run)) {
            GenerateReport::dispatch($run->id);

            return redirect()->route('warehouse.reports.index')->with('success', "{$run->title} is being built. You will be notified when it is ready.");
        }
        $run = $reports->generate($run);

        return $this->deliver($request, $run);
    }

    public function download(Request $request, string $uuid, WarehouseReports $reports): HttpResponse
    {
        $this->authorize('viewAny', FinStockItem::class);
        $run = DB::table('wms_report_runs')->where('company_id', app(\App\Modules\Tenancy\Services\CurrentCompany::class)->id())->where('uuid', $uuid)->firstOrFail();
        // A run is its requester's; the permission is checked again, since it may have been taken away.
        abort_unless((int) $run->requested_by === (int) $request->user()->id, 404);
        $reports->assertMay($run->report, $request->user());
        abort_if($run->with_values && ! $request->user()->can(RoleCatalog::ACCOUNTING), 403);
        abort_unless($run->status === 'COMPLETE' && $run->file_path, 409, 'This report is not ready.');

        return $this->deliver($request, $run);
    }

    private function deliver(Request $request, object $run): HttpResponse
    {
        DB::table('wms_report_runs')->where('id', $run->id)->increment('downloads');
        if ($run->sensitive) {
            app(\App\Modules\Operations\WarehouseManagement\Services\WarehouseAudit::class)->record((int) $run->company_id, 'export.downloaded', 'REPORT', $run->uuid, "Downloaded {$run->title} ({$run->format}, {$run->rows} rows)",
                null, ['parameters' => json_decode($run->parameters, true)]);
            activity('warehouse')->causedBy($request->user())->event('downloaded')
                ->withProperties(['report' => $run->report, 'run' => $run->uuid, 'format' => $run->format, 'rows' => $run->rows,
                    'parameters' => json_decode($run->parameters, true), 'ip' => $request->ip()])
                ->log("Downloaded {$run->title}");
        }
        $ext = strtolower($run->format);
        $name = str($run->title)->slug().'-'.Carbon::parse($run->generated_at)->format('Ymd-His').".{$ext}";
        $type = ['csv' => 'text/csv', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'][$ext];

        return response(Storage::disk('local')->get($run->file_path), 200, ['Content-Type' => $type, 'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }
}
