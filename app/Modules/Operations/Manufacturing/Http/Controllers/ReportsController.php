<?php

namespace App\Modules\Operations\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Manufacturing\Models\Factory;
use App\Modules\Operations\Manufacturing\Models\Item;
use App\Modules\Operations\Manufacturing\Models\ProductionLine;
use App\Modules\Operations\Manufacturing\Models\ProductionOrder;
use App\Modules\Operations\Manufacturing\Services\Dashboards;
use App\Modules\Operations\Manufacturing\Support\ManufacturingReport;
use App\Modules\Operations\Manufacturing\Support\RoleCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Management dashboards and their exports (MFG-018). One action per dashboard, each authorised by
 * its own ability; an export is the same figures as the screen, under the same authorisation, and
 * is recorded in the activity log.
 */
class ReportsController extends Controller
{
    use \App\Modules\Operations\Manufacturing\Concerns\ScopesValidationToTenant;

    public function __construct(private readonly Dashboards $dashboards) {}

    public function executive(Request $request): Response|StreamedResponse
    {
        $this->authorize('executive', ManufacturingReport::class);

        return $this->respond($request, 'executive', fn ($f) => $this->dashboards->executive($f));
    }

    public function planning(Request $request): Response|StreamedResponse
    {
        $this->authorize('planning', ManufacturingReport::class);

        return $this->respond($request, 'planning', fn ($f) => $this->dashboards->planning($f));
    }

    public function inventory(Request $request): Response|StreamedResponse
    {
        $this->authorize('inventory', ManufacturingReport::class);

        return $this->respond($request, 'inventory', fn ($f) => $this->dashboards->inventory($f));
    }

    public function costs(Request $request): Response|StreamedResponse
    {
        $this->authorize('costs', ManufacturingReport::class);

        return $this->respond($request, 'costs', fn ($f) => $this->dashboards->costs($f));
    }

    public function quality(Request $request): Response|StreamedResponse
    {
        $this->authorize('quality', ManufacturingReport::class);
        $user = $request->user();
        $sections = ['quality' => $user->can(RoleCatalog::VIEW_QUALITY), 'maintenance' => $user->can(RoleCatalog::VIEW_MAINTENANCE)];

        return $this->respond($request, 'quality', fn ($f) => $this->dashboards->qualityMaintenance($f, $sections));
    }

    private function respond(Request $request, string $key, callable $build): Response|StreamedResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'factory_id' => ['nullable', 'integer', $this->tenantExists('mfg_factories')],
            'item_id' => ['nullable', 'integer', $this->tenantExists('mfg_items')],
            'line_id' => ['nullable', 'integer', $this->tenantExists('mfg_production_lines')],
            'status' => ['nullable', Rule::in(ProductionOrder::STATUSES)],
            'export' => ['nullable', Rule::in(['csv'])],
        ]);
        $filters = [
            'from' => Carbon::parse($v['from'] ?? now()->subDays(29)->toDateString())->startOfDay(),
            'to' => Carbon::parse($v['to'] ?? now()->toDateString())->endOfDay(),
            'factory_id' => isset($v['factory_id']) ? (int) $v['factory_id'] : null, 'item_id' => isset($v['item_id']) ? (int) $v['item_id'] : null,
            'line_id' => isset($v['line_id']) ? (int) $v['line_id'] : null, 'status' => $v['status'] ?? null,
            // A consolidated view across factories only for somebody confined to none (MFG-024).
            'consolidated' => app(\App\Modules\Operations\Manufacturing\Services\FactoryAccess::class)->restrictionFor($request->user()) === null,
        ];
        $data = $build($filters);

        if (($v['export'] ?? null) === 'csv') {
            activity()->causedBy($request->user())->withProperties(['dashboard' => $key,
                'filters' => array_merge($filters, ['from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString()])])
                ->log('manufacturing.dashboard.exported');
            $rows = $this->dashboards->flatten($data);

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                foreach ($rows as $r) {
                    // A cell starting = + - @ would be run as a formula by a spreadsheet.
                    fputcsv($out, array_map(fn ($c) => is_string($c) ? preg_replace('/^([=+\-@])/', "'$1", $c) : $c, $r));
                }
                fclose($out);
            }, "manufacturing-{$key}-{$filters['from']->toDateString()}-{$filters['to']->toDateString()}.csv", ['Content-Type' => 'text/csv']);
        }

        $user = $request->user();

        return Inertia::render('Modules/Manufacturing/Reports/Dashboard', [
            'dashboard' => $key,
            'data' => $data,
            'filters' => ['from' => $filters['from']->toDateString(), 'to' => $filters['to']->toDateString(), 'factory_id' => $filters['factory_id'],
                'item_id' => $filters['item_id'], 'line_id' => $filters['line_id'], 'status' => $filters['status']],
            'options' => [
                'factories' => Factory::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($x) => ['id' => $x->id, 'name' => "{$x->code} — {$x->name}"])->values(),
                'products' => Item::query()->whereIn('kind', Item::MANUFACTURED_KINDS)->where('is_active', true)->with('stockItem:id,code,name')->limit(2000)->get()
                    ->map(fn ($x) => ['id' => $x->id, 'name' => trim(($x->stockItem?->code ?? '').' — '.($x->stockItem?->name ?? ''))])->sortBy('name')->values(),
                'lines' => ProductionLine::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name'])->map(fn ($x) => ['id' => $x->id, 'name' => "{$x->code} — {$x->name}"])->values(),
                'statuses' => ProductionOrder::STATUSES,
            ],
            'available' => collect(ManufacturingReport::DASHBOARDS)->filter(fn ($d) => $user->can($d, ManufacturingReport::class))->values(),
            'currency' => config('finance.base_currency', 'ZAR'),
        ]);
    }
}
